<?php
declare(strict_types=1);

namespace TeamDark\Panel;

require_once __DIR__.'/Auth.php';

use PDOException;
use RuntimeException;
use Throwable;

final class TelegramService
{
    public static function upsertTelegramUser(array $from): array
    {
        $chatId = self::chatId((string)($from['id'] ?? ''));
        $first = self::clean((string)($from['first_name'] ?? ''), 100);
        $last = self::clean((string)($from['last_name'] ?? ''), 100);
        $username = self::clean((string)($from['username'] ?? ''), 64);
        $language = self::clean((string)($from['language_code'] ?? ''), 16);
        $pdo = Database::pdo();
        $pdo->prepare("INSERT INTO telegram_users(chat_id,first_name,last_name,username,language_code,first_seen_at,last_seen_at) VALUES(?,?,?,?,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE first_name=VALUES(first_name),last_name=VALUES(last_name),username=VALUES(username),language_code=VALUES(language_code),last_seen_at=NOW()")
            ->execute([$chatId,$first,$last,$username,$language]);
        $q=$pdo->prepare("SELECT * FROM telegram_users WHERE chat_id=? LIMIT 1");
        $q->execute([$chatId]);
        $row=$q->fetch();
        if(!$row) throw new RuntimeException('Could not load Telegram user.');
        return $row;
    }

    public static function linkInfo(array $panelUser): ?array
    {
        $q=Database::pdo()->prepare("SELECT tg.* FROM telegram_users tg WHERE tg.linked_user_id=? LIMIT 1");
        $q->execute([$panelUser['id']]);
        return $q->fetch() ?: null;
    }

    public static function createLinkChallenge(array $panelUser,string $rawChatId): array
    {
        $userId=(int)($panelUser['id'] ?? 0);
        if($userId<=0) throw new RuntimeException('Invalid account.');
        if(!Auth::recentlyAuthenticated(300)) throw new RuntimeException('Fresh sign-in required before changing Telegram security.');

        $pdo=Database::pdo();
        $fresh=$pdo->prepare("SELECT telegram_chat_id,telegram_2fa_enabled,status FROM users WHERE id=? LIMIT 1");
        $fresh->execute([$userId]);
        $state=$fresh->fetch();
        if(!$state || $state['status']!=='active') throw new RuntimeException('Account is not active.');
        if((int)$state['telegram_2fa_enabled']===1) throw new RuntimeException('Disable Telegram 2FA before changing the linked Telegram account.');
        if(!empty($state['telegram_chat_id']) || self::linkInfo(['id'=>$userId])) throw new RuntimeException('Telegram is already linked. Unlink it securely before linking another account.');

        $chatId=self::chatId($rawChatId);
        Security::rateLimit('telegram-link-start-user',5,3600,(string)$userId);
        Security::rateLimit('telegram-link-start-chat',5,3600,(string)$chatId);
        $q=$pdo->prepare("SELECT id FROM users WHERE telegram_chat_id=? AND id<>? LIMIT 1");
        $q->execute([$chatId,$userId]);
        if($q->fetch()) throw new RuntimeException('That Telegram Chat ID is already linked to another panel account.');

        $pdo->prepare("DELETE FROM telegram_link_tokens WHERE user_id=? OR expires_at<=NOW() OR used_at IS NOT NULL")->execute([$userId]);
        for($attempt=0;$attempt<8;$attempt++){
            $code='TDLINK-'.strtoupper(bin2hex(random_bytes(8)));
            $hash=Crypto::fingerprint('telegram-link|'.$userId.'|'.$chatId.'|'.$code);
            try{
                $pdo->prepare("INSERT INTO telegram_link_tokens(user_id,chat_id,code_hash,expires_at) VALUES(?,?,?,DATE_ADD(NOW(), INTERVAL 10 MINUTE))")
                    ->execute([$userId,$chatId,$hash]);
                return ['code'=>$code,'chat_id'=>$chatId,'expires_at'=>date('Y-m-d H:i:s',time()+600)];
            }catch(PDOException $e){ if($e->getCode()!=='23000') throw $e; }
        }
        throw new RuntimeException('Could not create Telegram verification code.');
    }

