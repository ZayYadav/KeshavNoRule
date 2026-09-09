<?php
declare(strict_types=1);

use TeamDark\Panel\{
    Auth,
    Config,
    Crypto,
    Database,
    KeyManager,
    LoaderAuthService,
    PanelControl,
    ReferralManager,
    Security
};

$root = dirname(__DIR__);

foreach ([
    'Config',
    'Database',
    'PanelControl',
    'Security',
    'Crypto',
    'Auth',
    'KeyManager',
    'LoaderAuthService',
    'ReferralManager',
] as $file) {
    require $root.'/app/'.$file.'.php';
}

Config::load($root);
$pdo = Database::pdo();

$owner = $pdo->query(
    "SELECT * FROM users
     WHERE role='owner' AND status='active'
     ORDER BY id ASC
     LIMIT 1"
)->fetch();

if (!$owner) {
    throw new RuntimeException('Hardening contract owner missing.');
}

$weakRejected = false;
try {
    KeyManager::create(
        $owner,
        'weak custom key contract',
        86400,
        false,
        1,
        false,
        'aaaaaaaaaaaaaaaaaaa1'
    );
} catch (RuntimeException $e) {
    $weakRejected = str_contains(
        $e->getMessage(),
        '20–80'
    );
}

if (!$weakRejected) {
    throw new RuntimeException(
        'Weak custom license key was not rejected.'
    );
}

$strong = KeyManager::create(
    $owner,
    'legacy hash migration contract',
    86400,
    false,
    1,
    false,
    'Team-Dark-Hardening-Alpha9_Z7'
);

$legacyHash = Crypto::legacyLicenseLookupHash(
    $strong['key']
);
$hmacHash = Crypto::licenseLookupHash(
    $strong['key']
);

$pdo->prepare(
    'UPDATE license_keys SET key_hash=? WHERE id=?'
)->execute([
    $legacyHash,
    $strong['id'],
]);

$native = LoaderAuthService::authenticate(
    'PUBG',
    $strong['key'],
    'server-hardening-contract-serial',
    '127.0.0.1'
);

if (!($native['status'] ?? false)) {
    throw new RuntimeException(
        'Legacy key migration changed Loader authentication behavior.'
    );
}

$q = $pdo->prepare(
    'SELECT key_hash FROM license_keys WHERE id=?'
);
$q->execute([$strong['id']]);

if (!hash_equals($hmacHash, (string)$q->fetchColumn())) {
    throw new RuntimeException(
        'Legacy license lookup hash was not migrated to APP_KEY HMAC.'
    );
}

$pdo->prepare(
    "INSERT INTO users(
        name,username,password_hash,role,balance,referral_code,status
     ) VALUES(?,?,?,'admin',100,?,'active')"
)->execute([
    'Hardening Admin',
    'hardening-contract-admin',
    Security::passwordHash('HardeningContract@12345'),
    'TDHARDENINGADMIN',
]);

$adminId = (int)$pdo->lastInsertId();
$q = $pdo->prepare(
    'SELECT * FROM users WHERE id=?'
);
$q->execute([$adminId]);
$admin = $q->fetch();

$invite = ReferralManager::create(
    $admin,
    'user'
);

if (!preg_match(
    '/^TD-REF-[A-F0-9]{24}$/',
    (string)$invite['code']
)) {
    throw new RuntimeException(
        'Referral token does not use hardened 96-bit entropy.'
    );
}

$masterSwitchBlocked = false;
try {
    PanelControl::setTelegramOwnerMutationWindow(
        $owner,
        true
    );
} catch (RuntimeException $e) {
    $masterSwitchBlocked = str_contains(
        $e->getMessage(),
        'server master switch'
    );
}

if (!$masterSwitchBlocked) {
    throw new RuntimeException(
        'Telegram Owner mutation window bypassed the disabled server master switch.'
    );
}

echo "Server hardening contract OK\n";
