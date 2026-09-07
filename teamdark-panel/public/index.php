<?php
declare(strict_types=1);

use TeamDark\Panel\{Auth,Config,Crypto,Database,LicenseService,Security,View};

$root = dirname(__DIR__);
foreach (['Config','Database','Security','Crypto','Auth','View','LicenseService'] as $file) {
    require $root.'/app/'.$file.'.php';
}

Config::load($root);
Security::headers();
Security::startSession();

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$path = rawurldecode((string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/'));
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

    if (!$f) return '';

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
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function roleRank(string $role): int
{
    return ['user'=>10, 'reseller'=>20, 'admin'=>30, 'owner'=>40][$role] ?? 0;
}

function validUsername(string $u): bool
{
    return (bool)preg_match('/^[a-z0-9_.-]{3,32}$/', $u);
}

function validPassword(string $p): bool
{
    return strlen($p) >= 12
        && strlen($p) <= 200
        && preg_match('/[A-Za-z]/', $p)
        && preg_match('/\d/', $p)
        && preg_match('/[^A-Za-z0-9]/', $p);
}

function referralCode(): string
{
    return 'TD'.strtoupper(bin2hex(random_bytes(7)));
}

function newLicense(): string
{
    $raw = strtoupper(bin2hex(random_bytes(16)));
    return 'TD-'.implode('-', str_split($raw, 8));
}

function ownerHasUnlimitedBalance(array $user): bool
{
    return ($user['role'] ?? '') === 'owner';
}

function balanceText(array $user): string
{
    return ownerHasUnlimitedBalance($user)
        ? '∞'
        : number_format((int)($user['balance'] ?? 0));
}

function parseUnsignedBalance(string $raw, bool $ownerUnlimitedRange): ?int
{
    $raw = trim($raw);
    if (!preg_match('/^\d{1,18}$/', $raw)) return null;

    $value = (int)$raw;
    if ($value < 0) return null;

    if (!$ownerUnlimitedRange && $value > 100000) {
        return null;
    }

    return $value;
}

function parseBalanceDelta(string $raw, bool $ownerUnlimitedRange): ?int
{
    $raw = trim($raw);
    if (!preg_match('/^-?\d{1,18}$/', $raw)) return null;

    $value = (int)$raw;
    if ($value === 0) return null;

    if (!$ownerUnlimitedRange && abs($value) > 100000) {
        return null;
    }

    return $value;
}

function humanDuration(int $seconds): string
{
    $seconds = max(0, $seconds);
    $days = intdiv($seconds, 86400);
    $hours = intdiv($seconds % 86400, 3600);

    $parts = [];
    if ($days > 0) $parts[] = $days.' day'.($days === 1 ? '' : 's');
    if ($hours > 0) $parts[] = $hours.' hour'.($hours === 1 ? '' : 's');

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

    $u['balance_unlimited'] = ($u['role'] === 'owner');

    return $u;
}

function visibleKeyRows(array $user, ?string $filter = null): array
{
    LicenseService::expireDue();

    $pdo = Database::pdo();

    $sql = "SELECT k.*,u.username owner_name,c.username creator_name,
                   (SELECT COUNT(*) FROM license_devices d WHERE d.license_key_id=k.id) AS device_count
            FROM license_keys k
            JOIN users u ON u.id=k.owner_user_id
            JOIN users c ON c.id=k.created_by";

    $where = [];
    $params = [];

    if ($user['role'] === 'admin') {
        $where[] = "u.role<>'owner'";
    } elseif ($user['role'] === 'reseller') {
        $where[] = "(k.created_by=? OR k.owner_user_id=? OR u.referred_by=?)";
        $params[] = $user['id'];
        $params[] = $user['id'];
        $params[] = $user['id'];
    } elseif ($user['role'] === 'user') {
        $where[] = "k.owner_user_id=?";
        $params[] = $user['id'];
    }

    if ($filter === 'expired') {
        $where[] = "k.status='expired'";
    } elseif ($filter === 'current') {
        $where[] = "k.status<>'expired'";
    } elseif (in_array($filter, ['unused','active','disabled'], true)) {
        $where[] = "k.status=?";
        $params[] = $filter;
    }

    if ($where) {
        $sql .= ' WHERE '.implode(' AND ', $where);
    }

    $sql .= ' ORDER BY k.id DESC LIMIT 500';

    $q = $pdo->prepare($sql);
    $q->execute($params);

    return $q->fetchAll() ?: [];
}

function canTarget(array $actor, int $targetId): ?array
{
    $q = Database::pdo()->prepare(
        'SELECT id,username,role,referred_by,status FROM users WHERE id=? LIMIT 1'
    );
    $q->execute([$targetId]);
    $target = $q->fetch();

    if (!$target || $target['status'] !== 'active') return null;

    if ($actor['role'] === 'owner') return $target;

    if ($actor['role'] === 'admin' && $target['role'] !== 'owner') {
        return $target;
    }

    if (
        $actor['role'] === 'reseller'
        && (
            (int)$target['id'] === (int)$actor['id']
            || (int)$target['referred_by'] === (int)$actor['id']
        )
    ) {
        return $target;
    }

    return null;
}

function allowedDeviceCount(int $value): bool
{
    return in_array($value, [10,20,30,50,100,500,1000], true);
}

try {
    // Public loader-facing key validation. The first successful validation starts the timer.
    if (
        ($path === '/api/v1/license/activate' || $path === '/api/v1/license/validate')
        && $method === 'POST'
    ) {
        Security::rateLimit('license-validate', 120, 3600);

        $b = jsonBody();

        $result = LicenseService::activate(
            (string)($b['key'] ?? ''),
            (string)($b['device_id'] ?? ''),
            (string)($b['device_label'] ?? '')
        );

        $status = ($result['ok'] ?? false)
            ? 200
            : (($result['code'] ?? '') === 'INVALID_KEY' ? 404 : 403);

        jsonOut($result, $status);
    }

    // Username/password API login for panel users/resellers/admins.
    if ($path === '/api/v1/auth/login' && $method === 'POST') {
        Security::rateLimit('api-login', 10, 600);

        $b = jsonBody();
        $username = strtolower(trim((string)($b['username'] ?? '')));
        $password = (string)($b['password'] ?? '');

        $q = Database::pdo()->prepare('SELECT * FROM users WHERE username=? LIMIT 1');
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
            Security::audit($u ? (int)$u['id'] : null, 'api_login_failed');
            jsonOut(['ok'=>false, 'error'=>'Invalid credentials'], 401);
        }

        $token = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $hash = hash('sha256', $token);
        $ttl = (int)Config::get('token_ttl');

        $expiresAt = (new DateTimeImmutable())
            ->add(new DateInterval('PT'.$ttl.'S'))
            ->format('Y-m-d H:i:s');

        Database::pdo()->prepare('DELETE FROM api_tokens WHERE expires_at<=NOW()')->execute();

        Database::pdo()
            ->prepare('INSERT INTO api_tokens(user_id,token_hash,expires_at) VALUES(?,?,?)')
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
                'balance'=>$u['role'] === 'owner' ? null : (int)$u['balance'],
                'balance_unlimited'=>$u['role'] === 'owner',
            ],
        ]);
    }

    if ($path === '/api/v1/me' && $method === 'GET') {
        $u = bearerUser();
        jsonOut(['ok'=>true, 'user'=>$u]);
    }

    if ($path === '/api/v1/licenses' && $method === 'GET') {
        $u = bearerUser();
        $rows = visibleKeyRows($u, null);
        $out = [];

        foreach ($rows as $r) {
            $out[] = [
                'id'=>(int)$r['id'],
                'key'=>Crypto::decrypt($r['key_cipher'], $r['key_iv'], $r['key_tag']),
                'owner'=>$r['owner_name'],
                'label'=>$r['label'],
                'status'=>$r['status'],
                'duration_seconds'=>(int)$r['duration_seconds'],
                'activated_at'=>$r['activated_at'],
                'expires_at'=>$r['expires_at'],
                'last_used_at'=>$r['last_used_at'],
                'max_devices'=>(int)$r['max_devices'],
                'used_devices'=>(int)$r['device_count'],
                'created_at'=>$r['created_at'],
            ];
        }

        jsonOut(['ok'=>true, 'licenses'=>$out]);
    }

    if ($path === '/' && $method === 'GET') {
        redirectTo(Auth::user() ? '/dashboard' : '/login');
    }

    if ($path === '/login' && $method === 'GET') {
        if (Auth::user()) redirectTo('/dashboard');

        $body = '<section class="auth"><div class="card">'
            .'<div class="tabs"><a class="active" href="/login">Login</a><a href="/register">Register</a></div>'
            .'<h1>Welcome back</h1>'
            .'<p class="muted">Secure access to TeamDark control panel.</p>'
            .takeFlash()
            .'<form method="post" action="/login" class="stack">'
            .View::csrf()
            .'<div class="field"><label>Username</label><input name="username" autocomplete="username" required maxlength="32"></div>'
            .'<div class="field"><label>Password</label><input type="password" name="password" autocomplete="current-password" required maxlength="200"></div>'
            .'<button class="primary">Sign in</button>'
            .'</form></div></section>';

        View::page('Login', $body);
        exit;
    }

    if ($path === '/login' && $method === 'POST') {
        Security::verifyCsrf($_POST['csrf'] ?? null);

        if (!Auth::login(input('username'), (string)($_POST['password'] ?? ''))) {
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
        if (Auth::user()) redirectTo('/dashboard');

        $prefill = View::e((string)($_GET['ref'] ?? ''));

        $body = '<section class="auth"><div class="card">'
            .'<div class="tabs"><a href="/login">Login</a><a class="active" href="/register">Register</a></div>'
            .'<h1>Create account</h1>'
            .'<p class="muted">A valid referral code is required.</p>'
            .takeFlash()
            .'<form method="post" action="/register" class="stack">'
            .View::csrf()
            .'<div class="field"><label>Referral code</label><input name="referral" value="'.$prefill.'" required maxlength="32"></div>'
            .'<div class="field"><label>Username</label><input name="username" required minlength="3" maxlength="32" autocomplete="username"></div>'
            .'<div class="field"><label>Password</label><input type="password" name="password" required minlength="12" maxlength="200" autocomplete="new-password"></div>'
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
            flash('err', 'Username must be 3–32 chars: letters, numbers, dot, underscore or hyphen.');
            redirectTo('/register');
        }

        if (!validPassword($password)) {
            flash('err', 'Password must be 12+ characters with a letter, number and symbol.');
            redirectTo('/register?ref='.urlencode($ref));
        }

        $pdo = Database::pdo();

        $q = $pdo->prepare(
            "SELECT id FROM users WHERE referral_code=? AND status='active' LIMIT 1"
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

            $ins = $pdo->prepare(
                "INSERT INTO users(
                    username,password_hash,role,balance,referral_code,
                    referred_by,created_by,status
                 ) VALUES(?,?,'user',?,?,?,?, 'active')"
            );

            $ins->execute([
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
                $pdo->prepare('UPDATE users SET balance=balance+? WHERE id=?')
                    ->execute([$bonus, $referrer['id']]);

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
        LicenseService::expireDue();

        $pdo = Database::pdo();

        if ($user['role'] === 'owner') {
            $users = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
            $keys = (int)$pdo->query("SELECT COUNT(*) FROM license_keys WHERE status<>'expired'")->fetchColumn();
            $expired = (int)$pdo->query("SELECT COUNT(*) FROM license_keys WHERE status='expired'")->fetchColumn();
        } else {
            $q = $pdo->prepare('SELECT COUNT(*) FROM users WHERE referred_by=?');
            $q->execute([$user['id']]);
            $users = (int)$q->fetchColumn();
            $keys = count(visibleKeyRows($user, 'current'));
            $expired = count(visibleKeyRows($user, 'expired'));
        }

        $body = '<section class="hero">'
            .'<span class="tag">'.View::e(strtoupper($user['role'])).'</span>'
            .'<h1>Hello, '.View::e($user['username']).'</h1>'
            .'<p class="muted">Manage licenses, first-use timers, devices, referrals and credits.</p>'
            .'</section>'
            .takeFlash()
            .'<div class="grid">'
            .'<div class="card quarter"><div class="muted">Balance</div><div class="stat">'.View::e(balanceText($user)).'</div></div>'
            .'<div class="card quarter"><div class="muted">Current keys</div><div class="stat">'.$keys.'</div></div>'
            .'<div class="card quarter"><div class="muted">Expired keys</div><div class="stat">'.$expired.'</div></div>'
            .'<div class="card quarter"><div class="muted">Referrals</div><div class="stat">'.$users.'</div></div>'
            .'<div class="card half"><h3>Your referral code</h3>'
            .'<p class="key">'.View::e($user['referral_code']).'</p>'
            .'<button class="ghost" data-copy="'.View::e($user['referral_code']).'">Copy code</button>'
            .'</div>'
            .'<div class="card half"><h3>Account</h3>'
            .'<p class="muted">Role: '.View::e($user['role']).'<br>'
            .'Balance policy: '.(ownerHasUnlimitedBalance($user) ? 'Unlimited' : 'Credit based').'<br>'
            .'Created: '.View::e($user['created_at']).'</p>'
            .'</div></div>';

        View::page('Dashboard', $body, $user);
        exit;
    }

    if (($path === '/keys' || $path === '/keys/expired') && $method === 'GET') {
        $filter = $path === '/keys/expired' ? 'expired' : 'current';
        $rows = visibleKeyRows($user, $filter);
        $create = '';

        if (roleRank($user['role']) >= 20 && $filter !== 'expired') {
            $pdo = Database::pdo();

            if ($user['role'] === 'owner') {
                $targets = $pdo->query(
                    "SELECT id,username,role FROM users WHERE status='active' ORDER BY username LIMIT 500"
                )->fetchAll();
            } elseif ($user['role'] === 'admin') {
                $targets = $pdo->query(
                    "SELECT id,username,role FROM users WHERE status='active' AND role<>'owner' ORDER BY username LIMIT 500"
                )->fetchAll();
            } else {
                $q = $pdo->prepare(
                    "SELECT id,username,role
                     FROM users
                     WHERE status='active' AND (id=? OR referred_by=?)
                     ORDER BY username
                     LIMIT 300"
                );
                $q->execute([$user['id'], $user['id']]);
                $targets = $q->fetchAll();
            }

            $opts = '';
            foreach ($targets as $t) {
                $opts .= '<option value="'.(int)$t['id'].'">'
                    .View::e($t['username'].' • '.$t['role'])
                    .'</option>';
            }

            $deviceOptions = '';
            foreach ([10,20,30,50,100,500,1000] as $deviceLimit) {
                $deviceOptions .= '<option value="'.$deviceLimit.'">'.$deviceLimit.' devices</option>';
            }

            $create = '<div class="card">'
                .'<h3>Create license key</h3>'
                .'<p class="muted">Timer starts only when this key is successfully used on the first device.</p>'
                .'<form method="post" action="/keys/create" class="stack">'
                .View::csrf()
                .'<div class="field"><label>Assign to</label><select name="owner_id">'.$opts.'</select></div>'
                .'<div class="field"><label>Label</label><input name="label" maxlength="100" placeholder="Customer / plan note"></div>'
                .'<div class="form-row">'
                .'<div class="field"><label>Days</label><input type="number" name="duration_days" min="0" max="3650" value="30" required></div>'
                .'<div class="field"><label>Hours</label><input type="number" name="duration_hours" min="0" max="23" value="0" required></div>'
                .'</div>'
                .'<div class="field"><label>Maximum devices</label><select name="max_devices">'.$deviceOptions.'</select></div>'
                .'<button class="primary">Generate key</button>'
                .'</form>'
                .'<p class="muted">Reseller key cost: '.(int)Config::get('key_cost').' credit(s). Owner balance is unlimited.</p>'
                .'</div>';
        }

        $trs = '';

        foreach ($rows as $r) {
            try {
                $plain = Crypto::decrypt($r['key_cipher'], $r['key_iv'], $r['key_tag']);
            } catch (Throwable) {
                $plain = '[unavailable]';
            }

            $activation = $r['activated_at']
                ? View::e($r['activated_at'])
                : '<span class="muted">Not used yet</span>';

            $expiry = $r['expires_at']
                ? View::e($r['expires_at'])
                : '<span class="muted">Starts on first use</span>';

            $devices = (int)$r['device_count'].' / '.(int)$r['max_devices'];

            $trs .= '<tr>'
                .'<td><span class="key">'.View::e($plain).'</span><br>'
                .'<button class="ghost" data-copy="'.View::e($plain).'">Copy</button></td>'
                .'<td>'.View::e($r['owner_name']).'</td>'
                .'<td>'.View::e($r['label']).'</td>'
                .'<td>'.View::e(humanDuration((int)$r['duration_seconds'])).'</td>'
                .'<td><span class="tag">'.View::e($r['status']).'</span></td>'
                .'<td>'.$activation.'</td>'
                .'<td>'.$expiry.'</td>'
                .'<td>'.View::e($devices).'</td>'
                .'<td>'.View::e($r['last_used_at'] ?: 'Never').'</td>'
                .'</tr>';
        }

        if ($trs === '') {
            $trs = '<tr><td colspan="9" class="muted">No keys in this section.</td></tr>';
        }

        $body = '<section class="hero">'
            .'<h1>'.($filter === 'expired' ? 'Expired keys' : 'License keys').'</h1>'
            .'<p class="muted">First-use timer + automatic expiry + per-key device limits.</p>'
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
            .'<th>Key</th><th>Owner</th><th>Label</th><th>Duration</th><th>Status</th>'
            .'<th>Activated</th><th>Expires</th><th>Devices</th><th>Last use</th>'
            .'</tr></thead><tbody>'.$trs.'</tbody>'
            .'</table></div></div></div>';

        View::page($filter === 'expired' ? 'Expired Keys' : 'Keys', $body, $user);
        exit;
    }

    if ($path === '/keys/create' && $method === 'POST') {
        Security::verifyCsrf($_POST['csrf'] ?? null);
        Auth::requireRole($user, 'reseller');
        Security::rateLimit('key-create-'.$user['id'], 30, 3600);

        $target = canTarget($user, (int)($_POST['owner_id'] ?? 0));

        if (!$target) {
            flash('err', 'Target user not allowed.');
            redirectTo('/keys');
        }

        $label = substr(input('label'), 0, 100);
        $days = max(0, min(3650, (int)($_POST['duration_days'] ?? 0)));
        $hours = max(0, min(23, (int)($_POST['duration_hours'] ?? 0)));
        $durationSeconds = ($days * 86400) + ($hours * 3600);
        $maxDevices = (int)($_POST['max_devices'] ?? 10);

        if ($durationSeconds < 3600) {
            flash('err', 'Key duration must be at least 1 hour.');
            redirectTo('/keys');
        }

        if (!allowedDeviceCount($maxDevices)) {
            flash('err', 'Invalid maximum device count.');
            redirectTo('/keys');
        }

        $cost = $user['role'] === 'reseller'
            ? (int)Config::get('key_cost')
            : 0;

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            if ($cost > 0 && !ownerHasUnlimitedBalance($user)) {
                $lock = $pdo->prepare('SELECT balance FROM users WHERE id=? FOR UPDATE');
                $lock->execute([$user['id']]);
                $bal = (int)$lock->fetchColumn();

                if ($bal < $cost) {
                    throw new RuntimeException('Insufficient balance.');
                }

                $pdo->prepare('UPDATE users SET balance=balance-? WHERE id=?')
                    ->execute([$cost, $user['id']]);

                $pdo->prepare(
                    'INSERT INTO balance_ledger(user_id,actor_user_id,amount,reason) VALUES(?,?,?,?)'
                )->execute([
                    $user['id'],
                    $user['id'],
                    -$cost,
                    'License key creation',
                ]);
            }

            $plain = newLicense();
            [$cipher, $iv, $tag] = Crypto::encrypt($plain);
            $hash = hash('sha256', $plain);

            $pdo->prepare(
                "INSERT INTO license_keys(
                    owner_user_id,created_by,key_hash,key_cipher,key_iv,key_tag,
                    label,duration_seconds,activated_at,expires_at,last_used_at,max_devices,status
                 ) VALUES(?,?,?,?,?,?,?,?,NULL,NULL,NULL,?,'unused')"
            )->execute([
                $target['id'],
                $user['id'],
                $hash,
                $cipher,
                $iv,
                $tag,
                $label,
                $durationSeconds,
                $maxDevices,
            ]);

            $newId = (int)$pdo->lastInsertId();

            $pdo->commit();

            Security::audit((int)$user['id'], 'license_created', [
                'license_id'=>$newId,
                'owner_id'=>(int)$target['id'],
                'duration_seconds'=>$durationSeconds,
                'max_devices'=>$maxDevices,
            ]);

            flash('ok', 'License generated. Timer will start on first successful use.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            flash(
                'err',
                $e instanceof RuntimeException
                    ? $e->getMessage()
                    : 'Could not create key.'
            );
        }

        redirectTo('/keys');
    }

    if ($path === '/users' && $method === 'GET') {
        Auth::requireRole($user, 'admin');

        $pdo = Database::pdo();

        if ($user['role'] === 'owner') {
            $rows = $pdo->query(
                'SELECT id,username,role,balance,referral_code,status,created_at FROM users ORDER BY id DESC LIMIT 500'
            )->fetchAll();
        } else {
            $q = $pdo->prepare(
                "SELECT id,username,role,balance,referral_code,status,created_at
                 FROM users
                 WHERE role<>'owner'
                 ORDER BY id DESC
                 LIMIT 500"
            );
            $q->execute();
            $rows = $q->fetchAll();
        }

        $roles = $user['role'] === 'owner'
            ? ['admin','reseller','user']
            : ['reseller','user'];

        $roleOpts = '';
        foreach ($roles as $r) {
            $roleOpts .= '<option>'.View::e($r).'</option>';
        }

        $trs = '';

        foreach ($rows as $r) {
            $rowBalance = $r['role'] === 'owner'
                ? '∞'
                : number_format((int)$r['balance']);

            $canAdjust = Auth::canManageRole($user, $r['role']);

            $adjust = $canAdjust
                ? '<form method="post" action="/users/balance" class="inline balance-form">'
                    .View::csrf()
                    .'<input type="hidden" name="user_id" value="'.(int)$r['id'].'">'
                    .'<input name="amount" type="number" placeholder="± credits">'
                    .'<button class="ghost">Apply</button>'
                    .'</form>'
                : '<span class="muted">—</span>';

            $trs .= '<tr>'
                .'<td>'.(int)$r['id'].'</td>'
                .'<td>'.View::e($r['username']).'</td>'
                .'<td><span class="tag">'.View::e($r['role']).'</span></td>'
                .'<td>'.View::e($rowBalance).'</td>'
                .'<td class="key">'.View::e($r['referral_code']).'</td>'
                .'<td>'.View::e($r['status']).'</td>'
                .'<td>'.$adjust.'</td>'
                .'</tr>';
        }

        $openingMax = $user['role'] === 'owner'
            ? ''
            : ' max="100000"';

        $body = '<section class="hero">'
            .'<h1>Users</h1>'
            .'<p class="muted">Owner has unlimited balance and can assign very large balances to managed accounts.</p>'
            .'</section>'
            .takeFlash()
            .'<div class="grid">'
            .'<div class="card half"><h3>Create managed user</h3>'
            .'<form method="post" action="/users/create" class="stack">'
            .View::csrf()
            .'<div class="field"><label>Username</label><input name="username" required maxlength="32"></div>'
            .'<div class="field"><label>Password</label><input type="password" name="password" required minlength="12" maxlength="200"></div>'
            .'<div class="field"><label>Role</label><select name="role">'.$roleOpts.'</select></div>'
            .'<div class="field"><label>Opening balance</label><input type="number" name="balance" min="0"'.$openingMax.' value="0"></div>'
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
            flash('err', 'Invalid user, password, role or opening balance.');
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

            Security::audit((int)$user['id'], 'managed_user_created', [
                'target_id'=>$id,
                'role'=>$role,
                'opening_balance'=>$balance,
            ]);

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

        $target = canTarget($user, $targetId);

        if (!$target || !Auth::canManageRole($user, $target['role'])) {
            flash('err', 'Target user not allowed.');
            redirectTo('/users');
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $q = $pdo->prepare('SELECT balance FROM users WHERE id=? FOR UPDATE');
            $q->execute([$targetId]);
            $current = (int)$q->fetchColumn();

            if ($current + $amount < 0) {
                throw new RuntimeException('Balance cannot go below zero.');
            }

            $pdo->prepare('UPDATE users SET balance=balance+? WHERE id=?')
                ->execute([$amount, $targetId]);

            $pdo->prepare(
                'INSERT INTO balance_ledger(user_id,actor_user_id,amount,reason) VALUES(?,?,?,?)'
            )->execute([
                $targetId,
                $user['id'],
                $amount,
                'Manual balance adjustment',
            ]);

            $pdo->commit();

            Security::audit((int)$user['id'], 'balance_adjusted', [
                'target_id'=>$targetId,
                'amount'=>$amount,
            ]);

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
        '<section class="auth"><div class="card"><h1>404</h1><p class="muted">Route not found.</p><a class="btn" href="/">Go home</a></div></section>',
        Auth::user()
    );
} catch (Throwable $e) {
    if (str_starts_with($path, '/api/')) {
        jsonOut(['ok'=>false, 'error'=>'Request failed'], 400);
    }

    http_response_code(400);
    $u = Auth::user();

    View::page(
        'Request failed',
        '<section class="auth"><div class="card"><h1>Request failed</h1><div class="alert">'
            .View::e($e->getMessage())
            .'</div><a class="btn" href="/">Go back</a></div></section>',
        $u
    );
}
