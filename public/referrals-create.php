<?php
declare(strict_types=1);

use TeamDark\Panel\{Auth,Config,Database,PanelControl,ReferralManager,Security};

$root = dirname(__DIR__);
foreach (['Config','Database','PanelControl','Security','Auth','ReferralManager'] as $file) {
    require_once $root.'/app/'.$file.'.php';
}

Config::load($root);
Security::enforceHttpsWeb();
Security::headers();
Security::startSession();

function referralCreateRedirect(string $path): never
{
    header('Location: '.$path, true, 303);
    exit;
}

function referralCreateFlash(string $type, string $message, ?string $copy = null): void
{
    $_SESSION['flash'] = [$type, $message, $copy, $copy ? 'Copy referral' : 'Copy'];
}

function referralCreateMessage(Throwable $e): string
{
    if ($e instanceof PDOException) {
        error_log('TeamDark referral creation database failure at '.basename($e->getFile()).':'.$e->getLine());
        return 'Could not create referral.';
    }
    $message = trim($e->getMessage());
    return $message === '' ? 'Could not create referral.' : substr($message, 0, 300);
}

try {
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $path = rawurldecode((string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/referrals/create'), PHP_URL_PATH) ?: '/referrals/create'));

    if (!in_array($path, ['/referrals/create','/referrals/create/'], true)) {
        http_response_code(404);
        exit;
    }
    if ($method !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        exit;
    }

    $actor = Auth::requireLogin();
    Auth::requireRole($actor, 'admin');
    Security::verifyCsrf($_POST['csrf'] ?? null);

    // Owner is intentionally unrestricted by the ordinary referral-create rate bucket.
    if (($actor['role'] ?? '') !== 'owner') {
        Security::rateLimit('referral-create-'.$actor['id'], 30, 3600);
    }

    $maxRegistrations = ($actor['role'] ?? '') === 'owner'
        ? (string)($_POST['max_registrations'] ?? '1')
        : '1';

    $invite = ReferralManager::create(
        $actor,
        trim((string)($_POST['role'] ?? 'user')),
        $_POST['app_ids'] ?? [],
        trim((string)($_POST['grant_balance'] ?? '0')),
        $maxRegistrations
    );

    referralCreateFlash(
        'ok',
        'Referral created: '.$invite['code']
            .' • Max registrations: '.number_format((int)$invite['max_registrations'])
            .' • App APIs: '.count($invite['app_ids'])
            .' • Balance: '.number_format((int)$invite['grant_balance']).' credits',
        (string)$invite['code']
    );
} catch (Throwable $e) {
    referralCreateFlash('err', referralCreateMessage($e));
}

referralCreateRedirect('/users');
