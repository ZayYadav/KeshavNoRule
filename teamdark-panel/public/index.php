<?php
declare(strict_types=1);

use TeamDark\Panel\{
    Auth,
    Config,
    Crypto,
    Database,
    KeyManager,
    LicenseService,
    ReferralManager,
    TelegramService,
    Security,
    View
};

use TeamDark\Panel\{PanelControl, OwnerConsole};
require_once dirname(__DIR__).'/app/OwnerConsole.php';

$root = dirname(__DIR__);

foreach ([
    'Config',
    'Database',
    'Security',
    'Crypto',
    'Auth',
    'View',
    'LoaderAuthService',
    'KeyManager',
    'LicenseService',
    'ReferralManager',
    'TelegramService',
] as $file) {
    require $root.'/app/'.$file.'.php';
}

Config::load($root);
Security::headers();
Security::startSession();

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$path = rawurldecode(
    (string)(
        parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH)
        ?: '/'
    )
);
$path = '/' . ltrim(preg_replace('#/+#', '/', $path) ?? '/', '/');

function redirectTo(string $path): never
{
    header('Location: '.$path, true, 303);
    exit;
}

function input(string $name, string $default = ''): string
{
    return trim((string)($_POST[$name] ?? $default));
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = [$type, $message];
}

function takeFlash(): string
{
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);

    if (!$f) {
        return '';
    }

    return '<div data-flash role="status" class="alert '.($f[0] === 'ok' ? 'ok' : '').'">'
        .View::e($f[1])
        .'</div>';
}

function jsonBody(): array
{
    $length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($length > 65536) {
        throw new RuntimeException('Invalid request body.');
    }

    $raw = file_get_contents('php://input') ?: '';
    if (strlen($raw) > 65536) {
        throw new RuntimeException('Invalid request body.');
    }

    if ($raw === '') {
        return [];
    }

    try {
        $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return [];
    }

    return is_array($data) ? $data : [];
}

