<?php
declare(strict_types=1);

use TeamDark\Panel\{Config,Database,Security,Crypto,LoaderAuthService};

ini_set('display_errors', '0');
ini_set('html_errors', '0');
error_reporting(E_ALL);
ob_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('Surrogate-Control: no-store');
header('Cloudflare-CDN-Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function teamdarkJson(array $payload): never
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    // Keep protocol-level failures HTTP 200 because the existing native
    // loader parses the JSON reason only for successful HTTP responses.
    http_response_code(200);
    echo json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    exit;
}

try {
    $root = dirname(__DIR__);

    foreach ([
        'Config',
        'Database',
        'Security',
        'Crypto',
        'LoaderAuthService',
    ] as $file) {
        require $root.'/app/'.$file.'.php';
    }

    Config::load($root);

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        teamdarkJson([
            'status'=>false,
            'reason'=>'Missing Parameters',
        ]);
    }

    $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($contentLength > 4096) {
        teamdarkJson([
            'status'=>false,
            'reason'=>'Invalid Request',
        ]);
    }

    $game = isset($_POST['game']) ? trim((string)$_POST['game']) : '';
    $userKey = isset($_POST['user_key']) ? trim((string)$_POST['user_key']) : '';
    $serial = isset($_POST['serial']) ? trim((string)$_POST['serial']) : '';

    if ($game === '' || $userKey === '' || $serial === '') {
        teamdarkJson([
            'status'=>false,
            'reason'=>'Missing Parameters',
        ]);
    }

    // Per-IP protection; exact auth/device checks remain transactional below.
    Security::rateLimit('teamdark-loader-connect', 300, 60);

    $result = LoaderAuthService::authenticate(
        $game,
        $userKey,
        $serial,
        Security::clientIp()
    );

    teamdarkJson($result);
} catch (Throwable $e) {
    error_log(
        'TeamDark /connect error: '
        .get_class($e)
        .' at '
        .basename($e->getFile())
        .':'
        .$e->getLine()
    );

    teamdarkJson([
        'status'=>false,
        'reason'=>'Server Error',
    ]);
}
