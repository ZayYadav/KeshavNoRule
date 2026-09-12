<?php
declare(strict_types=1);

use TeamDark\Panel\Config;

$root = dirname(__DIR__);
require $root.'/app/Config.php';

Config::load($root);

$token = (string)Config::get('telegram_bot_token', '');
$secret = (string)Config::get('telegram_webhook_secret', '');
$appUrl = rtrim((string)Config::get('app_url', ''), '/');

if ($token === '') {
    fwrite(STDERR, "TELEGRAM_BOT_TOKEN is missing.\n");
    exit(1);
}

if (!preg_match('/^[A-Za-z0-9_-]{16,256}$/', $secret)) {
    fwrite(
        STDERR,
        "TELEGRAM_WEBHOOK_SECRET must be 16-256 chars using A-Z a-z 0-9 _ - only.\n"
    );
    exit(1);
}

if (!preg_match('#^https://#i', $appUrl)) {
    fwrite(STDERR, "APP_URL must be HTTPS for Telegram webhook setup.\n");
    exit(1);
}

$webhookUrl = $appUrl.'/telegram/webhook';

$payload = json_encode([
    'url'=>$webhookUrl,
    'secret_token'=>$secret,
    'allowed_updates'=>['message','callback_query'],
    'max_connections'=>20,
    'drop_pending_updates'=>false,
], JSON_UNESCAPED_SLASHES);

$ch = curl_init('https://api.telegram.org/bot'.$token.'/setWebhook');

curl_setopt_array($ch, [
    CURLOPT_POST=>true,
    CURLOPT_POSTFIELDS=>$payload,
    CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
    CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_CONNECTTIMEOUT=>5,
    CURLOPT_TIMEOUT=>15,
    CURLOPT_SSL_VERIFYPEER=>true,
    CURLOPT_SSL_VERIFYHOST=>2,
    CURLOPT_FOLLOWLOCATION=>false,
]);

$body = curl_exec($ch);
$status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);

$data = is_string($body) ? json_decode($body, true) : null;

if (
    $status !== 200
    || !is_array($data)
    || !($data['ok'] ?? false)
) {
    fwrite(STDERR, "Telegram setWebhook failed.\n");
    exit(1);
}

echo "Webhook configured: ".$webhookUrl.PHP_EOL;