function jsonOut(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, private, max-age=0');
    echo json_encode(
        $data,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    exit;
}

function safeMessage(Throwable $e, string $fallback = 'Request failed.'): string
{
    if ($e instanceof PDOException) {
        error_log(
            'TeamDark database request failure: '
            .get_class($e)
            .' at '
            .basename($e->getFile())
            .':'
            .$e->getLine()
        );
        return $fallback;
    }

    $message = trim($e->getMessage());
    return $message === '' ? $fallback : substr($message, 0, 300);
}

function roleRank(string $role): int
{
    return [
        'user'=>10,
        'reseller'=>20,
        'admin'=>30,
        'owner'=>40,
    ][$role] ?? 0;
}

function validUsername(string $username): bool
{
    return (bool)preg_match('/^[a-z0-9_.-]{3,32}$/', $username);
}

function validName(string $name): bool
{
    $name = trim($name);
    $bytes = strlen($name);
    return $bytes >= 2
        && $bytes <= 240
        && !preg_match('/[\x00-\x1F\x7F]/u', $name);
}

function validPassword(string $password): bool
{
    return strlen($password) >= 12
        && strlen($password) <= 200
        && preg_match('/[A-Za-z]/', $password)
        && preg_match('/\d/', $password)
        && preg_match('/[^A-Za-z0-9]/', $password);
}

function referralCode(): string
{
    return 'TD'.strtoupper(bin2hex(random_bytes(7)));
}

function ownerUnlimited(array $user): bool
{
    return ($user['role'] ?? '') === 'owner';
}

function balanceText(array $user): string
{
    return ownerUnlimited($user)
        ? '∞'
        : number_format((int)($user['balance'] ?? 0));
}

function parseUnsignedBalance(string $raw, bool $ownerRange): ?int
{
    $raw = trim($raw);

    if (!preg_match('/^\d{1,18}$/', $raw)) {
        return null;
    }

    $value = (int)$raw;

    if ($value < 0) {
        return null;
    }

    if (!$ownerRange && $value > 100000) {
        return null;
    }

    return $value;
}

function parseBalanceDelta(string $raw, bool $ownerRange): ?int
{
    $raw = trim($raw);

    if (!preg_match('/^-?\d{1,18}$/', $raw)) {
        return null;
    }

    $value = (int)$raw;

    if ($value === 0) {
        return null;
    }

    if (!$ownerRange && abs($value) > 100000) {
        return null;
    }

    return $value;
}

function ownerManagedUser(array $actor, int $targetId): array
{
    if (($actor['role'] ?? '') !== 'owner' || $targetId <= 0) {
        throw new RuntimeException('Owner access required.');
    }

    $q = Database::pdo()->prepare(
        'SELECT id,name,username,role,balance,telegram_chat_id,status,created_at
         FROM users WHERE id=? LIMIT 1'
    );
    $q->execute([$targetId]);
    $target = $q->fetch();

    if (
        !$target
        || (int)$target['id'] === (int)$actor['id']
        || $target['role'] === 'owner'
    ) {
        throw new RuntimeException('This account cannot be managed.');
    }

    return $target;
}

function humanDuration(int $seconds): string
{
    $seconds = max(3600, $seconds);
    $days = intdiv($seconds, 86400);
    $hours = intdiv($seconds % 86400, 3600);

    $parts = [];

    if ($days > 0) {
        $parts[] = $days.' day'.($days === 1 ? '' : 's');
    }

    if ($hours > 0) {
        $parts[] = $hours.' hour'.($hours === 1 ? '' : 's');
    }

    return $parts ? implode(' ', $parts) : '1 hour';
}

function bearerUser(): array
{
    $auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');

    if (!preg_match('/^Bearer\s+([A-Za-z0-9_-]{40,200})$/', $auth, $m)) {
        jsonOut(['ok'=>false, 'error'=>'Unauthorized'], 401);
    }

    $hash = hash('sha256', $m[1]);

    $q = Database::pdo()->prepare(
        "SELECT u.id,u.name,u.username,u.role,u.balance,u.status,t.id token_id
         FROM api_tokens t
         JOIN users u ON u.id=t.user_id
         WHERE t.token_hash=? AND t.expires_at>NOW()
         LIMIT 1"
    );
    $q->execute([$hash]);
    $u = $q->fetch();

    if (!$u || $u['status'] !== 'active') {
        jsonOut(['ok'=>false, 'error'=>'Unauthorized'], 401);
    }

    if (PanelControl::blocked($u)) jsonOut(['ok'=>false, 'error'=>'Panel under maintenance'], 503);

    Database::pdo()
        ->prepare('UPDATE api_tokens SET last_used_at=NOW() WHERE id=?')
        ->execute([$u['token_id']]);

    unset($u['status'], $u['token_id']);

    $u['balance_unlimited'] = $u['role'] === 'owner';
    if ($u['role'] === 'owner') {
        $u['balance'] = null;
    } else {
        $u['balance'] = (int)$u['balance'];
    }

    return $u;
}

try {
    if (!str_starts_with($path, '/api/') && !in_array($path, ['/login','/logout'], true)) {
        $sessionUser = Auth::user();
        if (PanelControl::blocked($sessionUser)) {
            http_response_code(503);
            header('Retry-After: 300');
            View::page('Maintenance', '<section class="auth"><div class="card"><div class="eyebrow">PANEL OFFLINE</div><h1>We will be back.</h1><p class="muted">'.View::e(PanelControl::settings()['message']).'</p><a class="ghost" href="/login">Owner sign in</a></div></section>');
            exit;
        }
    }
    // Legacy JSON panel-user authentication API.
    if ($path === '/api/v1/auth/login' && $method === 'POST') {
        Security::rateLimit('api-login', 10, 600);

        $b = jsonBody();
        $username = strtolower(trim((string)($b['username'] ?? '')));
        $password = (string)($b['password'] ?? '');

        if ($username !== '') {
            Security::rateLimit(
                'api-login-account',
                80,
                900,
                $username
            );
        }

        $q = Database::pdo()->prepare(
            'SELECT * FROM users WHERE username=? LIMIT 1'
        );
        $q->execute([$username]);
        $u = $q->fetch();

        $dummy = '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';

        $ok = $u
            && $u['status'] === 'active'
            && password_verify($password, $u['password_hash']);

        if (!$u) {
            password_verify($password, $dummy);
        }

        if (!$ok) {
            Security::audit(
                $u ? (int)$u['id'] : null,
                'api_login_failed'
            );
            jsonOut(['ok'=>false, 'error'=>'Invalid credentials'], 401);
        }

        if ($username !== '') {
            Security::clearRateLimit('api-login-account', $username);
        }

        $token = rtrim(
            strtr(base64_encode(random_bytes(48)), '+/', '-_'),
            '='
        );
        if (PanelControl::blocked($u)) jsonOut(['ok'=>false, 'error'=>'Panel under maintenance'], 503);
        $hash = hash('sha256', $token);
        $ttl = (int)Config::get('token_ttl');

        $expiresAt = (new DateTimeImmutable())
            ->add(new DateInterval('PT'.$ttl.'S'))
            ->format('Y-m-d H:i:s');

        Database::pdo()
            ->prepare('DELETE FROM api_tokens WHERE expires_at<=NOW()')
            ->execute();

        Database::pdo()
            ->prepare(
                'INSERT INTO api_tokens(user_id,token_hash,expires_at) VALUES(?,?,?)'
            )
            ->execute([$u['id'], $hash, $expiresAt]);

        Security::audit((int)$u['id'], 'api_login_success');

        jsonOut([
            'ok'=>true,
            'token'=>$token,
            'token_type'=>'Bearer',
            'expires_in'=>$ttl,
            'user'=>[
                'id'=>(int)$u['id'],
                'name'=>$u['name'] ?: $u['username'],
                'username'=>$u['username'],
                'role'=>$u['role'],
                'balance'=>$u['role'] === 'owner'
                    ? null
                    : (int)$u['balance'],
                'balance_unlimited'=>$u['role'] === 'owner',
            ],
        ]);
    }

    if ($path === '/api/v1/me' && $method === 'GET') {
        jsonOut([
            'ok'=>true,
            'user'=>bearerUser(),
        ]);
    }

    if ($path === '/api/v1/licenses' && $method === 'GET') {
        $u = bearerUser();
        $rows = KeyManager::visibleKeys($u, 'all');
        $out = [];

        foreach ($rows as $row) {
            $out[] = [
                'id'=>(int)$row['id'],
                'key'=>Crypto::decrypt(
                    $row['key_cipher'],
                    $row['key_iv'],
                    $row['key_tag']
                ),
                'game'=>$row['game'],
                'owner'=>$row['owner_name'],
                'label'=>$row['label'],
                'status'=>$row['status'],
                'duration_seconds'=>(int)$row['duration_seconds'],
                'unlimited_expiry'=>(bool)$row['unlimited_expiry'],
                'activated_at'=>$row['activated_at'],
                'expires_at'=>(bool)$row['unlimited_expiry']
                    ? 'UNLIMITED'
                    : $row['expires_at'],
                'last_used_at'=>$row['last_used_at'],
                'max_devices'=>(bool)$row['unlimited_devices']
                    ? null
                    : (int)$row['max_devices'],
                'unlimited_devices'=>(bool)$row['unlimited_devices'],
                'used_devices'=>(int)$row['device_count'],
                'created_at'=>$row['created_at'],
            ];
        }

        jsonOut([
            'ok'=>true,
            'licenses'=>$out,
        ]);
    }

    // Backward-compatible JSON validation API retained for panel integrations.
    if (
        ($path === '/api/v1/license/activate'
            || $path === '/api/v1/license/validate')
        && $method === 'POST'
    ) {
        Security::rateLimit('license-json-validate', 120, 3600);

        $b = jsonBody();

        jsonOut(
            LicenseService::activate(
                (string)($b['key'] ?? ''),
                (string)($b['device_id'] ?? ''),
                (string)($b['device_label'] ?? '')
            )
        );
    }

    if ($path === '/' && $method === 'GET') {
        redirectTo(Auth::user() ? '/dashboard' : '/login');
    }

    if ($path === '/login' && $method === 'GET') {
        if (Auth::user()) {
            redirectTo('/dashboard');
        }

        $body = '<section class="auth"><div class="card">'
            .'<div class="tabs">'
            .'<a class="active" href="/login">Login</a>'
            .'<a href="/register">Register</a>'
            .'</div>'
            .'<h1>Welcome back</h1>'
            .'<p class="muted">Secure access to TeamDark control panel.</p>'
            .takeFlash()
            .'<form method="post" action="/login" class="stack">'
            .View::csrf()
            .'<div class="field"><label>Username</label>'
            .'<input name="username" autocomplete="username" required maxlength="32"></div>'
            .'<div class="field"><label>Password</label>'
            .'<input type="password" name="password" autocomplete="current-password" required maxlength="200"></div>'
            .'<button class="primary">Sign in</button>'
            .'</form></div></section>';

        View::page('Login', $body);
        exit;
    }

    if ($path === '/login' && $method === 'POST') {
        Security::verifyCsrf($_POST['csrf'] ?? null);

        if (!Auth::login(
            input('username'),
            (string)($_POST['password'] ?? '')
        )) {
            flash('err', 'Invalid username or password.');
            redirectTo('/login');
        }

        redirectTo('/dashboard');
    }

    if ($path === '/logout' && $method === 'POST') {
        Security::verifyCsrf($_POST['csrf'] ?? null);
        Auth::logout();
        redirectTo('/login');
    }

    if ($path === '/register' && $method === 'GET') {
        if (Auth::user()) {
            redirectTo('/dashboard');
        }

        $prefill = View::e((string)($_GET['ref'] ?? ''));

        $body = '<section class="auth"><div class="card">'
            .'<div class="tabs">'
            .'<a href="/login">Login</a>'
            .'<a class="active" href="/register">Register</a>'
            .'</div>'
            .'<h1>Create account</h1>'
            .'<p class="muted">A valid referral code is required.</p>'
            .takeFlash()
            .'<form method="post" action="/register" class="stack">'
            .View::csrf()
            .'<div class="field"><label>Referral code</label>'
            .'<input name="referral" value="'.$prefill.'" required maxlength="40" autocomplete="off"></div>'
            .'<div class="field"><label>Name</label>'
            .'<input name="name" required minlength="2" maxlength="80" autocomplete="name" placeholder="Your name"></div>'
            .'<div class="field"><label>Username</label>'
            .'<input name="username" required minlength="3" maxlength="32" autocomplete="username"></div>'
            .'<div class="field"><label>Password</label>'
            .'<input type="password" name="password" required minlength="12" maxlength="200" autocomplete="new-password"></div>'
            .'<button class="primary">Create account</button>'
            .'</form></div></section>';

        View::page('Register', $body);
        exit;
    }

    if ($path === '/register' && $method === 'POST') {
        Security::verifyCsrf($_POST['csrf'] ?? null);
        if (!PanelControl::settings()['registration_open']) {
            flash('err', 'New registrations are paused by the owner.');
            redirectTo('/register');
        }
        Security::rateLimit('register', 6, 3600);

        $name = input('name');
        $username = strtolower(input('username'));
        $password = (string)($_POST['password'] ?? '');
        $ref = strtoupper(input('referral'));

        if (!validName($name)) {
            flash('err', 'Name must be between 2 and 80 characters.');
            redirectTo('/register?ref='.urlencode($ref));
        }

        if (!validUsername($username)) {
            flash(
                'err',
                'Username must be 3–32 chars: letters, numbers, dot, underscore or hyphen.'
            );
            redirectTo('/register?ref='.urlencode($ref));
        }

        if (!validPassword($password)) {
            flash(
                'err',
                'Password must be 12+ characters with a letter, number and symbol.'
            );
            redirectTo('/register?ref='.urlencode($ref));
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $q = $pdo->prepare(
                "SELECT i.id,i.role,i.created_by,u.role creator_role,u.status creator_status
                 FROM referral_invites i
                 JOIN users u ON u.id=i.created_by
                 WHERE i.code=? AND i.status='pending'
                 LIMIT 1
                 FOR UPDATE"
            );
            $q->execute([$ref]);
            $invite = $q->fetch();

            if (!$invite || $invite['creator_status'] !== 'active') {
                throw new RuntimeException('Invalid or already used referral code.');
            }

            if (!in_array($invite['role'], ['admin','reseller','user'], true)) {
                throw new RuntimeException('Invalid referral role.');
            }

            $signup = (int)Config::get('signup_bonus');
            $bonus = (int)Config::get('referrer_bonus');

            $pdo->prepare(
                "INSERT INTO users(
                    name,username,password_hash,role,balance,referral_code,
                    referred_by,created_by,status
                 ) VALUES(?,?,?,?,?,?,?,?,'active')"
            )->execute([
                $name,
                $username,
                Security::passwordHash($password),
                $invite['role'],
                $signup,
                referralCode(),
                $invite['created_by'],
                $invite['created_by'],
            ]);

            $uid = (int)$pdo->lastInsertId();

            $pdo->prepare(
                "UPDATE referral_invites
                 SET status='used',used_by=?,used_at=NOW()
                 WHERE id=? AND status='pending'"
            )->execute([$uid, $invite['id']]);

            if ($signup > 0) {
                $pdo->prepare(
                    'INSERT INTO balance_ledger(user_id,actor_user_id,amount,reason) VALUES(?,?,?,?)'
                )->execute([
                    $uid,
                    $invite['created_by'],
                    $signup,
                    'Referral signup bonus',
                ]);
            }

            if ($bonus > 0 && $invite['creator_role'] !== 'owner') {
                $pdo->prepare(
                    'UPDATE users SET balance=balance+? WHERE id=?'
                )->execute([
                    $bonus,
                    $invite['created_by'],
                ]);

                $pdo->prepare(
                    'INSERT INTO balance_ledger(user_id,actor_user_id,amount,reason) VALUES(?,?,?,?)'
                )->execute([
                    $invite['created_by'],
                    $uid,
                    $bonus,
                    'Successful referral bonus',
                ]);
            }

            $pdo->commit();

            Security::audit($uid, 'registered', [
                'name'=>$name,
                'role'=>$invite['role'],
                'referred_by'=>(int)$invite['created_by'],
                'invite_id'=>(int)$invite['id'],
            ]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $message = $e instanceof RuntimeException
                ? $e->getMessage()
                : (
                    $e instanceof PDOException && $e->getCode() === '23000'
                        ? 'Username already exists.'
                        : 'Registration failed.'
                );

            flash('err', $message);
            redirectTo('/register?ref='.urlencode($ref));
        }

        flash('ok', 'Account created. You can sign in now.');
        redirectTo('/login');
    }

    $user = Auth::requireLogin();

    if (PanelControl::blocked($user)) redirectTo('/');
    if ($method === 'POST') {
        Security::verifyCsrf($_POST['csrf'] ?? null);
        Security::audit((int)$user['id'], 'action_requested', [
            'path'=>$path,
            'target_id'=>(int)($_POST['user_id'] ?? 0),
            'license_id'=>(int)($_POST['key_id'] ?? 0),
        ]);
    }
    if ($method === 'GET' && in_array($path, ['/dashboard','/keys','/keys/expired','/keys/devices','/users','/telegram-users','/activity','/owner/users','/owner/settings'], true)) {
        Security::audit((int)$user['id'], 'page_viewed', ['path'=>$path]);
    }
    if ($path === '/owner/settings' && $method === 'POST') {
        Auth::requireRole($user, 'owner');
        Security::verifyCsrf($_POST['csrf'] ?? null);
        try {
            PanelControl::save($user, $_POST);
            flash('ok', 'Server controls updated.');
        } catch (Throwable $e) {
            flash('err', safeMessage($e));
        }
        redirectTo('/owner/settings');
    }
    if ($path === '/owner/settings' && $method === 'GET') {
        OwnerConsole::settings($user, takeFlash());
        exit;
    }
    if ($path === '/owner/users' && $method === 'GET') {
        OwnerConsole::users($user, $_GET);
        exit;
    }

    if ($path === '/dashboard' && $method === 'GET') {
        KeyManager::expireDue();

        $pdo = Database::pdo();
        $ownerPulse = '';

        if ($user['role'] === 'owner') {
            $users = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
            $keys = (int)$pdo->query(
                "SELECT COUNT(*) FROM license_keys WHERE status<>'expired'"
            )->fetchColumn();
            $expired = (int)$pdo->query(
                "SELECT COUNT(*) FROM license_keys WHERE status='expired'"
            )->fetchColumn();

            $activeUsers = (int)$pdo->query(
                "SELECT COUNT(*) FROM users WHERE status='active'"
            )->fetchColumn();
            $disabledUsers = (int)$pdo->query(
                "SELECT COUNT(*) FROM users WHERE status='disabled'"
            )->fetchColumn();
            $newUsers = (int)$pdo->query(
                "SELECT COUNT(*) FROM users WHERE created_at>=DATE_SUB(NOW(),INTERVAL 7 DAY)"
            )->fetchColumn();
            $pendingInvites = (int)$pdo->query(
                "SELECT COUNT(*) FROM referral_invites WHERE status='pending'"
            )->fetchColumn();

            $ownerPulse = '<div class="card"><div class="toolbar"><div>'
                .'<div class="eyebrow">OWNER COMMAND CENTER</div><h3>Control the entire network</h3></div>'
                .'<span class="status-chip status-active">LIVE</span></div>'
                .'<div class="owner-command-grid">'
                .'<a class="owner-command" href="/users"><span>'.$activeUsers.'</span><div><strong>Active users</strong><small>'.$disabledUsers.' disabled</small></div></a>'
                .'<a class="owner-command" href="/users"><span>'.$newUsers.'</span><div><strong>New this week</strong><small>'.$pendingInvites.' invites pending</small></div></a>'
                .'<a class="owner-command" href="/activity"><span>↗</span><div><strong>All activity</strong><small>Browse retained history</small></div></a>'
                .'<a class="owner-command" href="/owner/users"><span>◎</span><div><strong>User insights</strong><small>Keys, credits and per-user history</small></div></a>'
                .'<a class="owner-command" href="/owner/settings"><span>⏻</span><div><strong>Server controls</strong><small>Panel access and availability</small></div></a>'
                .'</div></div>';
        } else {
            $q = $pdo->prepare('SELECT COUNT(*) FROM users WHERE referred_by=?');
            $q->execute([$user['id']]);
            $users = (int)$q->fetchColumn();
            $keys = count(KeyManager::visibleKeys($user, 'current'));
            $expired = count(KeyManager::visibleKeys($user, 'expired'));
        }

        $displayName = trim((string)($user['name'] ?? '')) ?: $user['username'];
        $referralCard = in_array($user['role'], ['owner','admin'], true)
            ? '<div class="card half spotlight"><div class="eyebrow">INVITE CONTROL</div>'
                .'<h3>Create registration referrals</h3>'
                .'<p class="muted">Owner can issue Admin, Reseller and User invites. Admin can issue User invites only.</p>'
                .'<a class="btn" href="/users">Open referrals</a></div>'
            : '<div class="card half"><div class="eyebrow">ACCOUNT</div>'
                .'<h3>'.View::e($displayName).'</h3>'
                .'<p class="muted">Your account cannot create referral codes.</p></div>';


        $telegramInfo = TelegramService::linkInfo($user);
        $linkState = $_SESSION['telegram_link_code'] ?? null;

        if (
            is_array($linkState)
            && (int)($linkState['expires_ts'] ?? 0) <= time()
        ) {
            unset($_SESSION['telegram_link_code']);
            $linkState = null;
        }

        if ($telegramInfo) {
            $telegramCard = '<div class="card half telegram-card">'
                .'<div class="eyebrow">TELEGRAM LINK</div><h3>Verified & linked</h3>'
                .'<p class="muted">Chat ID: <span class="key">'.View::e($telegramInfo['chat_id']).'</span><br>'
                .'Telegram: '.View::e(TelegramService::displayName($telegramInfo))
                .($telegramInfo['username'] ? ' • @'.View::e($telegramInfo['username']) : '')
                .'</p>'
                .'<form method="post" action="/telegram/unlink" class="inline">'
                .View::csrf()
                .'<button class="ghost danger">Unlink Telegram</button></form>'
                .'</div>';
        } else {
            $verify = '';

            if (is_array($linkState)) {
                $verify = '<div class="verify-box"><div class="eyebrow">15 MIN VERIFY CODE</div>'
                    .'<div class="key verify-code">'.View::e($linkState['code']).'</div>'
                    .'<p class="muted">Open the bot from Chat ID '.View::e($linkState['chat_id'])
                    .' and send <span class="key">/link '.View::e($linkState['code']).'</span></p></div>';
            }

            $telegramCard = '<div class="card half telegram-card spotlight">'
                .'<div class="eyebrow">TELEGRAM SECURITY</div><h3>Link your Telegram</h3>'
                .'<p class="muted">Enter your private Telegram Chat ID. Linking completes only after the same Telegram account verifies the one-time code in the bot.</p>'
                .'<form method="post" action="/telegram/link/start" class="stack">'
                .View::csrf()
                .'<div class="field"><label>Telegram Chat ID</label>'
                .'<input name="chat_id" inputmode="numeric" pattern="[0-9]{5,19}" maxlength="19" required placeholder="Example: 1234567890"></div>'
                .'<button class="primary">Generate verification code</button>'
                .'</form>'.$verify.'</div>';
        }

        $body = '<section class="hero hero-dashboard">'
            .'<div><span class="tag">'.View::e(strtoupper($user['role'])).'</span>'
            .'<h1>Hello, '.View::e($displayName).'</h1>'
            .'<p class="muted">Team Dark license control • smooth, secure and first-use activated.</p></div>'
            .'</section>'
            .takeFlash()
            .'<div class="grid stats-grid">'
            .'<div class="card quarter metric"><div class="eyebrow">BALANCE</div>'
            .'<div class="stat">'.View::e(balanceText($user)).'</div></div>'
            .'<div class="card quarter metric"><div class="eyebrow">LIVE KEYS</div>'
            .'<div class="stat">'.$keys.'</div></div>'
            .'<div class="card quarter metric"><div class="eyebrow">EXPIRED</div>'
            .'<div class="stat">'.$expired.'</div></div>'
            .'<div class="card quarter metric"><div class="eyebrow">REFERRED</div>'
            .'<div class="stat">'.$users.'</div></div>'
            .$ownerPulse
            .$referralCard
            .$telegramCard
            .'<div class="card half"><div class="eyebrow">PROFILE</div><h3>Account</h3>'
            .'<p class="muted">Username: '.View::e($user['username']).'<br>'
            .'Role: '.View::e($user['role']).'<br>'
            .'Balance policy: '.(ownerUnlimited($user) ? 'Unlimited' : 'Credit based').'<br>'
            .'Created: '.View::e($user['created_at']).'</p></div></div>';

        View::page('Dashboard', $body, $user);
        exit;
    }


    if ($path === '/telegram/link/start' && $method === 'POST') {
        Security::verifyCsrf($_POST['csrf'] ?? null);
        Security::rateLimit('telegram-link-start-'.$user['id'], 8, 3600);

        try {
            $challenge = TelegramService::createLinkChallenge(
                $user,
                input('chat_id')
            );

            $_SESSION['telegram_link_code'] = [
                'code'=>$challenge['code'],
                'chat_id'=>$challenge['chat_id'],
                'expires_ts'=>time() + 900,
            ];

            flash(
                'ok',
                'Verification code created. Send /link '
                .$challenge['code']
                .' to the Team Dark bot from that exact Chat ID.'
            );
        } catch (Throwable $e) {
            flash('err', safeMessage($e));
        }

        redirectTo('/dashboard');
    }

    if ($path === '/telegram/unlink' && $method === 'POST') {
        Security::verifyCsrf($_POST['csrf'] ?? null);

        try {
            TelegramService::unlink($user);
            unset($_SESSION['telegram_link_code']);
            flash('ok', 'Telegram account unlinked.');
        } catch (Throwable $e) {
            flash('err', safeMessage($e));
        }

        redirectTo('/dashboard');
    }

    if (($path === '/keys' || $path === '/keys/expired') && $method === 'GET') {
        $filter = $path === '/keys/expired' ? 'expired' : 'current';
        $rows = KeyManager::visibleKeys($user, $filter);

        $deviceOptions = '';
        foreach (KeyManager::DEVICE_LIMITS as $limit) {
            $selected = $limit === 10 ? ' selected' : '';
            $deviceOptions .= '<option value="'.$limit.'"'.$selected.'>'
                .$limit.' devices</option>';
        }
        $deviceOptions .= '<option value="unlimited">Unlimited devices</option>';

        $dayOptions = '';
        foreach ([1,3,7,15,30,60,90,180,365,730,3650] as $day) {
            $selected = $day === 30 ? ' selected' : '';
            $dayOptions .= '<option value="'.$day.'"'.$selected.'>'
                .$day.' day'.($day === 1 ? '' : 's').'</option>';
        }

        $pricing = ownerUnlimited($user)
            ? 'Owner generation cost: 0 credits.'
            : 'Timed cost: '.(int)Config::get('key_cost')
                .' credit(s) per day. Unlimited validity: '
                .(int)Config::get('unlimited_key_cost').' credits.';

        $create = $filter === 'current'
            ? '<div class="modal-backdrop" id="key-generator" data-modal="key-generator" aria-hidden="true">'
                .'<div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="key-generator-title">'
                .'<div class="toolbar"><div><div class="eyebrow">TEAM DARK</div><h3 id="key-generator-title">Generate Key</h3></div>'
                .'<a class="icon-btn" data-close-modal href="/keys" aria-label="Close">×</a></div>'
                .'<p class="muted">The key is automatically assigned to your own account.</p>'
                .'<form method="post" action="/keys/create" class="stack" data-action="Generate key" data-confirm="Generate this key with the selected validity and device limit?" data-busy="Generating secure key…">'
                .View::csrf()
                .'<div class="field"><label>Custom key <span class="optional">optional</span></label>'
                .'<input name="custom_key" maxlength="80" placeholder="Team-Dark-MyVIPKey" autocomplete="off"></div>'
                .'<div class="field"><label>Label <span class="optional">optional</span></label>'
                .'<input name="label" maxlength="100" placeholder="Customer / plan note"></div>'
                .'<div class="form-row">'
                .'<div class="field"><label>Validity</label><select name="duration_days">'.$dayOptions.'</select></div>'
                .'<div class="field"><label>Maximum devices</label><select name="max_devices">'.$deviceOptions.'</select></div>'
                .'</div>'
                .'<label class="checkline"><input type="checkbox" name="unlimited_expiry" value="1" data-unlimited-toggle> Unlimited validity</label>'
                .'<button type="submit" class="primary wide" data-submit-label="Generating…">Generate key</button>'
                .'</form>'
                .'<p class="hint">'.$pricing.' Auto format: Team-Dark-XXXXXXXXX.</p>'
                .'</div></div>'
            : '';

        $cards = '';

        foreach ($rows as $row) {
            try {
                $plain = Crypto::decrypt(
                    $row['key_cipher'],
                    $row['key_iv'],
                    $row['key_tag']
                );
            } catch (Throwable) {
                $plain = '[unavailable]';
            }

            $statusText = $row['status'] === 'disabled'
                ? 'BLOCKED'
                : strtoupper((string)$row['status']);

            $expiry = (bool)$row['unlimited_expiry']
                ? 'Unlimited'
                : ($row['expires_at'] ?: 'Starts on first use');

            $deviceText = (bool)$row['unlimited_devices']
                ? (int)$row['device_count'].' / ∞ devices'
                : (int)$row['device_count'].' / '.(int)$row['max_devices'].' devices';

            $actions = '<button type="button" class="ghost compact" data-copy="'.View::e($plain).'">Copy</button>';

            if ($row['status'] === 'disabled') {
                $actions .= '<form method="post" action="/keys/action" class="inline">'
                    .View::csrf()
                    .'<input type="hidden" name="key_id" value="'.(int)$row['id'].'">'
                    .'<input type="hidden" name="action" value="enable">'
                    .'<button class="ghost compact">Unblock</button></form>';
            } elseif (in_array($row['status'], ['unused','active'], true)) {
                $actions .= '<form method="post" action="/keys/action" class="inline">'
                    .View::csrf()
                    .'<input type="hidden" name="key_id" value="'.(int)$row['id'].'">'
                    .'<input type="hidden" name="action" value="disable">'
                    .'<button class="ghost compact">Block</button></form>';
            }

            $actions .= '<form method="post" action="/keys/devices/reset" class="inline">'
                .View::csrf()
                .'<input type="hidden" name="key_id" value="'.(int)$row['id'].'">'
                .'<input type="hidden" name="quick" value="1">'
                .'<button class="ghost compact">Reset</button></form>';

            $canDelete = $user['role'] === 'owner'
                || (int)$row['owner_user_id'] === (int)$user['id'];

            if ($canDelete) {
                $actions .= '<form method="post" action="/keys/action" class="inline">'
                    .View::csrf()
                    .'<input type="hidden" name="key_id" value="'.(int)$row['id'].'">'
                    .'<input type="hidden" name="action" value="delete">'
                    .'<button class="ghost compact danger" data-confirm="Delete this key permanently?">Delete</button></form>';
            }

            $label = trim((string)$row['label']);
            $labelHtml = $label !== ''
                ? '<span class="key-label">'.View::e($label).'</span>'
                : '';

            $cards .= '<article class="key-card">'
                .'<div class="key-main"><div class="key-line">'
                .'<span class="key">'.View::e($plain).'</span>'
                .'<span class="status-chip status-'.View::e($row['status']).'">'.View::e($statusText).'</span>'
                .'</div>'
                .'<div class="key-meta"><span>'.View::e($expiry).'</span><span>'.View::e($deviceText).'</span>'.$labelHtml.'</div></div>'
                .'<div class="key-actions">'.$actions.'</div>'
                .'</article>';
        }

        if ($cards === '') {
            $cards = '<div class="empty-state"><div class="empty-orb">TD</div><h3>No keys here</h3>'
                .'<p class="muted">Generate your first Team Dark key from the button above.</p></div>';
        }

        $body = '<section class="hero keys-hero"><div>'
            .'<div class="eyebrow">LICENSE VAULT</div>'
            .'<h1>'.($filter === 'expired' ? 'Expired Keys' : 'Keys').'</h1>'
            .'<p class="muted">Self-owned keys with one-tap block, reset and delete controls.</p></div>'
            .($filter === 'current'
                ? '<a class="primary generate-btn" href="#key-generator" data-open-modal="key-generator" role="button">+ Generate Key</a>'
                : '')
            .'</section>'
            .takeFlash()
            .'<div class="tabs key-tabs">'
            .'<a '.($filter === 'current' ? 'class="active"' : '').' href="/keys">Current</a>'
            .'<a '.($filter === 'expired' ? 'class="active"' : '').' href="/keys/expired">Expired</a>'
            .'</div>'
            .'<section class="key-grid">'.$cards.'</section>'
            .$create;

        View::page(
            $filter === 'expired' ? 'Expired Keys' : 'Keys',
            $body,
            $user
        );
        exit;
    }

    if ($path === '/keys/create' && $method === 'POST') {
        Security::verifyCsrf($_POST['csrf'] ?? null);
        Security::rateLimit('key-create-'.$user['id'], 40, 3600);

        try {
            $allowedDays = [1,3,7,15,30,60,90,180,365,730,3650];
            $days = (int)($_POST['duration_days'] ?? 30);

            if (!in_array($days, $allowedDays, true)) {
                throw new RuntimeException('Invalid validity selection.');
            }

            $unlimitedExpiry = isset($_POST['unlimited_expiry'])
                && $_POST['unlimited_expiry'] === '1';

            $deviceRaw = input('max_devices', '10');
            $unlimitedDevices = $deviceRaw === 'unlimited';
            $maxDevices = $unlimitedDevices ? 1 : (int)$deviceRaw;

            $created = KeyManager::create(
                $user,
                input('label'),
                $days * 86400,
                $unlimitedExpiry,
                $maxDevices,
                $unlimitedDevices,
                input('custom_key')
            );

            flash(
                'ok',
                'Generated: '.$created['key'].' • Cost: '.$created['cost'].' credit(s).'
            );
        } catch (Throwable $e) {
            flash('err', safeMessage($e));
        }

        redirectTo('/keys');
    }

    if ($path === '/keys/action' && $method === 'POST') {
        Security::verifyCsrf($_POST['csrf'] ?? null);

        try {
            KeyManager::action(
                $user,
                (int)($_POST['key_id'] ?? 0),
                input('action')
            );

            flash('ok', 'Key updated.');
        } catch (Throwable $e) {
            flash('err', safeMessage($e));
        }

        redirectTo('/keys');
    }

    if ($path === '/keys/devices' && $method === 'GET') {
        $keyId = (int)($_GET['id'] ?? 0);

        try {
            $data = KeyManager::devices($user, $keyId);
            $key = $data['key'];
            $devices = $data['devices'];

            try {
                $plain = Crypto::decrypt(
                    $key['key_cipher'],
                    $key['key_iv'],
                    $key['key_tag']
                );
            } catch (Throwable) {
                $plain = '[unavailable]';
            }

            $rows = '';

            foreach ($devices as $device) {
                $reset = '';

                if ($data['can_manage'] && (int)$device['active'] === 1) {
                    $reset = '<form method="post" action="/keys/devices/reset" class="inline">'
                        .View::csrf()
                        .'<input type="hidden" name="key_id" value="'.$keyId.'">'
                        .'<input type="hidden" name="device_id" value="'.(int)$device['id'].'">'
                        .'<button class="ghost danger">Reset</button>'
                        .'</form>';
                }

                $rows .= '<tr>'
                    .'<td class="key">'.View::e($device['serial']).'</td>'
                    .'<td>'.View::e($device['first_seen_at']).'</td>'
                    .'<td>'.View::e($device['last_seen_at']).'</td>'
                    .'<td>'.View::e($device['ip_address']).'</td>'
                    .'<td><span class="tag">'.((int)$device['active'] === 1 ? 'Active' : 'Reset').'</span></td>'
                    .'<td>'.$reset.'</td>'
                    .'</tr>';
            }

            if ($rows === '') {
                $rows = '<tr><td colspan="6" class="muted">No devices have used this key yet.</td></tr>';
            }

            $resetAll = $data['can_manage']
                ? '<form method="post" action="/keys/devices/reset" class="inline">'
                    .View::csrf()
                    .'<input type="hidden" name="key_id" value="'.$keyId.'">'
                    .'<button class="ghost danger">Reset all devices</button>'
                    .'</form>'
                : '';

            $limit = (bool)$key['unlimited_devices']
                ? 'Unlimited'
                : (string)(int)$key['max_devices'];

            $body = '<section class="hero">'
                .'<a class="ghost" href="/keys">← Back to keys</a>'
                .'<h1>Device management</h1>'
                .'<p class="key">'.View::e($plain).'</p>'
                .'<p class="muted">Owner: '.View::e($key['owner_name'])
                .' • Limit: '.View::e($limit).'</p>'
                .'</section>'
                .takeFlash()
                .'<div class="card">'
                .'<div class="toolbar"><h3>Bound serials</h3>'.$resetAll.'</div>'
                .'<div class="table-wrap"><table>'
                .'<thead><tr><th>Serial</th><th>First seen</th><th>Last seen</th><th>IP</th><th>Status</th><th>Action</th></tr></thead>'
                .'<tbody>'.$rows.'</tbody>'
                .'</table></div></div>';

            View::page('Devices', $body, $user);
            exit;
        } catch (Throwable $e) {
            flash('err', safeMessage($e));
            redirectTo('/keys');
        }
    }

    if ($path === '/keys/devices/reset' && $method === 'POST') {
        Security::verifyCsrf($_POST['csrf'] ?? null);

        $keyId = (int)($_POST['key_id'] ?? 0);
        $deviceId = isset($_POST['device_id'])
            && $_POST['device_id'] !== ''
            ? (int)$_POST['device_id']
            : null;

        try {
            $count = KeyManager::resetDevices(
                $user,
                $keyId,
                $deviceId
            );

            flash(
                'ok',
                $count > 0
                    ? 'Device binding reset.'
                    : 'No active device binding changed.'
            );
        } catch (Throwable $e) {
            flash('err', safeMessage($e));
        }

        if (isset($_POST['quick']) && $_POST['quick'] === '1') {
            redirectTo('/keys');
        }

        redirectTo('/keys/devices?id='.$keyId);
    }


    if ($path === '/telegram-users' && $method === 'GET') {
        Auth::requireRole($user, 'owner');

        $guests = TelegramService::unregisteredGuests();
        $guestRows = '';
        $guestKeyTotal = 0;

        foreach ($guests as $guest) {
            $keys = TelegramService::guestKeys((int)$guest['id'], 5);
            $guestKeyTotal += (int)$guest['guest_key_count_db'];
            $keyHtml = '';

            foreach ($keys as $keyRow) {
                try {
                    $plain = Crypto::decrypt(
                        $keyRow['key_cipher'],
                        $keyRow['key_iv'],
                        $keyRow['key_tag']
                    );
                } catch (Throwable) {
                    $plain = '[unavailable]';
                }

                $toggle = '';

                if ($keyRow['status'] === 'disabled') {
                    $toggle = '<form method="post" action="/telegram-users/key-action" class="inline">'
                        .View::csrf()
                        .'<input type="hidden" name="key_id" value="'.(int)$keyRow['id'].'">'
                        .'<input type="hidden" name="action" value="enable">'
                        .'<button class="ghost compact">Unblock</button></form>';
                } elseif (in_array($keyRow['status'], ['unused','active'], true)) {
                    $toggle = '<form method="post" action="/telegram-users/key-action" class="inline">'
                        .View::csrf()
                        .'<input type="hidden" name="key_id" value="'.(int)$keyRow['id'].'">'
                        .'<input type="hidden" name="action" value="disable">'
                        .'<button class="ghost compact">Block</button></form>';
                }

                $keyHtml .= '<div class="tg-keybox">'
                    .'<div><span class="key">'.View::e($plain).'</span> '
                    .'<span class="status-chip status-'.View::e($keyRow['status']).'">'.View::e(strtoupper($keyRow['status'])).'</span></div>'
                    .'<div class="tg-key-actions">'
                    .'<button type="button" class="ghost compact" data-copy="'.View::e($plain).'">Copy</button>'
                    .$toggle
                    .'<form method="post" action="/telegram-users/key-action" class="inline">'
                    .View::csrf()
                    .'<input type="hidden" name="key_id" value="'.(int)$keyRow['id'].'">'
                    .'<input type="hidden" name="action" value="reset">'
                    .'<button class="ghost compact">Reset</button></form>'
                    .'<form method="post" action="/telegram-users/key-action" class="inline">'
                    .View::csrf()
                    .'<input type="hidden" name="key_id" value="'.(int)$keyRow['id'].'">'
                    .'<input type="hidden" name="action" value="delete">'
                    .'<button class="ghost compact danger" data-confirm="Delete this Telegram guest key?">Delete</button></form>'
                    .'</div></div>';
            }

            if ($keyHtml === '') {
                $keyHtml = '<span class="muted">No guest keys yet.</span>';
            }

            $tgName = TelegramService::displayName($guest);
            $username = $guest['username']
                ? '@'.View::e($guest['username'])
                : '—';

            $guestRows .= '<tr>'
                .'<td><strong>'.View::e($tgName).'</strong><br><span class="muted">'.$username.'</span></td>'
                .'<td><span class="key">'.View::e($guest['chat_id']).'</span></td>'
                .'<td>'.View::e($guest['first_seen_at']).'</td>'
                .'<td>'.View::e($guest['last_seen_at']).'</td>'
                .'<td>'.View::e(TelegramService::nextGuestEligible($guest['guest_last_key_at'])).'</td>'
                .'<td>'.$keyHtml.'</td>'
                .'</tr>';
        }

        if ($guestRows === '') {
            $guestRows = '<tr><td colspan="6"><div class="empty-state"><div class="empty-orb">TG</div><h3>No unregistered TG users</h3><p class="muted">Telegram guests will appear here after they start the bot.</p></div></td></tr>';
        }

        $linkedCount = (int)Database::pdo()->query(
            "SELECT COUNT(*) FROM telegram_users WHERE linked_user_id IS NOT NULL"
        )->fetchColumn();

        $body = '<section class="hero keys-hero"><div>'
            .'<div class="eyebrow">OWNER ONLY</div><h1>TG Users</h1>'
            .'<p class="muted">Unregistered Telegram users and their 2-hour guest keys. Linked users automatically move into normal panel ownership.</p></div></section>'
            .takeFlash()
            .'<div class="grid">'
            .'<div class="card third metric"><div class="eyebrow">UNREGISTERED TG</div><div class="stat">'.count($guests).'</div></div>'
            .'<div class="card third metric"><div class="eyebrow">LINKED TG</div><div class="stat">'.$linkedCount.'</div></div>'
            .'<div class="card third metric"><div class="eyebrow">GUEST KEYS</div><div class="stat">'.$guestKeyTotal.'</div></div>'
            .'<div class="card"><div class="toolbar"><h3>Unregistered Telegram Users</h3><span class="tag">Owner view</span></div>'
            .'<div class="table-wrap"><table class="compact-table tg-table">'
            .'<thead><tr><th>Name</th><th>Chat ID</th><th>First Seen</th><th>Last Seen</th><th>Next Free 2H</th><th>Guest Keys</th></tr></thead>'
            .'<tbody>'.$guestRows.'</tbody></table></div></div>'
            .'</div>';

        View::page('TG Users', $body, $user);
        exit;
    }

    if ($path === '/telegram-users/key-action' && $method === 'POST') {
        Security::verifyCsrf($_POST['csrf'] ?? null);
        Auth::requireRole($user, 'owner');

        $keyId = (int)($_POST['key_id'] ?? 0);
        $action = input('action');

        try {
            if ($action === 'reset') {
                KeyManager::resetDevices($user, $keyId);
            } elseif (in_array($action, ['disable','enable','delete'], true)) {
                KeyManager::action($user, $keyId, $action);
            } else {
                throw new RuntimeException('Invalid Telegram key action.');
            }

            flash('ok', 'Telegram guest key updated.');
        } catch (Throwable $e) {
            flash('err', safeMessage($e));
        }

        redirectTo('/telegram-users');
    }

    if ($path === '/activity' && $method === 'GET') {
        OwnerConsole::activity($user, $_GET);
        exit;
    }

    if ($path === '/users' && $method === 'GET') {
        Auth::requireRole($user, 'admin');

        $pdo = Database::pdo();

        if ($user['role'] === 'owner') {
            $rows = $pdo->query(
                'SELECT id,name,username,role,balance,telegram_chat_id,status,last_login_at,created_at FROM users ORDER BY id DESC LIMIT 1000'
            )->fetchAll();
        } else {
            $q = $pdo->prepare(
                "SELECT id,name,username,role,balance,telegram_chat_id,status,last_login_at,created_at
                 FROM users
                 WHERE role<>'owner'
                 ORDER BY id DESC
                 LIMIT 1000"
            );
            $q->execute();
            $rows = $q->fetchAll();
        }

        $roles = ReferralManager::allowedRoles($user);
        $roleOptions = '';
        foreach ($roles as $role) {
            $roleOptions .= '<option value="'.View::e($role).'">'.View::e(ucfirst($role)).'</option>';
        }

        $activeCount = 0;
        $disabledCount = 0;
        $linkedCount = 0;
        $adminCount = 0;
        $trs = '';
        foreach ($rows as $row) {
            $rowBalance = $row['role'] === 'owner'
                ? '∞'
                : number_format((int)$row['balance']);

            $row['status'] === 'active' ? $activeCount++ : $disabledCount++;
            if ($row['telegram_chat_id']) $linkedCount++;
            if ($row['role'] === 'admin') $adminCount++;

            $canAdjust = Auth::canManageRole($user, $row['role']);
            $isOwnerTarget = $user['role'] === 'owner'
                && $row['role'] !== 'owner'
                && (int)$row['id'] !== (int)$user['id'];

            $adjust = $canAdjust && $user['role'] !== 'owner'
                ? '<form method="post" action="/users/balance" class="inline balance-form">'
                    .View::csrf()
                    .'<input type="hidden" name="user_id" value="'.(int)$row['id'].'">'
                    .'<input name="amount" type="number" required placeholder="± credits">'
                    .'<button class="ghost compact" data-action="Update balance">Apply</button>'
                    .'</form>'
                : ($isOwnerTarget
                    ? '<button type="button" class="ghost compact" data-open-modal="owner-user" data-user-manage'
                        .' data-user-id="'.(int)$row['id'].'"'
                        .' data-user-name="'.View::e($row['name'] ?: $row['username']).'"'
                        .' data-user-username="'.View::e($row['username']).'"'
                        .' data-user-role="'.View::e($row['role']).'"'
                        .' data-user-status="'.View::e($row['status']).'"'
                        .' data-user-balance="'.View::e($rowBalance).'"'
                        .' data-user-telegram="'.View::e($row['telegram_chat_id'] ?: '').'">Manage</button>'
                    : '<span class="muted">Protected</span>');

            $telegramCell = $row['telegram_chat_id']
                ? (
                    $user['role'] === 'owner'
                        ? '<span class="key">'.View::e($row['telegram_chat_id']).'</span>'
                        : '<span class="status-chip status-active">LINKED</span>'
                )
                : '<span class="muted">Not linked</span>';

            $initial = strtoupper(substr((string)($row['name'] ?: $row['username']), 0, 1));
            $checkbox = $isOwnerTarget
                ? '<input class="row-check" type="checkbox" value="'.(int)$row['id'].'" data-user-check aria-label="Select @'.View::e($row['username']).'">'
                : '<span class="muted">—</span>';

            $trs .= '<tr data-user-row data-role="'.View::e($row['role']).'" data-status="'.View::e($row['status']).'" data-search="'.View::e(strtolower(($row['name'] ?: '').' '.$row['username'].' '.$row['role'].' '.$row['status'])).'">'
                .'<td>'.$checkbox.'</td>'
                .'<td><div class="row-user"><span class="mini-avatar">'.View::e($initial).'</span><span><strong>'.View::e($row['name'] ?: $row['username']).'</strong><small>@'.View::e($row['username']).' • ID '.(int)$row['id'].'</small></span></div></td>'
                .'<td><span class="tag">'.View::e($row['role']).'</span></td>'
                .'<td>'.View::e($rowBalance).'</td>'
                .'<td>'.$telegramCell.'</td>'
                .'<td><span class="status-chip status-'.View::e($row['status']).'">'.View::e($row['status']).'</span></td>'
                .'<td>'.View::e($row['last_login_at'] ?: 'Never').'</td>'
                .'<td>'.$adjust.'</td>'
                .'</tr>';
        }

        $invites = ReferralManager::visible($user);
        $inviteRows = '';

        foreach ($invites as $invite) {
            $usedBy = $invite['used_username']
                ? View::e(($invite['used_name'] ?: $invite['used_username']).' @'.$invite['used_username'])
                : '<span class="muted">Waiting for registration</span>';

            $inviteAction = $invite['status'] === 'pending'
                ? '<form method="post" action="/referrals/revoke" class="inline" data-confirm="Revoke this registration invite?" data-busy="Revoking invite…">'
                    .View::csrf().'<input type="hidden" name="invite_id" value="'.(int)$invite['id'].'">'
                    .'<button class="ghost danger compact" type="submit">Revoke</button></form>'
                : '<span class="muted">—</span>';

            $inviteRows .= '<tr>'
                .'<td><span class="key">'.View::e($invite['code']).'</span><br>'
                .'<button type="button" class="ghost compact" data-copy="'.View::e($invite['code']).'">Copy</button></td>'
                .'<td><span class="tag">'.View::e($invite['role']).'</span></td>'
                .'<td>'.View::e($invite['creator_username']).'</td>'
                .'<td><span class="status-chip status-'.View::e($invite['status']).'">'.View::e(strtoupper($invite['status'])).'</span></td>'
                .'<td>'.$usedBy.'</td>'
                .'<td>'.View::e($invite['created_at']).'</td>'
                .'<td>'.$inviteAction.'</td>'
                .'</tr>';
        }

        if ($inviteRows === '') {
            $inviteRows = '<tr><td colspan="7"><div class="empty-state"><div class="empty-orb">＋</div><h3>No invites yet</h3><p class="muted">Create a secure one-time registration invite.</p></div></td></tr>';
        }

        $bulkBar = $user['role'] === 'owner'
            ? '<form method="post" action="/users/bulk" class="selection-bar" data-bulk-form data-confirm="Apply this action to all selected users?" data-busy="Updating selected users…">'
                .View::csrf().'<input type="hidden" name="user_ids" value="">'
                .'<strong data-selected-count>0 selected</strong>'
                .'<select name="action" class="control-select" aria-label="Bulk action"><option value="disable">Disable accounts</option><option value="enable">Enable accounts</option><option value="revoke_access">Revoke API access</option></select>'
                .'<button class="primary compact" type="submit" disabled>Apply action</button></form>'
            : '';

        $ownerModal = '';
        if ($user['role'] === 'owner') {
            $ownerModal = '<div class="modal-backdrop" id="owner-user" data-modal="owner-user" aria-hidden="true">'
                .'<div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="owner-user-title">'
                .'<div class="modal-head"><div><div class="eyebrow">OWNER CONTROL</div><h3 id="owner-user-title">Manage user</h3></div><button type="button" class="icon-btn" data-close-modal aria-label="Close">×</button></div>'
                .'<div class="user-summary"><span class="mini-avatar" data-owner-initial>U</span><div><strong data-owner-user-name>User</strong><small data-owner-user-handle>@username</small></div><span class="tag" data-owner-user-role>USER</span></div>'
                .'<div class="modal-section"><a class="ghost wide" href="/owner/users" data-owner-history>Keys, balance and all history</a><div class="form-row"><div><span class="eyebrow">BALANCE</span><strong data-owner-user-balance>0</strong></div><div><span class="eyebrow">TELEGRAM</span><strong data-owner-user-telegram>Not linked</strong></div></div></div>'
                .'<div class="modal-section"><div class="eyebrow">ACCOUNT SETTINGS</div><div class="modal-actions-grid">'
                .'<form method="post" action="/users/balance" class="stack" data-owner-form data-busy="Updating balance…">'.View::csrf().'<input type="hidden" name="user_id"><div class="field"><label>Balance adjustment</label><input name="amount" type="number" required placeholder="Use + or - credits"></div><button class="primary" type="submit">Update balance</button></form>'
                .'<form method="post" action="/users/role" class="stack" data-owner-form data-confirm="Change this user role?" data-busy="Changing account role…">'.View::csrf().'<input type="hidden" name="user_id"><div class="field"><label>Account role</label><select name="role" data-owner-role-select><option value="admin">Admin</option><option value="reseller">Reseller</option><option value="user">User</option></select></div><button class="ghost" type="submit">Change role</button></form>'
                .'</div></div>'
                .'<div class="modal-section"><div class="eyebrow">SECURITY ACTIONS</div><div class="modal-actions-grid">'
                .'<form method="post" action="/users/status" data-owner-form data-owner-status-form data-busy="Updating account status…">'.View::csrf().'<input type="hidden" name="user_id"><input type="hidden" name="action"><button class="ghost wide" type="submit">Disable account</button></form>'
                .'<form method="post" action="/users/revoke-access" data-owner-form data-confirm="Revoke every active API token for this user?" data-busy="Revoking active access…">'.View::csrf().'<input type="hidden" name="user_id"><button class="ghost warning wide" type="submit">Revoke API access</button></form>'
                .'<form method="post" action="/users/telegram-reset" data-owner-form data-owner-telegram-form data-confirm="Disconnect this Telegram account?" data-busy="Disconnecting Telegram…">'.View::csrf().'<input type="hidden" name="user_id"><button class="ghost wide" type="submit">Disconnect Telegram</button></form>'
                .'</div></div>'
                .'<div class="modal-section"><form method="post" action="/users/password" class="stack" data-owner-form data-confirm="Replace this user password and revoke API access?" data-busy="Resetting password…">'.View::csrf().'<input type="hidden" name="user_id"><div class="field"><label>Temporary password</label><input name="password" type="password" minlength="12" maxlength="200" required autocomplete="new-password" placeholder="12+ chars, number and symbol"></div><button class="ghost danger wide" type="submit">Reset password</button></form></div>'
                .'</div></div>';
        }

        $body = '<section class="hero keys-hero"><div>'
            .'<div class="eyebrow">IDENTITY & ACCESS</div><h1>Users & invites</h1>'
            .'<p class="muted">Manage account access, roles, balances and one-time registration invites.</p></div></section>'
            .takeFlash()
            .'<div class="grid">'
            .'<div class="card quarter metric"><div class="eyebrow">TOTAL USERS</div><div class="stat">'.count($rows).'</div><div class="metric-note">'.$adminCount.' admin accounts</div></div>'
            .'<div class="card quarter metric"><div class="eyebrow">ACTIVE</div><div class="stat">'.$activeCount.'</div><div class="metric-note"><span>●</span> Access enabled</div></div>'
            .'<div class="card quarter metric"><div class="eyebrow">DISABLED</div><div class="stat">'.$disabledCount.'</div><div class="metric-note">Access blocked</div></div>'
            .'<div class="card quarter metric"><div class="eyebrow">TELEGRAM</div><div class="stat">'.$linkedCount.'</div><div class="metric-note">Verified links</div></div>'
            .'<div class="card third spotlight"><div class="eyebrow">CREATE REFERRAL</div>'
            .'<h3>One-time registration invite</h3>'
            .'<p class="muted">'.($user['role'] === 'owner'
                ? 'Owner can create Admin, Reseller and User referrals.'
                : 'Admin can create User referrals only.').'</p>'
            .'<form method="post" action="/referrals/create" class="stack">'
            .View::csrf()
            .'<div class="field"><label>Account role</label><select name="role">'.$roleOptions.'</select></div>'
            .'<button class="primary wide">Create referral</button>'
            .'</form></div>'
            .'<div class="card"><div class="toolbar"><h3>Referral invites</h3><span class="tag">'.count($invites).' visible</span></div>'
            .'<div class="table-wrap"><table class="compact-table">'
            .'<thead><tr><th>Referral</th><th>Role</th><th>Created by</th><th>Status</th><th>Registered user</th><th>Created</th><th>Action</th></tr></thead>'
            .'<tbody>'.$inviteRows.'</tbody></table></div></div>'
            .'<div class="card"><div class="toolbar"><div><h3>User directory</h3><span class="muted">Search and control every visible account</span></div>'
            .'<div class="toolbar-controls"><div class="search-wrap"><input class="control-input" type="search" placeholder="Search name or username" data-user-search></div>'
            .'<select class="control-select" data-user-role-filter aria-label="Filter by role"><option value="all">All roles</option><option value="owner">Owner</option><option value="admin">Admin</option><option value="reseller">Reseller</option><option value="user">User</option></select>'
            .'<select class="control-select" data-user-status-filter aria-label="Filter by status"><option value="all">Any status</option><option value="active">Active</option><option value="disabled">Disabled</option></select></div></div>'
            .$bulkBar
            .'<div class="table-wrap"><table class="compact-table">'
            .'<thead><tr><th>'.($user['role'] === 'owner' ? '<input class="row-check" type="checkbox" data-select-all-users aria-label="Select all manageable users">' : 'Select').'</th><th>User</th><th>Role</th><th>Balance</th><th>Telegram</th><th>Status</th><th>Last login</th><th>Control</th></tr></thead>'
            .'<tbody>'.$trs.'<tr class="table-empty" data-user-empty><td colspan="8">No users match these filters.</td></tr></tbody></table></div></div>'
            .'</div>'.$ownerModal;

        View::page('Users & invites', $body, $user);
        exit;
    }

    if ($path === '/referrals/create' && $method === 'POST') {
        Security::verifyCsrf($_POST['csrf'] ?? null);
        Auth::requireRole($user, 'admin');
        Security::rateLimit('referral-create-'.$user['id'], 30, 3600);

        try {
            $invite = ReferralManager::create($user, input('role', 'user'));
            flash('ok', 'Referral created: '.$invite['code']);
        } catch (Throwable $e) {
            flash('err', safeMessage($e));
        }

        redirectTo('/users');
    }

    if ($path === '/referrals/revoke' && $method === 'POST') {
        Security::verifyCsrf($_POST['csrf'] ?? null);
        Auth::requireRole($user, 'admin');
        $inviteId = (int)($_POST['invite_id'] ?? 0);

        $sql = "UPDATE referral_invites SET status='revoked'
                WHERE id=? AND status='pending'";
        $params = [$inviteId];
        if ($user['role'] !== 'owner') {
            $sql .= ' AND created_by=?';
            $params[] = $user['id'];
        }

        $q = Database::pdo()->prepare($sql);
        $q->execute($params);
        if ($q->rowCount() !== 1) {
            flash('err', 'Invite was not found or is no longer pending.');
        } else {
            Security::audit((int)$user['id'], 'referral_revoked', [
                'invite_id'=>$inviteId,
            ]);
            flash('ok', 'Registration invite revoked.');
        }
        redirectTo('/users');
    }

    if ($path === '/users/status' && $method === 'POST') {
        Security::verifyCsrf($_POST['csrf'] ?? null);
        Auth::requireRole($user, 'owner');
        Security::rateLimit('owner-user-control-'.$user['id'], 240, 3600);

        try {
            $target = ownerManagedUser($user, (int)($_POST['user_id'] ?? 0));
            $action = input('action');
            if (!in_array($action, ['enable','disable'], true)) {
                throw new RuntimeException('Invalid account action.');
            }
            $status = $action === 'enable' ? 'active' : 'disabled';
            $pdo = Database::pdo();
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE users SET status=? WHERE id=?')->execute([
                $status,
                $target['id'],
            ]);
            if ($status === 'disabled') {
                $pdo->prepare('DELETE FROM api_tokens WHERE user_id=?')->execute([
                    $target['id'],
                ]);
            }
            $pdo->commit();
            Security::audit((int)$user['id'], 'user_status_changed', [
                'target_id'=>(int)$target['id'],
                'status'=>$status,
            ]);
            flash('ok', '@'.$target['username'].' is now '.$status.'.');
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            flash('err', safeMessage($e));
        }
        redirectTo('/users');
    }

    if ($path === '/users/role' && $method === 'POST') {
        Security::verifyCsrf($_POST['csrf'] ?? null);
        Auth::requireRole($user, 'owner');

        try {
            $target = ownerManagedUser($user, (int)($_POST['user_id'] ?? 0));
            $role = input('role');
            if (!in_array($role, ['admin','reseller','user'], true)) {
                throw new RuntimeException('Invalid account role.');
            }
            Database::pdo()->prepare('UPDATE users SET role=? WHERE id=?')->execute([
                $role,
                $target['id'],
            ]);
            Security::audit((int)$user['id'], 'user_role_changed', [
                'target_id'=>(int)$target['id'],
                'from'=>$target['role'],
                'to'=>$role,
            ]);
            flash('ok', '@'.$target['username'].' is now '.ucfirst($role).'.');
        } catch (Throwable $e) {
            flash('err', safeMessage($e));
        }
        redirectTo('/users');
    }

    if ($path === '/users/revoke-access' && $method === 'POST') {
        Security::verifyCsrf($_POST['csrf'] ?? null);
        Auth::requireRole($user, 'owner');

        try {
            $target = ownerManagedUser($user, (int)($_POST['user_id'] ?? 0));
            $q = Database::pdo()->prepare('DELETE FROM api_tokens WHERE user_id=?');
            $q->execute([$target['id']]);
            Security::audit((int)$user['id'], 'user_access_revoked', [
                'target_id'=>(int)$target['id'],
                'tokens_revoked'=>$q->rowCount(),
            ]);
            flash('ok', 'Active API access revoked for @'.$target['username'].'.');
        } catch (Throwable $e) {
            flash('err', safeMessage($e));
        }
        redirectTo('/users');
    }

    if ($path === '/users/telegram-reset' && $method === 'POST') {
        Security::verifyCsrf($_POST['csrf'] ?? null);
        Auth::requireRole($user, 'owner');

        try {
            $target = ownerManagedUser($user, (int)($_POST['user_id'] ?? 0));
            if (!$target['telegram_chat_id']) {
                throw new RuntimeException('This user has no linked Telegram account.');
            }
            TelegramService::unlink($target);
            Security::audit((int)$user['id'], 'user_telegram_reset', [
                'target_id'=>(int)$target['id'],
            ]);
            flash('ok', 'Telegram disconnected for @'.$target['username'].'.');
        } catch (Throwable $e) {
            flash('err', safeMessage($e));
        }
        redirectTo('/users');
    }

    if ($path === '/users/password' && $method === 'POST') {
        Security::verifyCsrf($_POST['csrf'] ?? null);
        Auth::requireRole($user, 'owner');

        try {
            $target = ownerManagedUser($user, (int)($_POST['user_id'] ?? 0));
            $password = (string)($_POST['password'] ?? '');
            if (!validPassword($password)) {
                throw new RuntimeException(
                    'Password needs 12+ characters with a letter, number and symbol.'
                );
            }
            $pdo = Database::pdo();
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([
                Security::passwordHash($password),
                $target['id'],
            ]);
            $pdo->prepare('DELETE FROM api_tokens WHERE user_id=?')->execute([
                $target['id'],
            ]);
            $pdo->commit();
            Security::audit((int)$user['id'], 'user_password_reset', [
                'target_id'=>(int)$target['id'],
            ]);
            flash('ok', 'Password reset and API access revoked for @'.$target['username'].'.');
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            flash('err', safeMessage($e));
        }
        redirectTo('/users');
    }

    if ($path === '/users/bulk' && $method === 'POST') {
        Security::verifyCsrf($_POST['csrf'] ?? null);
        Auth::requireRole($user, 'owner');
        Security::rateLimit('owner-user-bulk-'.$user['id'], 30, 3600);

        try {
            $ids = array_values(array_unique(array_filter(
                array_map('intval', explode(',', input('user_ids'))),
                static fn (int $id): bool => $id > 0
            )));
            if (!$ids || count($ids) > 100) {
                throw new RuntimeException('Select between 1 and 100 users.');
            }
            $action = input('action');
            if (!in_array($action, ['enable','disable','revoke_access'], true)) {
                throw new RuntimeException('Invalid bulk action.');
            }

            $targets = [];
            foreach ($ids as $id) $targets[] = ownerManagedUser($user, $id);
            $pdo = Database::pdo();
            $pdo->beginTransaction();
            foreach ($targets as $target) {
                if ($action === 'revoke_access') {
                    $pdo->prepare('DELETE FROM api_tokens WHERE user_id=?')->execute([
                        $target['id'],
                    ]);
                    continue;
                }
                $status = $action === 'enable' ? 'active' : 'disabled';
                $pdo->prepare('UPDATE users SET status=? WHERE id=?')->execute([
                    $status,
                    $target['id'],
                ]);
                if ($status === 'disabled') {
                    $pdo->prepare('DELETE FROM api_tokens WHERE user_id=?')->execute([
                        $target['id'],
                    ]);
                }
            }
            $pdo->commit();
            Security::audit((int)$user['id'], 'users_bulk_action', [
                'action'=>$action,
                'target_ids'=>$ids,
            ]);
            flash('ok', 'Bulk action applied to '.count($ids).' users.');
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            flash('err', safeMessage($e));
        }
        redirectTo('/users');
    }

    if ($path === '/users/create' && $method === 'POST') {
        Security::verifyCsrf($_POST['csrf'] ?? null);
        Auth::requireRole($user, 'admin');
        flash('err', 'Direct user creation is disabled. Create a referral and let the user register.');
        redirectTo('/users');
    }

    if ($path === '/users/balance' && $method === 'POST') {
        Security::verifyCsrf($_POST['csrf'] ?? null);
        Auth::requireRole($user, 'admin');

        $targetId = (int)($_POST['user_id'] ?? 0);

        $amount = parseBalanceDelta(
            input('amount'),
            $user['role'] === 'owner'
        );

        if ($amount === null) {
            flash(
                'err',
                $user['role'] === 'owner'
                    ? 'Enter a valid non-zero balance adjustment.'
                    : 'Admin adjustment must be between -100000 and 100000.'
            );
            redirectTo('/users');
        }

        $q = Database::pdo()->prepare(
            'SELECT id,role,status FROM users WHERE id=? LIMIT 1'
        );
        $q->execute([$targetId]);
        $target = $q->fetch();

        if (
            !$target
            || $target['status'] !== 'active'
            || !Auth::canManageRole($user, $target['role'])
        ) {
            flash('err', 'Target user not allowed.');
            redirectTo('/users');
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $q = $pdo->prepare(
                'SELECT balance FROM users WHERE id=? FOR UPDATE'
            );
            $q->execute([$targetId]);
            $current = (int)$q->fetchColumn();

            if ($current + $amount < 0) {
                throw new RuntimeException(
                    'Balance cannot go below zero.'
                );
            }

            $pdo->prepare(
                'UPDATE users SET balance=balance+? WHERE id=?'
            )->execute([
                $amount,
                $targetId,
            ]);

            $pdo->prepare(
                'INSERT INTO balance_ledger(user_id,actor_user_id,amount,reason) VALUES(?,?,?,?)'
            )->execute([
                $targetId,
                $user['id'],
                $amount,
                'Manual balance adjustment',
            ]);

            $pdo->commit();

            Security::audit(
                (int)$user['id'],
                'balance_adjusted',
                [
                    'target_id'=>$targetId,
                    'amount'=>$amount,
                ]
            );

            flash('ok', 'Balance updated.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            flash('err', safeMessage($e));
        }

        redirectTo('/users');
    }

    http_response_code(404);

    View::page(
        'Not found',
        '<section class="auth"><div class="card">'
            .'<h1>404</h1>'
            .'<p class="muted">Route not found.</p>'
            .'<a class="btn" href="/">Go home</a>'
            .'</div></section>',
        Auth::user()
    );
} catch (Throwable $e) {
    if (str_starts_with($path, '/api/')) {
        jsonOut([
            'ok'=>false,
            'error'=>'Request failed',
        ], 400);
    }

    $incident = bin2hex(random_bytes(6));
    error_log(
        'TeamDark request failure ['.$incident.']: '
        .get_class($e)
        .' at '
        .basename($e->getFile())
        .':'
        .$e->getLine()
    );

    http_response_code(400);
    $u = Auth::user();

    View::page(
        'Request failed',
        '<section class="auth"><div class="card">'
            .'<h1>Request failed</h1>'
            .'<div class="alert">The request could not be completed. Reference: '
            .View::e($incident)
            .'</div>'
            .'<a class="btn" href="/">Go back</a>'
            .'</div></section>',
        $u
    );
}
