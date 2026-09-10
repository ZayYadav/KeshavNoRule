<?php
declare(strict_types=1);

/**
 * TeamDark Panel production crash logger.
 *
 * This wrapper intentionally applies only to the human web UI / JSON panel API.
 * The native Loader /connect endpoint is routed directly to public/connect.php
 * and therefore remains unchanged.
 */

$root = dirname(__DIR__);
$logDir = $root . '/storage/logs';
$logFile = $logDir . '/panel-error.log';

error_reporting(E_ALL);
@ini_set('log_errors', '1');
@ini_set('display_errors', '0');

if (!is_dir($logDir)) {
    @mkdir($logDir, 0750, true);
}

if (is_dir($logDir) && is_writable($logDir)) {
    @ini_set('error_log', $logFile);
}

/**
 * Write one structured entry without exposing secrets in the browser.
 */
function teamDarkWriteCrashLog(string $type, string $message, string $file, int $line, string $trace = ''): void
{
    $requestId = defined('TEAMDARK_REQUEST_ID') ? TEAMDARK_REQUEST_ID : 'unknown';
    $method = (string)($_SERVER['REQUEST_METHOD'] ?? 'CLI');
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
    $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');

    $entry = sprintf(
        "[%s] [request:%s] [%s] %s: %s in %s:%d | uri=%s | ip=%s%s",
        date('Y-m-d H:i:s'),
        $requestId,
        $method,
        $type,
        $message,
        $file,
        $line,
        $uri,
        $remote,
        $trace !== '' ? "\nTRACE:\n" . $trace : ''
    );

    error_log($entry);
}

if (!defined('TEAMDARK_REQUEST_ID')) {
    try {
        define('TEAMDARK_REQUEST_ID', bin2hex(random_bytes(6)));
    } catch (Throwable) {
        define('TEAMDARK_REQUEST_ID', substr(hash('sha256', uniqid('', true)), 0, 12));
    }
}

set_exception_handler(static function (Throwable $e): void {
    teamDarkWriteCrashLog(
        get_class($e),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        $e->getTraceAsString()
    );

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
    }

    echo "TeamDark Panel Error\n";
    echo "Request ID: " . TEAMDARK_REQUEST_ID . "\n";
    echo "Detailed error saved to: storage/logs/panel-error.log\n";
});

register_shutdown_function(static function (): void {
    $last = error_get_last();
    if (!$last) {
        return;
    }

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array((int)$last['type'], $fatalTypes, true)) {
        return;
    }

    teamDarkWriteCrashLog(
        'PHP_FATAL_' . (int)$last['type'],
        (string)$last['message'],
        (string)$last['file'],
        (int)$last['line']
    );

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
    }
});

require __DIR__ . '/index.php';
