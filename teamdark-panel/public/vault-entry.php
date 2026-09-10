<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$requestId = bin2hex(random_bytes(6));
$logDir = $root.'/storage/logs';
$logFile = $logDir.'/vault-error.log';

@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
@error_reporting(E_ALL);

if (!is_dir($logDir)) {
    @mkdir($logDir, 0750, true);
}

$writeVaultLog = static function (string $message) use ($logFile, $requestId): void {
    $line = '['.date('Y-m-d H:i:s').'] ['.$requestId.'] '.$message.PHP_EOL;
    if (is_dir(dirname($logFile)) && is_writable(dirname($logFile))) {
        @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    }
    error_log('TeamDark Vault ['.$requestId.'] '.$message);
};

$renderFailure = static function (string $message = 'Binary Vault could not start.') use ($requestId): never {
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=UTF-8');
        header('Cache-Control: no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');
    }

    $safe = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $ref = htmlspecialchars($requestId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        .'<title>Binary Vault</title><style>body{margin:0;background:#06080b;color:#eaf2f7;font-family:system-ui,-apple-system,Segoe UI,sans-serif;display:grid;place-items:center;min-height:100vh}.c{width:min(620px,90vw);padding:28px;border:1px solid #24313a;border-radius:20px;background:#0b1015;box-shadow:0 30px 90px #0008}.k{font-size:12px;letter-spacing:.18em;color:#7ce7ff}.r{margin-top:18px;padding:12px;border-radius:12px;background:#111920;color:#a8bac7;font-family:ui-monospace,monospace}.b{display:inline-block;margin-top:18px;padding:10px 16px;border-radius:10px;background:#eaf2f7;color:#061016;text-decoration:none;font-weight:700}</style></head><body><main class="c"><div class="k">TEAM DARK / BINARY VAULT</div><h1>Vault startup failed</h1><p>'.$safe.'</p><div class="r">Reference: '.$ref.'<br>Log: storage/logs/vault-error.log</div><a class="b" href="/dashboard">Back to dashboard</a></main></body></html>';
    exit;
};

register_shutdown_function(static function () use ($writeVaultLog, $renderFailure): void {
    $e = error_get_last();
    if (!$e || !in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }
    $writeVaultLog('Fatal '.$e['type'].' in '.basename((string)$e['file']).':'.(int)$e['line'].' - '.(string)$e['message']);
    if (!headers_sent()) {
        $renderFailure('A PHP fatal error occurred while loading Binary Vault.');
    }
});

try {
    $target = __DIR__.'/vault.php';
    if (!is_file($target) || !is_readable($target)) {
        throw new RuntimeException('public/vault.php is missing or unreadable on the server.');
    }
    require $target;
} catch (Throwable $e) {
    $writeVaultLog(get_class($e).' in '.basename($e->getFile()).':'.$e->getLine().' - '.$e->getMessage());

    $message = 'Binary Vault bootstrap failed.';
    if ($e instanceof RuntimeException && str_contains($e->getMessage(), 'missing or unreadable')) {
        $message = $e->getMessage();
    }
    $renderFailure($message);
}
