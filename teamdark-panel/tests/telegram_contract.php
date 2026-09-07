<?php
declare(strict_types=1);

use TeamDark\Panel\{
    Config,
    Crypto,
    Database,
    KeyManager,
    Security,
    TelegramService
};

$root = dirname(__DIR__);

foreach ([
    'Config',
    'Database',
    'Security',
    'Crypto',
    'TelegramService',
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

echo "Telegram contract OK\n";
