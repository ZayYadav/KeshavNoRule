<?php
declare(strict_types=1);

namespace TeamDark\Panel;

use RuntimeException;
use Throwable;

final class TelegramBot
{
    public static function handle(array $update): void
    {
        $updateId = (int)($update['update_id'] ?? 0);

        if ($updateId <= 0 || !self::claimUpdate($updateId)) {
            return;
        }

        try {
            $callback = is_array($update['callback_query'] ?? null)
                ? $update['callback_query']
                : null;
            $message = is_array($update['message'] ?? null)
                ? $update['message']
                : null;

            $from = $callback['from'] ?? ($message['from'] ?? null);

            if (!is_array($from)) {
                return;
            }

            $chat = $callback['message']['chat'] ?? ($message['chat'] ?? null);

            if (!is_array($chat) || ($chat['type'] ?? '') !== 'private') {
                return;
            }

            $chatId = (int)($chat['id'] ?? 0);

            if ($chatId <= 0 || (int)($from['id'] ?? 0) !== $chatId) {
                return;
            }

            Security::rateLimit('telegram-bot-global', 3000, 60, 'global');
            Security::rateLimit('telegram-bot-chat', 80, 60, (string)$chatId);

            $tg = TelegramService::upsertTelegramUser($from);
            $panelUser = self::panelUserByChatId($chatId);
            $isOwner = self::isOwnerChat($chatId);

            if (!$isOwner && PanelControl::blocked($panelUser)) {
                if ($callback) self::answerCallback((string)($callback['id'] ?? ''));
                self::send($chatId, self::h(PanelControl::settings()['message']));
                return;
            }
            Security::audit($panelUser ? (int)$panelUser['id'] : null, 'telegram_interaction', [
                'telegram_user_id'=>(int)$tg['id'],
                'operation'=>$callback ? substr((string)($callback['data'] ?? ''),0,100) : 'message',
            ]);

            if ($callback) {
                self::answerCallback((string)($callback['id'] ?? ''));
                self::handleCallback(
                    $chatId,
                    (string)($callback['data'] ?? ''),
                    $tg,
                    $panelUser,
                    $isOwner
                );
                return;
            }

            $text = trim((string)($message['text'] ?? ''));

            if (preg_match('/^\/link(?:@\w+)?\s+(\S+)$/i', $text, $m)) {
                Security::rateLimit('telegram-link-chat', 8, 900, (string)$chatId);

                $linked = TelegramService::confirmLink(
                    $chatId,
                    $m[1],
                    (int)$tg['id']
                );

                self::send(
                    $chatId,
                    "✅ <b>Panel linked</b>\n@"
                    .self::h($linked['username'])
                    ."\n\nYour previous Telegram guest keys now belong to this panel account.",
                    self::menuKeyboard($chatId, self::panelUserByChatId($chatId))
                );
                return;
            }

            if (preg_match('/^\/2fa(?:@\w+)?$/i', $text)) {
                self::showTwoFactorSetup($chatId, $panelUser);
                return;
            }

            self::showMenu($chatId, $tg, $panelUser, $isOwner);
        } catch (RuntimeException $e) {
            // Expected user/security errors are consumed once so Telegram does not retry-amplify them.
            try {
                if (isset($chatId) && $chatId > 0) {
                    self::send(
                        $chatId,
                        "🛡 <b>Request blocked</b>\n\nThe action could not be completed securely."
                    );
                }
            } catch (Throwable) {
            }
            return;
        } catch (Throwable $e) {
            self::releaseUpdate($updateId);
            throw $e;
        }
    }

