<?php
declare(strict_types=1);

use TeamDark\Panel\{Auth,Config,Database,PanelControl,ReferralManager,Security,View};

$root = dirname(__DIR__);
foreach (['Config','Database','Security','PanelControl','Auth','View','ReferralManager'] as $file) {
    require_once $root.'/app/'.$file.'.php';
}

Config::load($root);
Security::enforceHttpsWeb();
Security::headers();
Security::startSession();

function regRedirect(string $path): never
{
    header('Location: '.$path, true, 303);
    exit;
}

function regFlash(string $type, string $message): void
{
    $_SESSION['flash'] = [$type, $message];
}

function regTakeFlash(): string
{
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    if (!is_array($f)) return '';
    return '<div data-flash role="status" class="alert '.(($f[0] ?? '') === 'ok' ? 'ok' : '').'">'.View::e((string)($f[1] ?? '')).'</div>';
}

function regSafeMessage(Throwable $e, string $fallback = 'Registration unavailable.'): string
{
    if ($e instanceof PDOException) {
        error_log('TeamDark registration database failure at '.basename($e->getFile()).':'.$e->getLine());
        return $fallback;
    }
    if ($e instanceof RuntimeException) {
        $message = trim($e->getMessage());
        return $message === '' ? $fallback : substr($message, 0, 300);
    }
    return $fallback;
}

function regValidName(string $name): bool
{
    $name = trim($name);
    $bytes = strlen($name);
    return $bytes >= 2
        && $bytes <= 80
        && !preg_match('/[\x00-\x1F\x7F]/u', $name);
}

function regValidUsername(string $username): bool
{
    $length = strlen($username);
    return $length >= 3
        && $length <= 64
        && (bool)preg_match('/^[A-Za-z0-9._-]+$/', $username);
}

function regValidPassword(string $password): bool
{
    $length = strlen($password);
    return $length >= 1
        && $length <= 200
        && !str_contains($password, "\0");
}

function regReferralCode(): string
{
    return 'TDUSR'.strtoupper(bin2hex(random_bytes(8)));
}

