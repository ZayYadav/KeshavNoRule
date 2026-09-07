<?php
declare(strict_types=1);

use TeamDark\Panel\{Config,Crypto,Database,Security};

$root = dirname(__DIR__);

foreach (['Config','Database','Security','Crypto'] as $file) {
    require $root.'/app/'.$file.'.php';
}

Config::load($root);
$pdo = Database::pdo();

$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach ([
    'license_devices',
    'license_keys',
    'api_tokens',
    'balance_ledger',
    'audit_logs',
    'rate_limits',
    'users',
] as $table) {
    $pdo->exec('TRUNCATE TABLE '.$table);
}
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');

$pdo->prepare(
    "INSERT INTO users(
        username,password_hash,role,balance,referral_code,status
     ) VALUES(?,?,'owner',0,?,'active')"
)->execute([
    'contract-owner',
    Security::passwordHash('ContractTest@12345'),
    'TDCONTRACTOWNER',
]);

$ownerId = (int)$pdo->lastInsertId();

function insertTestKey(
    \PDO $pdo,
    int $ownerId,
    string $plain,
    int $durationSeconds,
    int $maxDevices,
    bool $unlimitedExpiry = false,
    bool $unlimitedDevices = false,
    string $status = 'unused',
    ?string $activatedAt = null,
    ?string $expiresAt = null
): void {
    [$cipher, $iv, $tag] = Crypto::encrypt($plain);

    $pdo->prepare(
        "INSERT INTO license_keys(
            owner_user_id,created_by,key_hash,key_cipher,key_iv,key_tag,
            label,game,duration_seconds,unlimited_expiry,
            activated_at,expires_at,last_used_at,
            max_devices,unlimited_devices,status
         ) VALUES(?,?,?,?,?,?,?,'PUBG',?,?,?,?,NULL,?,?,?)"
    )->execute([
        $ownerId,
        $ownerId,
        hash('sha256', $plain),
        $cipher,
        $iv,
        $tag,
        'contract-test',
        $durationSeconds,
        $unlimitedExpiry ? 1 : 0,
        $activatedAt,
        $expiresAt,
        $maxDevices,
        $unlimitedDevices ? 1 : 0,
        $status,
    ]);
}

$keys = [
    'timed'=>'TD-TEST-TIMED-KEY-0001',
    'unlimited'=>'TD-TEST-UNLIMITED-KEY-0002',
    'disabled'=>'TD-TEST-DISABLED-KEY-0003',
    'revoked'=>'TD-TEST-REVOKED-KEY-0004',
    'expired'=>'TD-TEST-EXPIRED-KEY-0005',
    'race_activation'=>'TD-TEST-RACE-ACTIVATION-0006',
    'race_devices'=>'TD-TEST-RACE-DEVICES-0007',
];

insertTestKey(
    $pdo,
    $ownerId,
    $keys['timed'],
    3600,
    2
);

insertTestKey(
    $pdo,
    $ownerId,
    $keys['unlimited'],
    0,
    1,
    true,
    true
);

insertTestKey(
    $pdo,
    $ownerId,
    $keys['disabled'],
    3600,
    2,
    false,
    false,
    'disabled'
);

insertTestKey(
    $pdo,
    $ownerId,
    $keys['revoked'],
    3600,
    2,
    false,
    false,
    'revoked'
);

insertTestKey(
    $pdo,
    $ownerId,
    $keys['expired'],
    3600,
    2,
    false,
    false,
    'active',
    date('Y-m-d H:i:s', time() - 7200),
    date('Y-m-d H:i:s', time() - 3600)
);

insertTestKey(
    $pdo,
    $ownerId,
    $keys['race_activation'],
    7200,
    10
);

insertTestKey(
    $pdo,
    $ownerId,
    $keys['race_devices'],
    7200,
    5
);

echo json_encode($keys, JSON_UNESCAPED_SLASHES);
