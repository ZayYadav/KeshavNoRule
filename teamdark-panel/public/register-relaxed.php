<?php
declare(strict_types=1);

use TeamDark\Panel\{Auth,Config,Database,PanelControl,ReferralManager,Security,View};

$root = dirname(__DIR__);
foreach (['Config','Database','Security','PanelControl','Auth','View','ReferralManager'] as $file) {
    require $root.'/app/'.$file.'.php';
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
    return (bool)preg_match('/^[a-z0-9_.-]{3,32}$/', $username);
}

function regReferralCode(): string
{
    return 'TD'.strtoupper(bin2hex(random_bytes(7)));
}

try {
    if (Auth::user()) regRedirect('/dashboard');

    $settings = PanelControl::settings();
    if (!$settings['panel_online']) {
        http_response_code(503);
        header('Retry-After: 300');
        View::page(
            'Maintenance',
            '<section class="auth"><div class="card"><div class="eyebrow">PANEL OFFLINE</div><h1>We will be back.</h1><p class="muted">'.View::e((string)$settings['message']).'</p><a class="ghost" href="/login">Owner sign in</a></div></section>'
        );
        exit;
    }

    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'GET') {
        $prefill = View::e((string)($_GET['ref'] ?? ''));
        $body = '<section class="auth"><div class="card">'
            .'<div class="tabs"><a href="/login">Login</a><a class="active" href="/register">Register</a></div>'
            .'<h1>Create account</h1><p class="muted">A valid referral code is required.</p>'
            .regTakeFlash()
            .'<form method="post" action="/register" class="stack">'.View::csrf()
            .'<div class="field"><label>Referral code</label><input name="referral" value="'.$prefill.'" required maxlength="40" autocomplete="off"></div>'
            .'<div class="field"><label>Name</label><input name="name" required minlength="2" maxlength="80" autocomplete="name" placeholder="Your name"></div>'
            .'<div class="field"><label>Username</label><input name="username" required minlength="3" maxlength="32" autocomplete="username"></div>'
            .'<div class="field"><label>Password</label><input type="password" name="password" required minlength="1" maxlength="200" autocomplete="new-password"><p class="hint">Any non-empty password up to 200 characters is accepted. No letter/number/symbol combination is required.</p></div>'
            .'<button class="primary">Create account</button></form></div></section>';
        View::page('Register', $body);
        exit;
    }

    if ($method !== 'POST') {
        http_response_code(405);
        exit;
    }

    Security::verifyCsrf($_POST['csrf'] ?? null);
    if (!$settings['registration_open']) {
        regFlash('err', 'New registrations are paused by the owner.');
        regRedirect('/register');
    }
    Security::rateLimit('register', 6, 3600);

    $name = trim((string)($_POST['name'] ?? ''));
    $username = strtolower(trim((string)($_POST['username'] ?? '')));
    $password = (string)($_POST['password'] ?? '');
    $ref = strtoupper(trim((string)($_POST['referral'] ?? '')));

    if (!regValidName($name)) {
        regFlash('err', 'Name must be between 2 and 80 characters.');
        regRedirect('/register?ref='.urlencode($ref));
    }
    if (!regValidUsername($username)) {
        regFlash('err', 'Username must be 3–32 chars: letters, numbers, dot, underscore or hyphen.');
        regRedirect('/register?ref='.urlencode($ref));
    }
    if (strlen($password) < 1 || strlen($password) > 200) {
        regFlash('err', 'Password must be between 1 and 200 characters.');
        regRedirect('/register?ref='.urlencode($ref));
    }

    $pdo = Database::pdo();
    $pdo->beginTransaction();

    try {
        $q = $pdo->prepare(
            "SELECT i.id,i.role,i.created_by,i.expires_at,u.role creator_role,u.status creator_status
             FROM referral_invites i JOIN users u ON u.id=i.created_by
             WHERE i.code=? AND i.status='pending' AND (i.expires_at IS NULL OR i.expires_at>NOW())
             LIMIT 1 FOR UPDATE"
        );
        $q->execute([$ref]);
        $invite = $q->fetch();

        if (!$invite || $invite['creator_status'] !== 'active' || !ReferralManager::creatorCanIssueRole((string)$invite['creator_role'], (string)$invite['role'])) {
            throw new RuntimeException('Invalid or already used referral code.');
        }
        if (!in_array($invite['role'], ['admin','reseller','user'], true)) {
            throw new RuntimeException('Invalid referral role.');
        }

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
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();

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
        'role'=>$invite['role'],
        'referral'=>$ref,
        'signup_bonus'=>$signup,
        'created_at'=>date('Y-m-d H:i:s'),
        'created_ts'=>time(),
    ];

    regRedirect('/register/success');
} catch (Throwable $e) {
    error_log('TeamDark registration error: '.get_class($e).' at '.basename($e->getFile()).':'.$e->getLine());
    regFlash('err', 'Registration request failed.');
    regRedirect('/register');
}