    private static function handleCallback(
        int $chatId,
        string $data,
        array $tg,
        ?array $panelUser,
        bool $isOwner
    ): void {
        if ($data === 'menu') {
            self::showMenu($chatId, $tg, $panelUser, $isOwner);
            return;
        }

        if ($data === 'guest:2h') {
            if (!(bool)Config::get('telegram_guest_free_keys_enabled', false)) {
                self::send(
                    $chatId,
                    "🛡 <b>Free guest keys are disabled</b>\n\nLink a panel account to use licensed key generation.",
                    self::menuKeyboard($chatId, $panelUser)
                );
                return;
            }

            if ($panelUser) {
                self::send($chatId, 'This Telegram account is linked. Use normal panel key generation.', self::menuKeyboard($chatId, $panelUser));
                return;
            }

            $created = KeyManager::createTelegramGuestKey(
                self::ownerActor(),
                (int)$tg['id']
            );

            self::send(
                $chatId,
                "🎁 <b>Free 2-hour key</b>\n<code>"
                .self::h($created['key'])
                ."</code>\n\n⏱ Timer starts on first Loader login.\n📱 1 device\n🗓 Next free key: "
                .self::h($created['next_eligible_at']),
                self::menuKeyboard($chatId, null)
            );
            return;
        }

        if ($data === 'link:help') {
            $url = (string)Config::get('app_url', '');
            $keyboard = [];
            if ($url !== '') {
                $keyboard[] = [[
                    'text'=>'🌐 Open Panel',
                    'url'=>$url.'/dashboard',
                ]];
            }
            $keyboard[] = [[
                'text'=>'⬅️ Menu',
                'callback_data'=>'menu',
            ]];

            self::send(
                $chatId,
                "🔗 <b>Link your panel account</b>\n\n1) Login to the panel.\n2) On Dashboard enter this Chat ID:\n<code>"
                .$chatId
                ."</code>\n3) Panel gives a TDLINK code.\n4) Send:\n<code>/link TDLINK-XXXXXXXX</code>\n\nThe code expires in 15 minutes.",
                $keyboard
            );
            return;
        }

        if (preg_match('/^gen:(1|7|30)$/', $data, $m)) {
            if (!$panelUser) {
                self::send($chatId, 'Link your panel account first.', self::menuKeyboard($chatId, null));
                return;
            }

            if (!(bool)Config::get('telegram_linked_key_generation_enabled', false)) {
                self::send(
                    $chatId,
                    "🛡 <b>Bot generation locked</b>\n\n"
                    ."For account security, generate new keys from the web panel.",
                    self::menuKeyboard($chatId, $panelUser)
                );
                return;
            }

            $days = (int)$m[1];
            $created = KeyManager::create(
                $panelUser,
                'Telegram Bot',
                $days * 86400,
                false,
                1,
                false
            );

            self::send(
                $chatId,
                "🔑 <b>".$days." day key generated</b>\n<code>"
                .self::h($created['key'])
                ."</code>\nCost: "
                .(int)$created['cost']
                ." credit(s).",
                self::menuKeyboard($chatId, $panelUser)
            );
            return;
        }

        if ($data === 'my:account') {
            if (!$panelUser) {
                self::send($chatId, 'No linked panel account.', self::menuKeyboard($chatId, null));
                return;
            }
            self::showAccount($chatId, $panelUser);
            return;
        }

        if ($data === 'my:keys') {
            if (!$panelUser) {
                self::send($chatId, 'No linked panel account.', self::menuKeyboard($chatId, null));
                return;
            }
            self::showMyKeys($chatId, $panelUser);
            return;
        }

        if ($data === 'security:2fa') {
            self::showTwoFactorSetup($chatId, $panelUser);
            return;
        }

        if (!$isOwner) {
            self::send($chatId, 'Owner action denied.', self::menuKeyboard($chatId, $panelUser));
            return;
        }

        if ($data === 'owner:stats') {
            self::showOwnerStats($chatId);
            return;
        }

        if ($data === 'owner:users') {
            self::showOwnerUsers($chatId);
            return;
        }

        if ($data === 'owner:tg') {
            self::showOwnerTelegramGuests($chatId);
            return;
        }

        if ($data === 'owner:keys') {
            self::showOwnerKeys($chatId);
            return;
        }

        if ($data === 'owner:refs') {
            self::showOwnerReferrals($chatId);
            return;
        }

        $ownerMutation = $data === 'owner:gen30'
            || str_starts_with($data, 'owner:ref:')
            || str_starts_with($data, 'owner:bal:')
            || str_starts_with($data, 'owner:status:')
            || str_starts_with($data, 'owner:keyact:');

        if (
            $ownerMutation
            && !(bool)Config::get('telegram_owner_mutations_enabled', false)
        ) {
            self::send(
                $chatId,
                "🛡 <b>Owner mutation blocked</b>\n\n"
                ."High-risk Telegram changes are disabled server-side. "
                ."Use the web Owner Console with fresh authentication.",
                self::ownerKeyboard()
            );
            return;
        }

        if ($data === 'owner:gen30') {
            $created = KeyManager::create(
                self::ownerActor(),
                'Telegram Owner',
                30 * 86400,
                false,
                1,
                false
            );
            self::send(
                $chatId,
                "👑 <b>Owner 30-day key</b>\n<code>"
                .self::h($created['key'])
                ."</code>",
                self::ownerKeyboard()
            );
            return;
        }

        if (preg_match('/^owner:ref:(admin|reseller|user)$/', $data, $m)) {
            $invite = ReferralManager::create(self::ownerActor(), $m[1]);
            self::send(
                $chatId,
                "🎟 <b>".self::h(ucfirst($m[1]))." referral</b>\n<code>"
                .self::h($invite['code'])
                ."</code>",
                self::ownerKeyboard()
            );
            return;
        }

        if (preg_match('/^owner:user:(\d+)$/', $data, $m)) {
            self::showOwnerUser($chatId, (int)$m[1]);
            return;
        }

        if (preg_match('/^owner:bal:(\d+):(p10|p100|m10)$/', $data, $m)) {
            $delta = ['p10'=>10,'p100'=>100,'m10'=>-10][$m[2]];
            self::adjustBalance((int)$m[1], $delta);
            self::showOwnerUser($chatId, (int)$m[1]);
            return;
        }

        if (preg_match('/^owner:status:(\d+)$/', $data, $m)) {
            self::toggleUserStatus((int)$m[1]);
            self::showOwnerUser($chatId, (int)$m[1]);
            return;
        }

        if (preg_match('/^owner:key:(\d+)$/', $data, $m)) {
            self::showOwnerKey($chatId, (int)$m[1]);
            return;
        }

        if (preg_match('/^owner:keyact:(\d+):(disable|enable|reset|delete)$/', $data, $m)) {
            $actor = self::ownerActor();
            $keyId = (int)$m[1];
            $action = $m[2];

            if ($action === 'reset') {
                KeyManager::resetDevices($actor, $keyId);
            } else {
                KeyManager::action($actor, $keyId, $action);
            }

            if ($action === 'delete') {
                self::showOwnerKeys($chatId);
            } else {
                self::showOwnerKey($chatId, $keyId);
            }
            return;
        }

        if (preg_match('/^owner:tg:(\d+)$/', $data, $m)) {
            self::showOwnerTelegramGuest($chatId, (int)$m[1]);
            return;
        }

        self::showMenu($chatId, $tg, $panelUser, true);
    }

