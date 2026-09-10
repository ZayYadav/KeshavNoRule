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

function teamdarkGateway(string $endpoint, bool $headOnly = false): never
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Robots-Tag: noindex, nofollow, noarchive');

    if ($headOnly) {
        exit;
    }

    $safeEndpoint = htmlspecialchars(
        $endpoint,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );

    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        .'<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">'
        .'<meta name="theme-color" content="#010204">'
        .'<meta name="color-scheme" content="dark">'
        .'<title>TEAM DARK • Secure API Gateway</title>'
        .'<link rel="stylesheet" href="/assets/connect-gateway.css?v=20260910-1">'
        .'</head><body>'
        .'<main class="gateway" aria-label="Team Dark secure API gateway">'
        .'<canvas id="gw-particles" data-gateway-particles aria-hidden="true"></canvas>'
        .'<div class="gw-grid" aria-hidden="true"></div>'
        .'<div class="gw-beam a" aria-hidden="true"></div>'
        .'<div class="gw-beam b" aria-hidden="true"></div>'
        .'<div class="gw-flare" aria-hidden="true"></div>'
        .'<div class="gw-rings" aria-hidden="true"><i></i><i></i><i></i></div>'
        .'<div class="gw-vignette" aria-hidden="true"></div>'
        .'<div class="gw-letterbox top" aria-hidden="true"></div>'
        .'<div class="gw-letterbox bottom" aria-hidden="true"></div>'
        .'<section class="gw-stage">'
        .'<div class="gw-kicker"><i></i><span>TEAM DARK // SECURE NETWORK</span><i></i></div>'
        .'<div class="gw-mark" aria-hidden="true"><b>TD</b></div>'
        .'<div class="gw-subkicker">API GATEWAY // CHANNEL VERIFIED</div>'
        .'<h1 class="gw-title">CONNECT</h1>'
        .'<p class="gw-copy">Encrypted license gateway for authorized Team Dark clients. Browser view is presentation-only; authenticated client traffic continues through the protected POST channel.</p>'
        .'<div class="gw-status">'
        .'<span>Gateway online</span><span>TLS protected</span><span>Auth required</span>'
        .'</div>'
        .'<div class="gw-card">'
        .'<div class="gw-endpoint"><div><small>Live gateway endpoint</small><code>'.$safeEndpoint.'</code></div>'
        .'<span class="gw-live"><i></i> Secure channel</span></div>'
        .'<div class="gw-meta">'
        .'<span><b>METHOD</b><strong>POST</strong></span>'
        .'<span><b>RESPONSE</b><strong>JSON</strong></span>'
        .'<span><b>TRANSPORT</b><strong>HTTPS / TLS</strong></span>'
        .'<span><b>ACCESS</b><strong>AUTHORIZED CLIENTS</strong></span>'
        .'</div></div>'
        .'<div class="gw-actions"><a href="/login">Panel login</a></div>'
        .'</section>'
        .'<div class="gw-corner">TEAM DARK CONTROL PLANE</div>'
        .'<div class="gw-time"><span>Gateway session</span><b data-gateway-time>00:00:000</b></div>'
        .'</main>'
        .'<script src="/assets/connect-gateway.js?v=20260910-1" defer></script>'
        .'</body></html>';
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
    Security::headers();

    // Preserve the exact existing Loader cache/header contract after shared headers.
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Surrogate-Control: no-store');
    header('Cloudflare-CDN-Cache-Control: no-store');

    $method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');

    if ($method === 'GET' || $method === 'HEAD') {
        $base = rtrim((string)Config::get('app_url', ''), '/');
        if ($base === '') {
            $host = preg_replace(
                '/[^A-Za-z0-9.\-:\[\]]/',
                '',
                (string)($_SERVER['HTTP_HOST'] ?? '')
            ) ?: '';
            $base = $host !== ''
                ? (Security::isHttpsRequest() ? 'https://' : 'http://').$host
                : '';
        }
        $endpoint = $base !== '' ? $base.'/connect' : '/connect';
        teamdarkGateway($endpoint, $method === 'HEAD');
    }

    if ($method !== 'POST') {
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
