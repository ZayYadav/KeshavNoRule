<?php
declare(strict_types=1);

use TeamDark\Panel\{
    Auth,
    Config,
    Crypto,
    Database,
    KeyManager,
    LicenseService,
    Security,
    View
};

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

    return '<div class="alert '.($f[0] === 'ok' ? 'ok' : '').'">'
        .View::e($f[1])
        .'</div>';
}

function jsonBody(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);

    return is_array($data) ? $data : [];
}

function jsonOut(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(
        $data,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    exit;
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
        "SELECT u.id,u.username,u.role,u.balance,u.referral_code,u.status,t.id token_id
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
    // Legacy JSON panel-user authentication API.
    if ($path === '/api/v1/auth/login' && $method === 'POST') {
        Security::rateLimit('api-login', 10, 600);

        $b = jsonBody();
        $username = strtolower(trim((string)($b['username'] ?? '')));
        $password = (string)($b['password'] ?? '');

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

        $token = rtrim(
            strtr(base64_encode(random_bytes(48)), '+/', '-_'),
            '='
        );
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
            .'<input name="referral" value="'.$prefill.'" required maxlength="32"></div>'
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
        Security::rateLimit('register', 6, 3600);

        $username = strtolower(input('username'));
        $password = (string)($_POST['password'] ?? '');
        $ref = strtoupper(input('referral'));

        if (!validUsername($username)) {
            flash(
                'err',
                'Username must be 3–32 chars: letters, numbers, dot, underscore or hyphen.'
            );
            redirectTo('/register');
        }

        if (!validPassword($password)) {
            flash(
                'err',
                'Password must be 12+ characters with a letter, number and symbol.'
            );
            redirectTo('/register?ref='.urlencode($ref));
        }

        $pdo = Database::pdo();

        $q = $pdo->prepare(
            "SELECT id FROM users
             WHERE referral_code=? AND status='active'
             LIMIT 1"
        );
        $q->execute([$ref]);
        $referrer = $q->fetch();

        if (!$referrer) {
            flash('err', 'Invalid referral code.');
            redirectTo('/register');
        }

        $pdo->beginTransaction();

        try {
            $signup = (int)Config::get('signup_bonus');
            $bonus = (int)Config::get('referrer_bonus');

            $pdo->prepare(
                "INSERT INTO users(
                    username,password_hash,role,balance,referral_code,
                    referred_by,created_by,status
                 ) VALUES(?,?,'user',?,?,?,?, 'active')"
            )->execute([
                $username,
                Security::passwordHash($password),
                $signup,
                referralCode(),
                $referrer['id'],
                $referrer['id'],
            ]);

            $uid = (int)$pdo->lastInsertId();

            if ($signup > 0) {
                $pdo->prepare(
                    'INSERT INTO balance_ledger(user_id,actor_user_id,amount,reason) VALUES(?,?,?,?)'
                )->execute([
                    $uid,
                    $referrer['id'],
                    $signup,
                    'Referral signup bonus',
                ]);
            }

            if ($bonus > 0) {
                $pdo->prepare(
                    'UPDATE users SET balance=balance+? WHERE id=?'
                )->execute([
                    $bonus,
                    $referrer['id'],
                ]);

                $pdo->prepare(
                    'INSERT INTO balance_ledger(user_id,actor_user_id,amount,reason) VALUES(?,?,?,?)'
                )->execute([
                    $referrer['id'],
                    $uid,
                    $bonus,
                    'Successful referral bonus',
                ]);
            }

            $pdo->commit();

            Security::audit($uid, 'registered', [
                'referred_by'=>(int)$referrer['id'],
            ]);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            flash(
                'err',
                $e->getCode() === '23000'
                    ? 'Username already exists.'
                    : 'Registration failed.'
            );

            redirectTo('/register?ref='.urlencode($ref));
        }

        flash('ok', 'Account created. You can sign in now.');
        redirectTo('/login');
    }

    $user = Auth::requireLogin();

    if ($path === '/dashboard' && $method === 'GET') {
        KeyManager::expireDue();

        $pdo = Database::pdo();

        if ($user['role'] === 'owner') {
            $users = (int)$pdo->query(
                'SELECT COUNT(*) FROM users'
            )->fetchColumn();

            $keys = (int)$pdo->query(
                "SELECT COUNT(*) FROM license_keys WHERE status<>'expired'"
            )->fetchColumn();

            $expired = (int)$pdo->query(
                "SELECT COUNT(*) FROM license_keys WHERE status='expired'"
            )->fetchColumn();
        } else {
            $q = $pdo->prepare(
                'SELECT COUNT(*) FROM users WHERE referred_by=?'
            );
            $q->execute([$user['id']]);
            $users = (int)$q->fetchColumn();

            $keys = count(KeyManager::visibleKeys($user, 'current'));
            $expired = count(KeyManager::visibleKeys($user, 'expired'));
        }

        $body = '<section class="hero">'
            .'<span class="tag">'.View::e(strtoupper($user['role'])).'</span>'
            .'<h1>Hello, '.View::e($user['username']).'</h1>'
            .'<p class="muted">Native-loader compatible license control with first-use timing and device binding.</p>'
            .'</section>'
            .takeFlash()
            .'<div class="grid">'
            .'<div class="card quarter"><div class="muted">Balance</div>'
            .'<div class="stat">'.View::e(balanceText($user)).'</div></div>'
            .'<div class="card quarter"><div class="muted">Current keys</div>'
            .'<div class="stat">'.$keys.'</div></div>'
            .'<div class="card quarter"><div class="muted">Expired keys</div>'
            .'<div class="stat">'.$expired.'</div></div>'
            .'<div class="card quarter"><div class="muted">Referrals</div>'
            .'<div class="stat">'.$users.'</div></div>'
            .'<div class="card half"><h3>Your referral code</h3>'
            .'<p class="key">'.View::e($user['referral_code']).'</p>'
            .'<button class="ghost" data-copy="'.View::e($user['referral_code']).'">Copy code</button>'
            .'</div>'
            .'<div class="card half"><h3>Account</h3>'
            .'<p class="muted">Role: '.View::e($user['role']).'<br>'
            .'Balance policy: '.(ownerUnlimited($user) ? 'Unlimited' : 'Credit based').'<br>'
            .'Created: '.View::e($user['created_at']).'</p>'
            .'</div></div>';

        View::page('Dashboard', $body, $user);
        exit;
    }

    if (($path === '/keys' || $path === '/keys/expired') && $method === 'GET') {
        $filter = $path === '/keys/expired'
            ? 'expired'
            : 'current';

        $rows = KeyManager::visibleKeys($user, $filter);
        $targets = KeyManager::targetsFor($user);
        $create = '';

        if ($filter !== 'expired' && $targets) {
            $targetOptions = '';

            foreach ($targets as $target) {
                $targetOptions .= '<option value="'.(int)$target['id'].'">'
                    .View::e($target['username'].' • '.$target['role'])
                    .'</option>';
            }

            $deviceOptions = '';
            foreach (KeyManager::DEVICE_LIMITS as $limit) {
                $selected = $limit === 10 ? ' selected' : '';
                $deviceOptions .= '<option value="'.$limit.'"'.$selected.'>'
                    .$limit.' devices'
                    .'</option>';
            }
            $deviceOptions .= '<option value="unlimited">Unlimited devices</option>';

            $pricing = ownerUnlimited($user)
                ? 'Owner generation cost: 0 credits.'
                : 'Timed cost: '.(int)Config::get('key_cost')
                    .' credit(s) per started 24h. Unlimited validity: '
                    .(int)Config::get('unlimited_key_cost')
                    .' credits.';

            $create = '<div class="card">'
                .'<h3>Create PUBG license</h3>'
                .'<p class="muted">Countdown starts only after the first successful TeamDark Loader login.</p>'
                .'<form method="post" action="/keys/create" class="stack">'
                .View::csrf()
                .'<div class="field"><label>Assign to</label>'
                .'<select name="owner_id">'.$targetOptions.'</select></div>'
                .'<div class="field"><label>Label</label>'
                .'<input name="label" maxlength="100" placeholder="Customer / plan note"></div>'
                .'<div class="form-row">'
                .'<div class="field"><label>Days</label>'
                .'<input type="number" name="duration_days" min="0" max="3650" value="30"></div>'
                .'<div class="field"><label>Hours</label>'
                .'<input type="number" name="duration_hours" min="0" max="23" value="0"></div>'
                .'</div>'
                .'<label class="checkline"><input type="checkbox" name="unlimited_expiry" value="1"> Unlimited validity</label>'
                .'<div class="field"><label>Maximum devices</label>'
                .'<select name="max_devices">'.$deviceOptions.'</select></div>'
                .'<button class="primary">Generate key</button>'
                .'</form>'
                .'<p class="muted">'.$pricing.'</p>'
                .'</div>';
        }

        $trs = '';

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

            $duration = (bool)$row['unlimited_expiry']
                ? 'UNLIMITED'
                : humanDuration((int)$row['duration_seconds']);

            $activation = $row['activated_at']
                ? View::e($row['activated_at'])
                : '<span class="muted">Not used yet</span>';

            $expiry = (bool)$row['unlimited_expiry']
                ? '<span class="tag">UNLIMITED</span>'
                : (
                    $row['expires_at']
                        ? View::e($row['expires_at'])
                        : '<span class="muted">Starts on first use</span>'
                );

            $deviceText = (bool)$row['unlimited_devices']
                ? (int)$row['device_count'].' / ∞'
                : (int)$row['device_count'].' / '.(int)$row['max_devices'];

            $actions = '<a class="ghost" href="/keys/devices?id='.(int)$row['id'].'">Devices</a>';

            if (roleRank($user['role']) >= 20) {
                if ($row['status'] === 'disabled') {
                    $actions .= '<form method="post" action="/keys/action" class="inline">'
                        .View::csrf()
                        .'<input type="hidden" name="key_id" value="'.(int)$row['id'].'">'
                        .'<input type="hidden" name="action" value="enable">'
                        .'<button class="ghost">Enable</button></form>';
                } elseif (in_array($row['status'], ['unused','active'], true)) {
                    $actions .= '<form method="post" action="/keys/action" class="inline">'
                        .View::csrf()
                        .'<input type="hidden" name="key_id" value="'.(int)$row['id'].'">'
                        .'<input type="hidden" name="action" value="disable">'
                        .'<button class="ghost">Disable</button></form>';
                }

                if (!in_array($row['status'], ['revoked','expired'], true)) {
                    $actions .= '<form method="post" action="/keys/action" class="inline">'
                        .View::csrf()
                        .'<input type="hidden" name="key_id" value="'.(int)$row['id'].'">'
                        .'<input type="hidden" name="action" value="revoke">'
                        .'<button class="ghost danger">Revoke</button></form>';
                }

                if ($user['role'] === 'owner') {
                    $actions .= '<form method="post" action="/keys/action" class="inline">'
                        .View::csrf()
                        .'<input type="hidden" name="key_id" value="'.(int)$row['id'].'">'
                        .'<input type="hidden" name="action" value="delete">'
                        .'<button class="ghost danger">Delete</button></form>';
                }
            }

            $trs .= '<tr>'
                .'<td><span class="key">'.View::e($plain).'</span><br>'
                .'<button class="ghost" data-copy="'.View::e($plain).'">Copy</button></td>'
                .'<td>'.View::e($row['owner_name']).'</td>'
                .'<td>'.View::e($row['label']).'</td>'
                .'<td>'.View::e($duration).'</td>'
                .'<td><span class="tag">'.View::e($row['status']).'</span></td>'
                .'<td>'.$activation.'</td>'
                .'<td>'.$expiry.'</td>'
                .'<td>'.View::e($deviceText).'</td>'
                .'<td>'.View::e($row['last_used_at'] ?: 'Never').'</td>'
                .'<td class="actions">'.$actions.'</td>'
                .'</tr>';
        }

        if ($trs === '') {
            $trs = '<tr><td colspan="10" class="muted">No keys in this section.</td></tr>';
        }

        $body = '<section class="hero">'
            .'<h1>'.($filter === 'expired' ? 'Expired keys' : 'License keys').'</h1>'
            .'<p class="muted">PUBG • first-use activation • native serial binding • automatic expiry.</p>'
            .'</section>'
            .takeFlash()
            .'<div class="tabs key-tabs">'
            .'<a '.($filter === 'current' ? 'class="active"' : '').' href="/keys">Current</a>'
            .'<a '.($filter === 'expired' ? 'class="active"' : '').' href="/keys/expired">Expired</a>'
            .'</div>'
            .'<div class="grid">'
            .$create
            .'<div class="card">'
            .'<div class="toolbar"><h3>Keys</h3><span class="tag">'.count($rows).' visible</span></div>'
            .'<div class="table-wrap"><table>'
            .'<thead><tr>'
            .'<th>Key</th><th>Owner</th><th>Label</th><th>Duration</th>'
            .'<th>Status</th><th>Activated</th><th>Expires</th>'
            .'<th>Devices</th><th>Last use</th><th>Actions</th>'
            .'</tr></thead>'
            .'<tbody>'.$trs.'</tbody>'
            .'</table></div></div></div>';

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

        $days = max(
            0,
            min(3650, (int)($_POST['duration_days'] ?? 0))
        );
        $hours = max(
            0,
            min(23, (int)($_POST['duration_hours'] ?? 0))
        );

        $unlimitedExpiry = isset($_POST['unlimited_expiry'])
            && $_POST['unlimited_expiry'] === '1';

        $durationSeconds = ($days * 86400) + ($hours * 3600);

        $deviceRaw = input('max_devices', '10');
        $unlimitedDevices = $deviceRaw === 'unlimited';
        $maxDevices = $unlimitedDevices ? 1 : (int)$deviceRaw;

        try {
            $created = KeyManager::create(
                $user,
                (int)($_POST['owner_id'] ?? 0),
                input('label'),
                $durationSeconds,
                $unlimitedExpiry,
                $maxDevices,
                $unlimitedDevices
            );

            flash(
                'ok',
                'Key generated successfully. Cost: '
                    .$created['cost']
                    .' credit(s). Timer starts on first valid Loader login.'
            );
        } catch (Throwable $e) {
            flash('err', $e->getMessage());
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
            flash('err', $e->getMessage());
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
            flash('err', $e->getMessage());
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
            flash('err', $e->getMessage());
        }

        redirectTo('/keys/devices?id='.$keyId);
    }

    if ($path === '/keys/action' && $method === 'POST') {
        Security::verifyCsrf($_POST['csrf'] ?? null);

        $keyId = (int)($_POST['key_id'] ?? 0);
        $action = input('action');
        $key = findManageableKey($user, $keyId);

        if (!$key) {
            flash('err', 'Key not found or not allowed.');
            redirectTo('/keys');
        }

        $pdo = Database::pdo();

        if ($action === 'disable') {
            $pdo->prepare("UPDATE license_keys SET status='disabled' WHERE id=?")
                ->execute([$keyId]);
            Security::audit((int)$user['id'], 'license_disabled', ['license_id'=>$keyId]);
            flash('ok', 'Key disabled.');
        } elseif ($action === 'enable') {
            if (
                !empty($key['expires_at'])
                && (int)($key['unlimited_expiry'] ?? 0) !== 1
                && strtotime((string)$key['expires_at']) <= time()
            ) {
                $pdo->prepare("UPDATE license_keys SET status='expired' WHERE id=?")
                    ->execute([$keyId]);
                flash('err', 'Expired key cannot be enabled.');
            } else {
                $newStatus = empty($key['activated_at']) ? 'unused' : 'active';
                $pdo->prepare("UPDATE license_keys SET status=? WHERE id=?")
                    ->execute([$newStatus, $keyId]);
                Security::audit((int)$user['id'], 'license_enabled', ['license_id'=>$keyId]);
                flash('ok', 'Key enabled.');
            }
        } elseif ($action === 'revoke') {
            $pdo->prepare("UPDATE license_keys SET status='revoked' WHERE id=?")
                ->execute([$keyId]);
            Security::audit((int)$user['id'], 'license_revoked', ['license_id'=>$keyId]);
            flash('ok', 'Key revoked.');
        } elseif ($action === 'reset_devices') {
            $pdo->prepare(
                "UPDATE license_devices SET active=0 WHERE license_key_id=? AND active=1"
            )->execute([$keyId]);
            Security::audit((int)$user['id'], 'license_devices_reset', ['license_id'=>$keyId]);
            flash('ok', 'All device bindings reset.');
        } elseif ($action === 'delete') {
            $pdo->prepare("DELETE FROM license_keys WHERE id=?")->execute([$keyId]);
            Security::audit((int)$user['id'], 'license_deleted', ['license_id'=>$keyId]);
            flash('ok', 'Key deleted.');
        } else {
            flash('err', 'Invalid key action.');
        }

        redirectTo('/keys');
    }

    if ($path === '/users' && $method === 'GET') {
        Auth::requireRole($user, 'admin');

        $pdo = Database::pdo();

        if ($user['role'] === 'owner') {
            $rows = $pdo->query(
                'SELECT id,username,role,balance,referral_code,status,created_at FROM users ORDER BY id DESC LIMIT 1000'
            )->fetchAll();
        } else {
            $q = $pdo->prepare(
                "SELECT id,username,role,balance,referral_code,status,created_at
                 FROM users
                 WHERE role<>'owner'
                 ORDER BY id DESC
                 LIMIT 1000"
            );
            $q->execute();
            $rows = $q->fetchAll();
        }

        $roles = $user['role'] === 'owner'
            ? ['admin','reseller','user']
            : ['reseller','user'];

        $roleOptions = '';
        foreach ($roles as $role) {
            $roleOptions .= '<option>'.View::e($role).'</option>';
        }

        $trs = '';

        foreach ($rows as $row) {
            $rowBalance = $row['role'] === 'owner'
                ? '∞'
                : number_format((int)$row['balance']);

            $canAdjust = Auth::canManageRole(
                $user,
                $row['role']
            );

            $adjust = $canAdjust
                ? '<form method="post" action="/users/balance" class="inline balance-form">'
                    .View::csrf()
                    .'<input type="hidden" name="user_id" value="'.(int)$row['id'].'">'
                    .'<input name="amount" type="number" placeholder="± credits">'
                    .'<button class="ghost">Apply</button>'
                    .'</form>'
                : '<span class="muted">—</span>';

            $trs .= '<tr>'
                .'<td>'.(int)$row['id'].'</td>'
                .'<td>'.View::e($row['username']).'</td>'
                .'<td><span class="tag">'.View::e($row['role']).'</span></td>'
                .'<td>'.View::e($rowBalance).'</td>'
                .'<td class="key">'.View::e($row['referral_code']).'</td>'
                .'<td>'.View::e($row['status']).'</td>'
                .'<td>'.$adjust.'</td>'
                .'</tr>';
        }

        $openingMax = $user['role'] === 'owner'
            ? ''
            : ' max="100000"';

        $body = '<section class="hero">'
            .'<h1>Users</h1>'
            .'<p class="muted">Owner balance is unlimited and Owner can assign arbitrary account credits within BIGINT range.</p>'
            .'</section>'
            .takeFlash()
            .'<div class="grid">'
            .'<div class="card half"><h3>Create managed user</h3>'
            .'<form method="post" action="/users/create" class="stack">'
            .View::csrf()
            .'<div class="field"><label>Username</label>'
            .'<input name="username" required maxlength="32"></div>'
            .'<div class="field"><label>Password</label>'
            .'<input type="password" name="password" required minlength="12" maxlength="200"></div>'
            .'<div class="field"><label>Role</label>'
            .'<select name="role">'.$roleOptions.'</select></div>'
            .'<div class="field"><label>Opening balance</label>'
            .'<input type="number" name="balance" min="0"'.$openingMax.' value="0"></div>'
            .'<button class="primary">Create user</button>'
            .'</form></div>'
            .'<div class="card"><div class="table-wrap"><table>'
            .'<thead><tr><th>ID</th><th>User</th><th>Role</th><th>Balance</th><th>Referral</th><th>Status</th><th>Adjust</th></tr></thead>'
            .'<tbody>'.$trs.'</tbody>'
            .'</table></div></div></div>';

        View::page('Users', $body, $user);
        exit;
    }

    if ($path === '/users/create' && $method === 'POST') {
        Security::verifyCsrf($_POST['csrf'] ?? null);
        Auth::requireRole($user, 'admin');
        Security::rateLimit('user-create-'.$user['id'], 20, 3600);

        $username = strtolower(input('username'));
        $password = (string)($_POST['password'] ?? '');
        $role = input('role', 'user');

        $balance = parseUnsignedBalance(
            input('balance', '0'),
            $user['role'] === 'owner'
        );

        if (
            !validUsername($username)
            || !validPassword($password)
            || !Auth::canManageRole($user, $role)
            || $balance === null
        ) {
            flash(
                'err',
                'Invalid user, password, role or opening balance.'
            );
            redirectTo('/users');
        }

        try {
            Database::pdo()
                ->prepare(
                    'INSERT INTO users(username,password_hash,role,balance,referral_code,referred_by,created_by,status) VALUES(?,?,?,?,?,?,?,?)'
                )
                ->execute([
                    $username,
                    Security::passwordHash($password),
                    $role,
                    $balance,
                    referralCode(),
                    $user['id'],
                    $user['id'],
                    'active',
                ]);

            $id = (int)Database::pdo()->lastInsertId();

            if ($balance > 0) {
                Database::pdo()
                    ->prepare(
                        'INSERT INTO balance_ledger(user_id,actor_user_id,amount,reason) VALUES(?,?,?,?)'
                    )
                    ->execute([
                        $id,
                        $user['id'],
                        $balance,
                        'Opening balance',
                    ]);
            }

            Security::audit(
                (int)$user['id'],
                'managed_user_created',
                [
                    'target_id'=>$id,
                    'role'=>$role,
                    'opening_balance'=>$balance,
                ]
            );

            flash('ok', 'User created.');
        } catch (PDOException $e) {
            flash(
                'err',
                $e->getCode() === '23000'
                    ? 'Username already exists.'
                    : 'Could not create user.'
            );
        }

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

            flash('err', $e->getMessage());
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

    http_response_code(400);
    $u = Auth::user();

    View::page(
        'Request failed',
        '<section class="auth"><div class="card">'
            .'<h1>Request failed</h1>'
            .'<div class="alert">'.View::e($e->getMessage()).'</div>'
            .'<a class="btn" href="/">Go back</a>'
            .'</div></section>',
        $u
    );
}