    private static function showMenu(
        int $chatId,
        array $tg,
        ?array $panelUser,
        bool $isOwner
    ): void {
        $name = TelegramService::displayName($tg);

        if ($isOwner) {
            self::send(
                $chatId,
                "👑 <b>TEAM DARK BOT OWNER</b>\n"
                .self::h($name)
                ."\n\nFull control is available through the inline buttons below.",
                self::ownerKeyboard()
            );
            return;
        }

        if ($panelUser) {
            self::send(
                $chatId,
                "⚡ <b>TEAM DARK</b>\nWelcome "
                .self::h($panelUser['name'] ?: $panelUser['username'])
                ."\n\nPanel: @".self::h($panelUser['username'])
                ."\nRole: ".self::h(strtoupper($panelUser['role']))
                ."\nBalance: ".self::h($panelUser['role'] === 'owner' ? '∞' : (string)$panelUser['balance']),
                self::menuKeyboard($chatId, $panelUser)
            );
            return;
        }

        self::send(
            $chatId,
            "⚡ <b>TEAM DARK GUEST</b>\n"
            .self::h($name)
            ."\n\nUnregistered Telegram users can generate one free <b>2-hour</b> key every <b>7 days</b>.\nThe timer starts on first Loader login.",
            self::menuKeyboard($chatId, null)
        );
    }

    private static function showTwoFactorSetup(int $chatId, ?array $panelUser): void
    {
        if (!$panelUser) {
            self::send(
                $chatId,
                "🛡 <b>Telegram 2FA</b>\n\nLink your panel account first. Then this bot can issue your 2FA activation key.",
                self::menuKeyboard($chatId, null)
            );
            return;
        }

        if ((int)($panelUser['telegram_2fa_enabled'] ?? 0) === 1) {
            self::send(
                $chatId,
                "✅ <b>Telegram 2FA is enabled</b>\n\nEvery new panel login requires an 8-digit code delivered here.\n\nTo disable it, use Dashboard and confirm with your current password.",
                self::menuKeyboard($chatId, $panelUser)
            );
            return;
        }

        try {
            $activation = TwoFactorService::createActivationToken($panelUser);

            self::send(
                $chatId,
                "🛡 <b>TEAM DARK 2FA ACTIVATION</b>\n\n"
                ."Use this one-time key on your Dashboard:\n<code>"
                .self::h($activation['code'])
                ."</code>\n\n⏱ Expires in 10 minutes.\n"
                ."This key only enables 2FA for @".self::h($panelUser['username']).".",
                self::menuKeyboard($chatId, $panelUser)
            );
        } catch (Throwable $e) {
            $message = $e instanceof RuntimeException
                ? $e->getMessage()
                : 'Could not create a 2FA activation key. Try again later.';

            self::send(
                $chatId,
                "⚠️ <b>2FA setup unavailable</b>\n"
                .self::h($message),
                self::menuKeyboard($chatId, $panelUser)
            );
        }
    }