    public static function confirmLink(int $chatId,string $code,int $telegramUserId): array
    {
        $code=strtoupper(trim($code));
        if(!preg_match('/^TDLINK-[A-F0-9]{16}$/',$code)) throw new RuntimeException('Invalid verification code.');
        Security::rateLimit('telegram-link-confirm-chat',8,900,(string)$chatId);
        $pdo=Database::pdo();
        $pdo->beginTransaction();
        try{
            $candidates=$pdo->prepare("SELECT t.*,u.username,u.status,u.telegram_chat_id,u.telegram_2fa_enabled FROM telegram_link_tokens t JOIN users u ON u.id=t.user_id WHERE t.chat_id=? AND t.used_at IS NULL AND t.expires_at>NOW() ORDER BY t.id DESC LIMIT 8 FOR UPDATE");
            $candidates->execute([$chatId]);
            $token=null;
            foreach($candidates->fetchAll() ?: [] as $candidate){
                $expected=Crypto::fingerprint('telegram-link|'.(int)$candidate['user_id'].'|'.$chatId.'|'.$code);
                if(hash_equals((string)$candidate['code_hash'],$expected)){ $token=$candidate; break; }
            }
            if(!$token || $token['status']!=='active') throw new RuntimeException('Verification code is invalid or expired.');
            if((int)$token['telegram_2fa_enabled']===1) throw new RuntimeException('Disable Telegram 2FA before changing Telegram.');
            if(!empty($token['telegram_chat_id'])) throw new RuntimeException('Telegram is already linked. Unlink it before linking another account.');

            $q=$pdo->prepare("SELECT id,chat_id,linked_user_id FROM telegram_users WHERE id=? LIMIT 1 FOR UPDATE");
            $q->execute([$telegramUserId]);
            $tg=$q->fetch();
            if(!$tg || (int)$tg['chat_id']!==$chatId) throw new RuntimeException('Telegram identity mismatch.');
            if(!empty($tg['linked_user_id']) && (int)$tg['linked_user_id']!==(int)$token['user_id']) throw new RuntimeException('This Telegram account is already linked.');

            $q=$pdo->prepare("SELECT id FROM users WHERE telegram_chat_id=? AND id<>? LIMIT 1 FOR UPDATE");
            $q->execute([$chatId,$token['user_id']]);
            if($q->fetch()) throw new RuntimeException('Telegram Chat ID is already linked to another account.');

            $q=$pdo->prepare("SELECT id FROM telegram_users WHERE linked_user_id=? AND id<>? LIMIT 1 FOR UPDATE");
            $q->execute([$token['user_id'],$telegramUserId]);
            if($q->fetch()) throw new RuntimeException('Panel account already has a linked Telegram identity.');

            $pdo->prepare("UPDATE users SET telegram_chat_id=?,auth_version=auth_version+1 WHERE id=?")->execute([$chatId,$token['user_id']]);
            $pdo->prepare("UPDATE telegram_users SET linked_user_id=?,last_seen_at=NOW() WHERE id=?")->execute([$token['user_id'],$telegramUserId]);
            $pdo->prepare("UPDATE license_keys SET owner_user_id=? WHERE telegram_user_id=? AND key_source='telegram_guest'")->execute([$token['user_id'],$telegramUserId]);
            $pdo->prepare("UPDATE telegram_link_tokens SET used_at=NOW() WHERE id=?")->execute([$token['id']]);
            $pdo->prepare("DELETE FROM telegram_link_tokens WHERE user_id=? AND id<>?")->execute([$token['user_id'],$token['id']]);
            $pdo->prepare("DELETE FROM api_tokens WHERE user_id=?")->execute([$token['user_id']]);
            $pdo->commit();
            try{ Security::audit((int)$token['user_id'],'telegram_linked',['telegram_user_id'=>$telegramUserId,'chat_id_suffix'=>substr((string)$chatId,-4),'sessions_revoked'=>true]); }catch(Throwable){}
            return ['user_id'=>(int)$token['user_id'],'username'=>$token['username']];
        }catch(Throwable $e){ if($pdo->inTransaction())$pdo->rollBack(); throw $e; }
    }

