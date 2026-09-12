<?php
declare(strict_types=1);

use TeamDark\Panel\{
    Config,
    Crypto,
    Database,
    KeyManager,
    Security,
    TelegramService,
    TwoFactorService
};

$root = dirname(__DIR__);

foreach ([
    'Config',
    'Database',
    'Security',
    'Crypto',
    'TelegramService',
    'TwoFactorService',
    'KeyManager',
] as $file) {
    require $root.'/app/'.$file.'.php';
}

Config::load($root);
$pdo = Database::pdo();

$owner = $pdo->query(
    "SELECT id,name,username,role,balance,telegram_chat_id,status,created_at
     FROM users
     WHERE role='owner' AND status='active'
     ORDER BY id ASC
     LIMIT 1"
)->fetch();

if (!$owner) {
    throw new RuntimeException('Contract owner missing.');
}

$pdo->prepare(
    "INSERT INTO users(
        name,username,password_hash,role,balance,referral_code,status
     ) VALUES(?,?,?,'user',100,?,'active')"
)->execute([
    'Telegram Contract User',
    'telegram-contract-user',
    Security::passwordHash('TelegramContract@12345'),
    'TDTGCONTRACTUSER',
]);

$userId = (int)$pdo->lastInsertId();
$user = $pdo->query(
    "SELECT id,name,username,role,balance,telegram_chat_id,status,created_at
     FROM users WHERE id=".$userId
)->fetch();

$tg = TelegramService::upsertTelegramUser([
    'id'=>7000000001,
    'first_name'=>'Test',
    'last_name'=>'Telegram',
    'username'=>'testtelegram',
    'language_code'=>'en',
]);

$guest = KeyManager::createTelegramGuestKey(
    $owner,
    (int)$tg['id']
);

$q = $pdo->prepare(
    "SELECT duration_seconds,max_devices,key_source,telegram_user_id,owner_user_id
     FROM license_keys WHERE id=?"
);
$q->execute([$guest['id']]);
$key = $q->fetch();

if (
    !$key
    || (int)$key['duration_seconds'] !== 7200
    || (int)$key['max_devices'] !== 1
    || $key['key_source'] !== 'telegram_guest'
    || (int)$key['telegram_user_id'] !== (int)$tg['id']
    || (int)$key['owner_user_id'] !== (int)$owner['id']
) {
    throw new RuntimeException('Telegram guest key contract failed.');
}

$blocked = false;

try {
    KeyManager::createTelegramGuestKey(
        $owner,
        (int)$tg['id']
    );
} catch (RuntimeException $e) {
    $blocked = str_contains($e->getMessage(), 'Next key');
}

if (!$blocked) {
    throw new RuntimeException('7-day Telegram guest limit failed.');
}

$challenge = TelegramService::createLinkChallenge(
    $user,
    '7000000001'
);

TelegramService::confirmLink(
    7000000001,
    $challenge['code'],
    (int)$tg['id']
);

if (!preg_match('/^TDLINK-[A-F0-9]{24}$/', $challenge['code'])) {
    throw new RuntimeException('Telegram link code entropy/format failed.');
}

$q = $pdo->prepare(
    "SELECT telegram_chat_id FROM users WHERE id=?"
);
$q->execute([$userId]);

if ((int)$q->fetchColumn() !== 7000000001) {
    throw new RuntimeException('Panel Telegram link failed.');
}

$q = $pdo->prepare(
    "SELECT linked_user_id FROM telegram_users WHERE id=?"
);
$q->execute([$tg['id']]);

if ((int)$q->fetchColumn() !== $userId) {
    throw new RuntimeException('Telegram user migration failed.');
}

$q = $pdo->prepare(
    "SELECT owner_user_id FROM license_keys WHERE id=?"
);
$q->execute([$guest['id']]);

if ((int)$q->fetchColumn() !== $userId) {
    throw new RuntimeException('Guest key ownership migration failed.');
}

$q = $pdo->prepare(
    "SELECT id,name,username,role,balance,telegram_chat_id,
            telegram_2fa_enabled,telegram_2fa_enabled_at,status,password_hash,created_at
     FROM users WHERE id=? LIMIT 1"
);
$q->execute([$userId]);
$linkedUser = $q->fetch();

$relinkBlocked = false;
try {
    TelegramService::createLinkChallenge($linkedUser, '7000000002');
} catch (RuntimeException $e) {
    $relinkBlocked = str_contains($e->getMessage(), 'already linked');
}
if (!$relinkBlocked) {
    throw new RuntimeException('Telegram relink protection failed.');
}

$activation = TwoFactorService::createActivationToken($linkedUser);

if (!preg_match('/^TD2FA-[A-F0-9]{12}$/', $activation['code'])) {
    throw new RuntimeException('2FA activation key format failed.');
}

TwoFactorService::activate($linkedUser, $activation['code']);

$q = $pdo->prepare(
    "SELECT telegram_2fa_enabled FROM users WHERE id=?"
);
$q->execute([$userId]);

if ((int)$q->fetchColumn() !== 1) {
    throw new RuntimeException('Telegram 2FA activation failed.');
}

$loginCode = '48372615';
$loginHash = TwoFactorService::loginCodeHash($userId, $loginCode);
$continuation = 'contract-continuation-token-123456789';
$continuationHash = Crypto::fingerprint(
    '2fa-continuation|'.$userId.'|'.$continuation
);

$pdo->prepare(
    "INSERT INTO login_2fa_challenges(
        user_id,code_hash,continuation_hash,expires_at,attempts,created_ip
     ) VALUES(?,?,?,DATE_ADD(NOW(),INTERVAL 5 MINUTE),0,'127.0.0.1')"
)->execute([$userId, $loginHash, $continuationHash]);

$challengeId = (int)$pdo->lastInsertId();

$wrongContinuationBlocked = false;
try {
    TwoFactorService::verifyLoginChallenge(
        $challengeId,
        $userId,
        $loginCode,
        'wrong-continuation'
    );
} catch (RuntimeException $e) {
    $wrongContinuationBlocked = str_contains($e->getMessage(), 'continuation');
}
if (!$wrongContinuationBlocked) {
    throw new RuntimeException('API 2FA continuation binding failed.');
}

$verified = TwoFactorService::verifyLoginChallenge(
    $challengeId,
    $userId,
    $loginCode,
    $continuation
);

if ((int)$verified['id'] !== $userId) {
    throw new RuntimeException('2FA login verification failed.');
}

$reused = false;
try {
    TwoFactorService::verifyLoginChallenge(
        $challengeId,
        $userId,
        $loginCode,
        $continuation
    );
} catch (RuntimeException $e) {
    $reused = str_contains($e->getMessage(), 'invalid or expired');
}

if (!$reused) {
    throw new RuntimeException('2FA login code reuse was not blocked.');
}

TwoFactorService::disable($linkedUser, 'TelegramContract@12345');

$q = $pdo->prepare(
    "SELECT telegram_2fa_enabled FROM users WHERE id=?"
);
$q->execute([$userId]);

if ((int)$q->fetchColumn() !== 0) {
    throw new RuntimeException('2FA disable flow failed.');
}

echo "Telegram 2FA contract OK\n";

echo "Telegram contract OK\n";