    private static function showAccount(int $chatId, array $user): void
    {
        $keyCountQ = Database::pdo()->prepare(
            "SELECT COUNT(*) FROM license_keys WHERE owner_user_id=?"
        );
        $keyCountQ->execute([$user['id']]);

        self::send(
            $chatId,
            "👤 <b>My Panel Account</b>\n"
            ."Name: ".self::h($user['name'] ?: $user['username'])
            ."\nUsername: @".self::h($user['username'])
            ."\nRole: ".self::h(strtoupper($user['role']))
            ."\nBalance: ".self::h($user['role'] === 'owner' ? '∞' : (string)$user['balance'])
            ."\nChat ID: <code>".$chatId."</code>"
            ."\n2FA: ".((int)($user['telegram_2fa_enabled'] ?? 0) === 1 ? 'ENABLED ✅' : 'Optional / off')
            ."\nKeys: ".(int)$keyCountQ->fetchColumn(),
            self::menuKeyboard($chatId, $user)
        );
    }

    private static function showMyKeys(int $chatId, array $user): void
    {
        $q = Database::pdo()->prepare(
            "SELECT * FROM license_keys
             WHERE owner_user_id=?
             ORDER BY id DESC
             LIMIT 8"
        );
        $q->execute([$user['id']]);
        $rows = $q->fetchAll() ?: [];

        $text = "🔐 <b>My recent keys</b>\n\n";

        if (!$rows) {
            $text .= 'No keys yet.';
        } else {
            foreach ($rows as $row) {
                $text .= '#'.(int)$row['id'].' <code>'
                    .self::h(self::ownerSecret(self::plainKey($row)))
                    ."</code>\n"
                    .self::h(strtoupper((string)$row['status']))
                    .' • '
                    .self::h((int)$row['unlimited_expiry'] === 1 ? 'UNLIMITED' : ((int)$row['duration_seconds'].' sec'))
                    ."\n\n";
            }
        }

        self::send($chatId, $text, self::menuKeyboard($chatId, $user));
    }

    private static function showOwnerStats(int $chatId): void
    {
        $pdo = Database::pdo();
        $users = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        $tgGuests = (int)$pdo->query(
            "SELECT COUNT(*) FROM telegram_users WHERE linked_user_id IS NULL"
        )->fetchColumn();
        $linked = (int)$pdo->query(
            "SELECT COUNT(*) FROM telegram_users WHERE linked_user_id IS NOT NULL"
        )->fetchColumn();
        $keys = (int)$pdo->query(
            "SELECT COUNT(*) FROM license_keys WHERE status NOT IN ('expired','revoked')"
        )->fetchColumn();
        $refs = (int)$pdo->query(
            "SELECT COUNT(*) FROM referral_invites WHERE status='pending'"
        )->fetchColumn();

        self::send(
            $chatId,
            "📊 <b>Owner Stats</b>\n"
            ."Panel users: ".$users
            ."\nUnregistered TG users: ".$tgGuests
            ."\nLinked Telegram: ".$linked
            ."\nCurrent keys: ".$keys
            ."\nPending referrals: ".$refs,
            self::ownerKeyboard()
        );
    }

    private static function showOwnerUsers(int $chatId): void
    {
        $rows = Database::pdo()->query(
            "SELECT id,name,username,role,balance,status,telegram_chat_id
             FROM users
             ORDER BY id DESC
             LIMIT 10"
        )->fetchAll() ?: [];

        $text = "👥 <b>Panel Users</b>\n\n";
        $keyboard = [];

        foreach ($rows as $row) {
            $text .= '#'.(int)$row['id'].' '
                .self::h($row['name'] ?: $row['username'])
                .' @'.self::h($row['username'])
                .' • '.self::h(strtoupper($row['role']))
                .' • '.self::h($row['status'])
                ."\n";

            $keyboard[] = [[
                'text'=>'👤 @'.$row['username'],
                'callback_data'=>'owner:user:'.(int)$row['id'],
            ]];
        }

        $keyboard[] = [[
            'text'=>'⬅️ Owner Menu',
            'callback_data'=>'menu',
        ]];

        self::send($chatId, $text, $keyboard);
    }