try {
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $path = rawurldecode((string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/register'), PHP_URL_PATH) ?: '/register'));

    if (!in_array($path, ['/register','/register/'], true)) {
        http_response_code(404);
        View::page('Not found', '<section class="auth"><div class="card"><h1>404</h1><p class="muted">Registration route not found.</p></div></section>');
        exit;
    }

    if (PanelControl::blocked(null)) {
        http_response_code(503);
        header('Retry-After: 300');
        View::page('Maintenance', '<section class="auth"><div class="card"><div class="eyebrow">PANEL OFFLINE</div><h1>We will be back.</h1><p class="muted">'.View::e((string)PanelControl::settings()['message']).'</p></div></section>');
        exit;
    }

    if ($method === 'GET') {
        $ref = trim((string)($_GET['ref'] ?? ''));
        $flash = regTakeFlash();
        $invite = null;

        if ($ref !== '') {
            try {
                $invite = ReferralManager::validateForRegistration($ref);
            } catch (Throwable $e) {
                $flash .= '<div class="alert">'.View::e(regSafeMessage($e, 'Referral validation is temporarily unavailable.')).'</div>';
            }
        }

        if (!$invite) {
            $body = '<section class="auth"><div class="card"><div class="eyebrow">INVITE ONLY</div><h1>Create account</h1>'
                .$flash
                .'<p class="muted">A valid one-time referral code is required to register.</p>'
                .'<form method="get" action="/register" class="stack"><div class="field"><label>Referral code</label><input name="ref" maxlength="80" required value="'.View::e($ref).'" placeholder="TD-REF-..."></div><button class="primary wide">Verify invite</button></form>'
                .'<p class="hint">Already registered? <a href="/login">Sign in</a>.</p></div></section>';
            View::page('Register', $body);
            exit;
        }

        $referralGrantPreview = (int)($invite['grant_balance'] ?? 0);
        $inviteApiPreview = '';
        foreach (($invite['app_apis'] ?? []) as $appApi) {
            if (!is_array($appApi)) continue;
            $apiName = trim((string)($appApi['name'] ?? ''));
            $apiEndpoint = trim((string)($appApi['endpoint'] ?? ''));
            if ($apiName === '' || $apiEndpoint === '') continue;
            $inviteApiPreview .= '<div class="system-preview"><span>'.View::e($apiName).'</span><code>'.View::e($apiEndpoint).'</code><button type="button" class="ghost compact" data-copy="'.View::e($apiEndpoint).'">Copy endpoint</button></div>';
        }
        $body = '<section class="auth"><div class="card"><div class="eyebrow">SECURE REGISTRATION</div><h1>Create account</h1>'
            .$flash
            .'<p class="muted">Invite verified for a '.View::e((string)$invite['role']).' account.'
            .($referralGrantPreview > 0 ? ' Includes '.number_format($referralGrantPreview).' starting credits.' : '').'</p>'
            .'<div class="registration-api-access"><div class="eyebrow">REFERRAL APP API</div><p class="muted">Registration will grant exactly the App API access selected by the referral creator.</p>'.$inviteApiPreview.'</div>'
            .'<form method="post" action="/register" class="stack" data-busy="Creating account…">'.View::csrf()
            .'<input type="hidden" name="referral" value="'.View::e($ref).'">'
            .'<div class="field"><label>Name</label><input name="name" minlength="2" maxlength="80" required autocomplete="name"></div>'
            .'<div class="field"><label>Username</label><input name="username" minlength="3" maxlength="64" pattern="[A-Za-z0-9._-]+" required autocomplete="username"></div>'
            .'<div class="field"><label>Password</label><input name="password" type="password" minlength="1" maxlength="200" required autocomplete="new-password"></div>'
            .'<button class="primary wide" type="submit">Create account</button></form>'
            .'<p class="hint">Registration activates after a short security delay.</p></div></section>';
        View::page('Register', $body);
        exit;
    }

    if ($method !== 'POST') {
        http_response_code(405);
        exit;
    }

    Security::verifyCsrf($_POST['csrf'] ?? null);
    if (!(bool)(PanelControl::settings()['registration_open'] ?? true)) {
        regFlash('err', 'New registrations are paused by the owner.');
        regRedirect('/register');
    }
    if (Security::ownerIpPolicyBlocked(null)) {
        http_response_code(403);
        regFlash('err', 'Registration is not available from this network.');
        regRedirect('/register');
    }
    Security::rateLimit('register-ip', 15, 3600);

    $ref = trim((string)($_POST['referral'] ?? ''));
    $name = trim((string)($_POST['name'] ?? ''));
    $username = strtolower(trim((string)($_POST['username'] ?? '')));
    $password = (string)($_POST['password'] ?? '');
    $pdo = null;

    try {
        if (!regValidName($name)) {
            throw new RuntimeException('Name must be 2-80 characters.');
        }
        if (!regValidUsername($username)) {
            throw new RuntimeException('Username must be 3-64 letters, numbers, dot, underscore or dash.');
        }
        if (!regValidPassword($password)) {
            throw new RuntimeException('Password must be between 1 and 200 characters.');
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        $invite = ReferralManager::lockForRegistration($pdo, $ref);

        $bonusesEnabled = (bool)Config::get('registration_bonuses_enabled', false);
        $signup = $bonusesEnabled ? (int)Config::get('signup_bonus') : 0;
        $bonus = $bonusesEnabled ? (int)Config::get('referrer_bonus') : 0;

        $pdo->prepare(
            "INSERT INTO users(name,username,password_hash,role,balance,referral_code,referred_by,created_by,login_not_before,status)
             VALUES(?,?,?,?,?,?,?,?,DATE_ADD(NOW(),INTERVAL 15 SECOND),'active')"
        )->execute([
            $name,
            $username,
            Security::passwordHash($password),
            $invite['role'],
            $signup,
            regReferralCode(),
            $invite['created_by'],
            $invite['created_by'],
        ]);

        $uid = (int)$pdo->lastInsertId();
        $grantedAppIds = ReferralManager::grantInviteAppsToUser(
            $pdo,
            (int)$invite['id'],
            $uid,
            (int)$invite['created_by']
        );
        $grantedAppMap = array_fill_keys($grantedAppIds, true);
        $registrationApps = array_values(array_filter(
            $invite['app_apis'] ?? [],
            static fn(array $app): bool => isset($grantedAppMap[(int)($app['id'] ?? 0)])
        ));
        if (count($registrationApps) !== count($grantedAppIds)) {
            throw new RuntimeException('Could not resolve the allotted App API details.');
        }
        $referralGrant = ReferralManager::grantInviteBalanceToUser($pdo, $invite, $uid);
        $pdo->prepare("UPDATE referral_invites SET status='used',used_by=?,used_at=NOW() WHERE id=? AND status='pending'")
            ->execute([$uid, $invite['id']]);

        if ($signup > 0) {
            $pdo->prepare('INSERT INTO balance_ledger(user_id,actor_user_id,amount,reason) VALUES(?,?,?,?)')
                ->execute([$uid, $invite['created_by'], $signup, 'Referral signup bonus']);
        }

        if ($bonus > 0 && $invite['creator_role'] === 'admin' && (bool)Config::get('admin_referral_bonus_enabled', false)) {
            $pdo->prepare('UPDATE users SET balance=balance+? WHERE id=?')->execute([$bonus, $invite['created_by']]);
            $pdo->prepare('INSERT INTO balance_ledger(user_id,actor_user_id,amount,reason) VALUES(?,?,?,?)')
                ->execute([$invite['created_by'], $uid, $bonus, 'Successful referral bonus']);
        }

        $pdo->commit();
        Security::audit($uid, 'registered', [
            'name'=>$name,
            'role'=>$invite['role'],
            'referred_by'=>(int)$invite['created_by'],
            'invite_id'=>(int)$invite['id'],
            'app_ids'=>$grantedAppIds,
            'referral_balance'=>$referralGrant,
        ]);
    } catch (Throwable $e) {
        if ($pdo instanceof \PDO && $pdo->inTransaction()) $pdo->rollBack();

        if ($e instanceof PDOException) {
            error_log('TeamDark registration database failure at '.basename($e->getFile()).':'.$e->getLine());
            $message = 'Registration failed.';
        } elseif ($e instanceof RuntimeException) {
            $message = substr($e->getMessage(), 0, 300);
        } else {
            $message = 'Registration failed.';
        }

        regFlash('err', $message);
        regRedirect('/register?ref='.urlencode($ref));
    }

    $_SESSION['registration_success'] = [
        'user_id'=>$uid,
        'name'=>$name,
        'username'=>$username,
        'role'=>(string)$invite['role'],
        'referral'=>$ref,
        'signup_bonus'=>$signup,
        'referral_balance'=>$referralGrant,
        'starting_balance'=>$signup + $referralGrant,
        'app_apis'=>$registrationApps,
        'created_at'=>date('Y-m-d H:i:s'),
        'created_ts'=>time(),
    ];
    regRedirect('/register/success');
} catch (Throwable $e) {
    error_log('TeamDark registration controller error: '.get_class($e).' at '.basename($e->getFile()).':'.$e->getLine());
    http_response_code(400);
    View::page('Registration unavailable', '<section class="auth"><div class="card"><h1>Registration unavailable</h1><div class="alert">'.View::e(regSafeMessage($e)).'</div><a class="btn" href="/login">Go back</a></div></section>');
}
