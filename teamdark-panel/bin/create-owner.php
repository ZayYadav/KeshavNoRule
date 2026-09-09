<?php
declare(strict_types=1);

use TeamDark\Panel\{Config,Database,Security};

$root = dirname(__DIR__);
require $root.'/app/Config.php'; require $root.'/app/Database.php'; require $root.'/app/Security.php';
Config::load($root);
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }
$user = strtolower(trim($argv[1] ?? ''));
$pass = (string)($argv[2] ?? '');
$strongPassword = strlen($pass) >= 12
    && strlen($pass) <= 200
    && preg_match('/[A-Za-z]/', $pass)
    && preg_match('/\d/', $pass)
    && preg_match('/[^A-Za-z0-9]/', $pass);

if (
    !preg_match('/^[a-z0-9_.-]{3,32}$/', $user)
    || !$strongPassword
) {
    fwrite(
        STDERR,
        "Usage: php bin/create-owner.php ownername 'StrongPassword12+'\n"
        ."Password must be 12+ chars with a letter, number and symbol.\n"
    );
    exit(1);
}
$code = 'TD'.strtoupper(bin2hex(random_bytes(6)));
$stmt = Database::pdo()->prepare("INSERT INTO users(username,password_hash,role,balance,referral_code,status) VALUES(?,?,'owner',0,?,'active')");
$stmt->execute([$user, Security::passwordHash($pass), $code]);
echo "Owner created: {$user}\nReferral code: {$code}\n";