    private static function showOwnerUser(int $chatId, int $userId): void
    {
        $q = Database::pdo()->prepare(
            "SELECT id,name,username,role,balance,status,telegram_chat_id,created_at
             FROM users WHERE id=? LIMIT 1"
        );
        $q->execute([$userId]);
        $u = $q->fetch();

        if (!$u) {
            throw new RuntimeException('Panel user not found.');
        }

        $text = "👤 <b>User #".(int)$u['id']."</b>\n"
            ."Name: ".self::h($u['name'] ?: $u['username'])
            ."\nUsername: @".self::h($u['username'])
            ."\nRole: ".self::h(strtoupper($u['role']))
            ."\nStatus: ".self::h($u['status'])
            ."\nBalance: ".self::h($u['role'] === 'owner' ? '∞' : (string)$u['balance'])
            ."\nTelegram: ".self::h($u['telegram_chat_id'] ?: 'Not linked')
            ."\nCreated: ".self::h($u['created_at']);

        $keyboard = [];

        if ($u['role'] !== 'owner') {
            $keyboard[] = [
                ['text'=>'➕10','callback_data'=>'owner:bal:'.$userId.':p10'],
                ['text'=>'➕100','callback_data'=>'owner:bal:'.$userId.':p100'],
                ['text'=>'➖10','callback_data'=>'owner:bal:'.$userId.':m10'],
            ];
            $keyboard[] = [[
                'text'=>$u['status'] === 'active' ? '⛔ Disable User' : '✅ Enable User',
                'callback_data'=>'owner:status:'.$userId,
            ]];
        }

        $keyboard[] = [[
            'text'=>'⬅️ Users',
            'callback_data'=>'owner:users',
        ]];

        self::send($chatId, $text, $keyboard);
    }

    private static function showOwnerTelegramGuests(int $chatId): void
    {
        $rows = array_slice(TelegramService::unregisteredGuests(), 0, 10);
        $text = "✈️ <b>Unregistered Telegram Users</b>\n\n";
        $keyboard = [];

        if (!$rows) {
            $text .= 'No unregistered Telegram users.';
        }

        foreach ($rows as $row) {
            $text .= self::h(TelegramService::displayName($row))
                .' • <code>'.self::h($row['chat_id'])."</code>"
                .' • keys '.(int)$row['guest_key_count_db']
                ."\n";

            $keyboard[] = [[
                'text'=>'✈️ '.TelegramService::displayName($row),
                'callback_data'=>'owner:tg:'.(int)$row['id'],
            ]];
        }

        $keyboard[] = [[
            'text'=>'⬅️ Owner Menu',
            'callback_data'=>'menu',
        ]];

        self::send($chatId, $text, $keyboard);
    }

    private static function showOwnerTelegramGuest(int $chatId, int $tgId): void
    {
        $q = Database::pdo()->prepare(
            "SELECT * FROM telegram_users
             WHERE id=? AND linked_user_id IS NULL
             LIMIT 1"
        );
        $q->execute([$tgId]);
        $tg = $q->fetch();

        if (!$tg) {
            throw new RuntimeException('Unregistered Telegram user not found.');
        }

        $keys = TelegramService::guestKeys($tgId, 5);
        $text = "✈️ <b>Telegram Guest</b>\n"
            ."Name: ".self::h(TelegramService::displayName($tg))
            ."\nChat ID: <code>".self::h($tg['chat_id'])."</code>"
            ."\nUsername: ".self::h($tg['username'] ? '@'.$tg['username'] : '—')
            ."\nFirst seen: ".self::h($tg['first_seen_at'])
            ."\nLast seen: ".self::h($tg['last_seen_at'])
            ."\nNext free 2H key: ".self::h(TelegramService::nextGuestEligible($tg['guest_last_key_at']))
            ."\n\n<b>Recent guest keys</b>\n";

        $keyboard = [];

        if (!$keys) {
            $text .= 'No guest keys.';
        }

        foreach ($keys as $key) {
            $text .= '#'.(int)$key['id'].' <code>'
                .self::h(self::ownerSecret(self::plainKey($key)))
                .'</code> • '.self::h($key['status'])."\n";
            $keyboard[] = [[
                'text'=>'🔑 Key #'.(int)$key['id'],
                'callback_data'=>'owner:key:'.(int)$key['id'],
            ]];
        }

        $keyboard[] = [[
            'text'=>'⬅️ TG Users',
            'callback_data'=>'owner:tg',
        ]];

        self::send($chatId, $text, $keyboard);
    }