    public static function unlink(array $panelUser,bool $force=false): void
    {
        $userId=(int)($panelUser['id'] ?? 0);
        if(!$force && !Auth::recentlyAuthenticated(300)) throw new RuntimeException('Fresh sign-in required before changing Telegram security.');
        $pdo=Database::pdo();
        $q=$pdo->prepare('SELECT telegram_2fa_enabled FROM users WHERE id=? LIMIT 1');
        $q->execute([$userId]);
        if((int)$q->fetchColumn()===1 && !$force) throw new RuntimeException('Disable Telegram 2FA before unlinking Telegram.');
        $pdo->beginTransaction();
        try{
            $pdo->prepare("UPDATE telegram_users SET linked_user_id=NULL WHERE linked_user_id=?")->execute([$userId]);
            $pdo->prepare("UPDATE users SET telegram_chat_id=NULL,telegram_2fa_enabled=0,telegram_2fa_enabled_at=NULL,auth_version=auth_version+1 WHERE id=?")->execute([$userId]);
            $pdo->prepare("DELETE FROM telegram_link_tokens WHERE user_id=?")->execute([$userId]);
            $pdo->prepare("DELETE FROM telegram_2fa_activation_tokens WHERE user_id=?")->execute([$userId]);
            $pdo->prepare("DELETE FROM login_2fa_challenges WHERE user_id=?")->execute([$userId]);
            $pdo->prepare("DELETE FROM api_tokens WHERE user_id=?")->execute([$userId]);
            $pdo->commit();
            if(!$force){
                $v=$pdo->prepare('SELECT auth_version FROM users WHERE id=? LIMIT 1');$v->execute([$userId]);
                Auth::refreshCurrentSessionVersion($userId,(int)$v->fetchColumn());
            }
            try{Security::audit($userId,'telegram_unlinked',['sessions_revoked'=>true]);}catch(Throwable){}
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }

    public static function sendPrivateMessage(int $chatId,string $html): bool
    {
        if($chatId<=0 || trim($html)==='') return false;
        $token=(string)Config::get('telegram_bot_token','');
        if($token==='') throw new RuntimeException('TELEGRAM_BOT_TOKEN is not configured.');
        $ch=curl_init('https://api.telegram.org/bot'.$token.'/sendMessage');
        if($ch===false) throw new RuntimeException('Could not initialize Telegram request.');
        $payload=json_encode(['chat_id'=>$chatId,'text'=>$html,'parse_mode'=>'HTML','disable_web_page_preview'=>true],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$payload,CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_TIMEOUT=>10,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_FOLLOWLOCATION=>false]);
        $body=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$error=curl_error($ch);curl_close($ch);
        if($body===false || $status!==200){error_log('Telegram security message failed HTTP '.$status.($error!==''?' transport-error':''));return false;}
        $decoded=json_decode((string)$body,true);return is_array($decoded)&&($decoded['ok']??false)===true;
    }

    public static function unregisteredGuests(): array
    {
        $q=Database::pdo()->query("SELECT tg.*,(SELECT COUNT(*) FROM license_keys k WHERE k.telegram_user_id=tg.id AND k.key_source='telegram_guest') AS guest_key_count_db FROM telegram_users tg WHERE tg.linked_user_id IS NULL ORDER BY tg.last_seen_at DESC LIMIT 1000");
        return $q->fetchAll() ?: [];
    }

    public static function guestKeys(int $telegramUserId,int $limit=10): array
    {
        $limit=max(1,min(50,$limit));
        $q=Database::pdo()->prepare("SELECT k.*,(SELECT COUNT(*) FROM license_devices d WHERE d.license_key_id=k.id AND d.active=1) AS device_count FROM license_keys k WHERE k.telegram_user_id=? AND k.key_source='telegram_guest' ORDER BY k.id DESC LIMIT ".$limit);
        $q->execute([$telegramUserId]);return $q->fetchAll() ?: [];
    }

    public static function displayName(array $tg): string
    {
        $name=trim(trim((string)($tg['first_name']??'')).' '.trim((string)($tg['last_name']??'')));
        return $name!==''?$name:'Telegram User';
    }

    public static function nextGuestEligible(?string $lastKeyAt): string
    {
        if(!$lastKeyAt)return 'Available now';$next=strtotime($lastKeyAt)+604800;
        return $next<=time()?'Available now':date('Y-m-d H:i:s',$next);
    }

    private static function chatId(string $raw): int
    {
        $raw=trim($raw);if(!preg_match('/^[1-9][0-9]{4,18}$/',$raw))throw new RuntimeException('Enter a valid private Telegram Chat ID.');
        $id=filter_var($raw,FILTER_VALIDATE_INT);if($id===false||$id<=0)throw new RuntimeException('Invalid Telegram Chat ID.');return (int)$id;
    }

    private static function clean(string $value,int $max): string
    {
        $value=trim(preg_replace('/[\x00-\x1F\x7F]/u','',$value)??'');return substr($value,0,$max);
    }
}
