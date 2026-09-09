<?php
declare(strict_types=1);

use TeamDark\Panel\{
    Auth,
    Config,
    Crypto,
    Database,
    KeyManager,
    ReferralManager,
    Security,
    TelegramBot,
    TelegramService,
    TwoFactorService
};

ini_set('display_errors', '0');
ini_set('html_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

$root = dirname(__DIR__);

foreach ([
    'Config',
    'Database',
    'Security',
    'Crypto',
    'Auth',
    'ReferralManager',
    'TelegramService',
    'TwoFactorService',
    'KeyManager',
    'TelegramBot',
] as $file) {
    require $root.'/app/'.$file.'.php';
}

try {
    Config::load($root);
    Security::headers();
    header('Content-Type: application/json; charset=utf-8');

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        echo '{"ok":false}';
        exit;
    }

    $configuredSecret = (string)Config::get('telegram_webhook_secret', '');
    $receivedSecret = (string)(
        $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? ''
    );

    if (
        $configuredSecret === ''
        || $receivedSecret === ''
        || !hash_equals($configuredSecret, $receivedSecret)
    ) {
        http_response_code(403);
        echo '{"ok":false}';
        exit;
    }

    $length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);

    if ($length > 1048576) {
        http_response_code(413);
        echo '{"ok":false}';
        exit;
    }

    $raw = file_get_contents('php://input') ?: '';
    if (strlen($raw) > 1048576) {
        http_response_code(413);
        echo '{"ok":false}';
        exit;
    }

    try {
        $update = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        $update = null;
    }

    if (!is_array($update)) {
        http_response_code(400);
        echo '{"ok":false}';
        exit;
    }

    TelegramBot::handle($update);

    http_response_code(200);
    echo '{"ok":true}';
} catch (Throwable $e) {
    error_log(
        'TeamDark Telegram webhook failure: '
        .get_class($e)
        .' at '
        .basename($e->getFile())
        .':'
        .$e->getLine()
    );

    http_response_code(500);
    echo '{"ok":false}';
}