    private static function showOwnerKeys(int $chatId): void
    {
        $rows = Database::pdo()->query(
            "SELECT k.*,u.username owner_name
             FROM license_keys k
             JOIN users u ON u.id=k.owner_user_id
             ORDER BY k.id DESC
             LIMIT 10"
        )->fetchAll() ?: [];

        $text = "🔑 <b>Latest Keys</b>\n\n";
        $keyboard = [];

        foreach ($rows as $row) {
            $source = $row['key_source'] === 'telegram_guest' ? 'TG' : 'PANEL';
            $text .= '#'.(int)$row['id'].' <code>'
                .self::h(self::plainKey($row))
                .'</code> • '.self::h(strtoupper($row['status']))
                .' • '.$source."\n";
            $keyboard[] = [[
                'text'=>'🔑 #'.(int)$row['id'].' '.strtoupper($row['status']),
                'callback_data'=>'owner:key:'.(int)$row['id'],
            ]];
        }

        if (!$rows) {
            $text .= 'No keys.';
        }

        $keyboard[] = [[
            'text'=>'⬅️ Owner Menu',
            'callback_data'=>'menu',
        ]];

        self::send($chatId, $text, $keyboard);
    }

    private static function showOwnerKey(int $chatId, int $keyId): void
    {
        $q = Database::pdo()->prepare(
            "SELECT k.*,u.username owner_name,
                    tg.chat_id tg_chat_id,tg.first_name tg_first_name,tg.last_name tg_last_name
             FROM license_keys k
             JOIN users u ON u.id=k.owner_user_id
             LEFT JOIN telegram_users tg ON tg.id=k.telegram_user_id
             WHERE k.id=?
             LIMIT 1"
        );
        $q->execute([$keyId]);
        $key = $q->fetch();

        if (!$key) {
            throw new RuntimeException('Key not found.');
        }

        $text = "🔐 <b>Key #".$keyId."</b>\n<code>"
            .self::h(self::plainKey($key))
            ."</code>\nStatus: ".self::h(strtoupper($key['status']))
            ."\nOwner: @".self::h($key['owner_name'])
            ."\nSource: ".self::h($key['key_source'])
            ."\nDuration: ".self::h((int)$key['unlimited_expiry'] === 1 ? 'UNLIMITED' : ((int)$key['duration_seconds'].' sec'))
            ."\nActivated: ".self::h($key['activated_at'] ?: 'Not used')
            ."\nExpires: ".self::h($key['expires_at'] ?: ((int)$key['unlimited_expiry'] === 1 ? 'UNLIMITED' : 'Starts on first use'));

        if ($key['tg_chat_id']) {
            $text .= "\nTG Guest: ".self::h(trim(($key['tg_first_name'] ?? '').' '.($key['tg_last_name'] ?? '')))
                .' • <code>'.self::h($key['tg_chat_id']).'</code>';
        }

        $keyboard = [];

        if ($key['status'] === 'disabled') {
            $keyboard[] = [[
                'text'=>'✅ Unblock',
                'callback_data'=>'owner:keyact:'.$keyId.':enable',
            ]];
        } elseif (in_array($key['status'], ['unused','active'], true)) {
            $keyboard[] = [[
                'text'=>'⛔ Block',
                'callback_data'=>'owner:keyact:'.$keyId.':disable',
            ]];
        }

        $keyboard[] = [
            ['text'=>'♻️ Reset Devices','callback_data'=>'owner:keyact:'.$keyId.':reset'],
            ['text'=>'🗑 Delete','callback_data'=>'owner:keyact:'.$keyId.':delete'],
        ];
        $keyboard[] = [[
            'text'=>'⬅️ Keys',
            'callback_data'=>'owner:keys',
        ]];

        self::send($chatId, $text, $keyboard);
    }

    private static function showOwnerReferrals(int $chatId): void
    {
        $rows = Database::pdo()->query(
            "SELECT i.code,i.role,i.status,i.created_at,u.username creator
             FROM referral_invites i
             JOIN users u ON u.id=i.created_by
             ORDER BY i.id DESC
             LIMIT 8"
        )->fetchAll() ?: [];

        $text = "🎟 <b>Referrals</b>\n\n";

        foreach ($rows as $row) {
            $text .= '<code>'.self::h(self::ownerSecret((string)$row['code'])).'</code> • '
                .self::h(strtoupper($row['role']))
                .' • '.self::h(strtoupper($row['status']))
                ."\n";
        }

        if (!$rows) {
            $text .= 'No referrals yet.';
        }

        $keyboard = [
            [
                ['text'=>'➕ Admin','callback_data'=>'owner:ref:admin'],
                ['text'=>'➕ Reseller','callback_data'=>'owner:ref:reseller'],
            ],
            [
                ['text'=>'➕ User','callback_data'=>'owner:ref:user'],
            ],
            [
                ['text'=>'⬅️ Owner Menu','callback_data'=>'menu'],
            ],
        ];

        self::send($chatId, $text, $keyboard);
    }

    private static function adjustBalance(int $targetId, int $delta): void
    {
        $actor = self::ownerActor();
        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $q = $pdo->prepare(
                "SELECT id,role,balance FROM users WHERE id=? LIMIT 1 FOR UPDATE"
            );
            $q->execute([$targetId]);
            $target = $q->fetch();

            if (!$target || $target['role'] === 'owner') {
                throw new RuntimeException('Owner balance cannot be adjusted.');
            }

            $next = (int)$target['balance'] + $delta;

            if ($next < 0) {
                throw new RuntimeException('Balance cannot go below zero.');
            }

            $pdo->prepare(
                "UPDATE users SET balance=? WHERE id=?"
            )->execute([$next, $targetId]);

            $pdo->prepare(
                "INSERT INTO balance_ledger(user_id,actor_user_id,amount,reason)
                 VALUES(?,?,?,?)"
            )->execute([
                $targetId,
                $actor['id'],
                $delta,
                'Telegram owner balance adjustment',
            ]);

            $pdo->commit();

            try {
                Security::audit((int)$actor['id'], 'telegram_owner_balance_adjusted', [
                    'target_id'=>$targetId,
                    'amount'=>$delta,
                ]);
            } catch (Throwable) {
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private static function toggleUserStatus(int $targetId): void
    {
        $actor = self::ownerActor();

        if ((int)$actor['id'] === $targetId) {
            throw new RuntimeException('Bot owner cannot disable itself.');
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $q = $pdo->prepare(
                "SELECT id,role,status FROM users WHERE id=? LIMIT 1 FOR UPDATE"
            );
            $q->execute([$targetId]);
            $target = $q->fetch();

            if (!$target || $target['role'] === 'owner') {
                throw new RuntimeException('Owner account cannot be toggled here.');
            }

            $next = $target['status'] === 'active' ? 'disabled' : 'active';

            $pdo->prepare(
                "UPDATE users SET status=?,auth_version=auth_version+1 WHERE id=?"
            )->execute([$next, $targetId]);
            $pdo->prepare('DELETE FROM api_tokens WHERE user_id=?')
                ->execute([$targetId]);

            $pdo->commit();

            try {
                Security::audit((int)$actor['id'], 'telegram_owner_user_status', [
                    'target_id'=>$targetId,
                    'status'=>$next,
                ]);
            } catch (Throwable) {
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private static function ownerActor(): array
    {
        $ownerChat = trim((string)Config::get('telegram_owner_chat_id', ''));
        if ($ownerChat === '' || !preg_match('/^[1-9][0-9]{4,18}$/', $ownerChat)) {
            throw new RuntimeException('Telegram Owner identity is not configured.');
        }

        $q = Database::pdo()->prepare(
            "SELECT id,name,username,role,balance,telegram_chat_id,
                    telegram_2fa_enabled,telegram_2fa_enabled_at,status,created_at
             FROM users
             WHERE role='owner'
               AND status='active'
               AND telegram_chat_id=?
             LIMIT 1"
        );
        $q->execute([(int)$ownerChat]);
        $owner = $q->fetch();

        if (!$owner) {
            throw new RuntimeException(
                'Owner bot access requires the configured Telegram Chat ID to be securely linked to an active Owner account.'
            );
        }

        return $owner;
    }

    private static function panelUserByChatId(int $chatId): ?array
    {
        $q = Database::pdo()->prepare(
            "SELECT id,name,username,role,balance,telegram_chat_id,
                    telegram_2fa_enabled,telegram_2fa_enabled_at,status,created_at
             FROM users
             WHERE telegram_chat_id=? AND status='active'
             LIMIT 1"
        );
        $q->execute([$chatId]);
        $user = $q->fetch();

        return $user ?: null;
    }

    private static function isOwnerChat(int $chatId): bool
    {
        $ownerChat = trim((string)Config::get('telegram_owner_chat_id', ''));

        if ($ownerChat === '' || !hash_equals($ownerChat, (string)$chatId)) {
            return false;
        }

        $q = Database::pdo()->prepare(
            "SELECT id FROM users
             WHERE telegram_chat_id=? AND role='owner' AND status='active'
             LIMIT 1"
        );
        $q->execute([$chatId]);
        return (bool)$q->fetchColumn();
    }

    private static function menuKeyboard(int $chatId, ?array $panelUser): array
    {
        if ($panelUser) {
            return [
                [
                    ['text'=>'👤 My Account','callback_data'=>'my:account'],
                    ['text'=>'🔐 My Keys','callback_data'=>'my:keys'],
                ],
                [
                    ['text'=>'🔑 1 Day','callback_data'=>'gen:1'],
                    ['text'=>'🔑 7 Days','callback_data'=>'gen:7'],
                    ['text'=>'🔑 30 Days','callback_data'=>'gen:30'],
                ],
                [
                    ['text'=>'🛡 2FA Setup','callback_data'=>'security:2fa'],
                ],
            ];
        }

        $guest = [];
        if ((bool)Config::get('telegram_guest_free_keys_enabled', false)) {
            $guest[] = [
                ['text'=>'🎁 Free 2H Key','callback_data'=>'guest:2h'],
            ];
        }
        $guest[] = [
            ['text'=>'🔗 Link Panel Account','callback_data'=>'link:help'],
        ];
        return $guest;
    }

    private static function ownerKeyboard(): array
    {
        return [
            [
                ['text'=>'📊 Stats','callback_data'=>'owner:stats'],
                ['text'=>'👥 Panel Users','callback_data'=>'owner:users'],
            ],
            [
                ['text'=>'✈️ TG Users','callback_data'=>'owner:tg'],
                ['text'=>'🔑 Keys','callback_data'=>'owner:keys'],
            ],
            [
                ['text'=>'🎟 Referrals','callback_data'=>'owner:refs'],
                ['text'=>'➕ 30D Key','callback_data'=>'owner:gen30'],
            ],
            [
                ['text'=>'🔄 Refresh','callback_data'=>'menu'],
            ],
        ];
    }

    private static function send(int $chatId, string $text, array $keyboard = []): void
    {
        $params = [
            'chat_id'=>$chatId,
            'text'=>$text,
            'parse_mode'=>'HTML',
            'disable_web_page_preview'=>true,
        ];

        if ($keyboard) {
            $params['reply_markup'] = [
                'inline_keyboard'=>$keyboard,
            ];
        }

        self::api('sendMessage', $params);
    }

    private static function answerCallback(string $id): void
    {
        if ($id === '') {
            return;
        }

        self::api('answerCallbackQuery', [
            'callback_query_id'=>$id,
        ]);
    }

    private static function api(string $method, array $params): array
    {
        $token = (string)Config::get('telegram_bot_token', '');

        if ($token === '') {
            throw new RuntimeException('TELEGRAM_BOT_TOKEN is not configured.');
        }

        $ch = curl_init(
            'https://api.telegram.org/bot'.$token.'/'.$method
        );

        if ($ch === false) {
            throw new RuntimeException('Could not initialize Telegram request.');
        }

        $payload = json_encode(
            $params,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        curl_setopt_array($ch, [
            CURLOPT_POST=>true,
            CURLOPT_POSTFIELDS=>$payload,
            CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_CONNECTTIMEOUT=>4,
            CURLOPT_TIMEOUT=>10,
            CURLOPT_SSL_VERIFYPEER=>true,
            CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_FOLLOWLOCATION=>false,
        ]);

        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false || $status !== 200) {
            error_log(
                'Telegram API request failed for method '
                .$method
                .' HTTP '
                .$status
                .($error !== '' ? ' transport-error' : '')
            );

            return ['ok'=>false];
        }

        $data = json_decode((string)$body, true);

        return is_array($data) ? $data : ['ok'=>false];
    }

    private static function claimUpdate(int $updateId): bool
    {
        $pdo = Database::pdo();
        $pdo->prepare(
            "DELETE FROM telegram_update_ids
             WHERE received_at<DATE_SUB(NOW(), INTERVAL 7 DAY)"
        )->execute();

        $q = $pdo->prepare(
            "INSERT IGNORE INTO telegram_update_ids(update_id,received_at)
             VALUES(?,NOW())"
        );
        $q->execute([$updateId]);

        return $q->rowCount() === 1;
    }

    private static function releaseUpdate(int $updateId): void
    {
        Database::pdo()->prepare(
            "DELETE FROM telegram_update_ids WHERE update_id=?"
        )->execute([$updateId]);
    }

    private static function ownerSecret(string $value): string
    {
        if ((bool)Config::get('telegram_owner_sensitive_reads_enabled', false)) {
            return $value;
        }

        $length = strlen($value);
        if ($length <= 10) {
            return '********';
        }

        return substr($value, 0, 6).'••••••'.substr($value, -4);
    }

    private static function plainKey(array $row): string
    {
        try {
            return Crypto::decrypt(
                (string)$row['key_cipher'],
                (string)$row['key_iv'],
                (string)$row['key_tag']
            );
        } catch (Throwable) {
            return '[unavailable]';
        }
    }

    private static function h(mixed $value): string
    {
        return htmlspecialchars(
            (string)$value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
    }
}
