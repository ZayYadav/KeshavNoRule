from pathlib import Path
import re


def load(path):
    p = Path(path)
    return p, p.read_text()


def must_replace(s, old, new, path):
    if old not in s:
        raise SystemExit(f"missing marker in {path}: {old[:100]!r}")
    return s.replace(old, new, 1)


# PanelControl: modular settings defaults and preservation.
p, s = load('teamdark-panel/app/PanelControl.php')
s = must_replace(s,
"        'splash_version'=>1,\n    ];",
"        'splash_version'=>1,\n        'default_max_devices'=>10,\n        'force_one_device_new_keys'=>false,\n        'generated_key_prefix'=>'Team-Dark-',\n        'generated_key_length'=>16,\n        'key_cost_per_day'=>-1,\n        'blocked_ip_rules'=>[],\n        'brand_name'=>'',\n        'brand_subtitle'=>'Secure control plane',\n        'brand_footer'=>'TeamDark secure control plane',\n        'brand_mark'=>'TD',\n        'package_enabled'=>false,\n        'package_name'=>'',\n        'package_version'=>'',\n        'package_notes'=>'',\n        'package_file_id'=>0,\n        'panel_release_label'=>'',\n    ];", p)
s = must_replace(s, "        $settings = self::DEFAULTS;\n", "        $settings = array_replace(self::DEFAULTS, $current);\n", p)
p.write_text(s)

# Security: owner-safe IP deny policy.
p, s = load('teamdark-panel/app/Security.php')
marker = "    private static function trustedProxy(string $remote): bool\n"
insert = """    public static function ownerIpPolicyBlocked(?array $actor): bool
    {
        if (($actor['role'] ?? '') === 'owner') {
            return false;
        }

        $rules = PanelControl::settings()['blocked_ip_rules'] ?? [];
        if (!is_array($rules) || $rules === []) {
            return false;
        }

        $ip = self::clientIp();
        foreach ($rules as $rule) {
            $rule = trim((string)$rule);
            if ($rule !== '' && self::ipInCidr($ip, $rule)) {
                return true;
            }
        }
        return false;
    }

"""
if insert.strip() not in s:
    s = must_replace(s, marker, insert + marker, p)
p.write_text(s)

# KeyManager: pricing, auto-format and One Device policy.
p, s = load('teamdark-panel/app/KeyManager.php')
old = """    public static function price(int $durationSeconds, bool $unlimitedExpiry): int
    {
        if ($unlimitedExpiry) {
            return max(0, (int)Config::get('unlimited_key_cost', 100));
        }

        $days = max(1, (int)ceil(max(86400, $durationSeconds) / 86400));
        return $days * max(0, (int)Config::get('key_cost', 1));
    }
"""
new = """    public static function price(int $durationSeconds, bool $unlimitedExpiry): int
    {
        if ($unlimitedExpiry) {
            return max(0, (int)Config::get('unlimited_key_cost', 100));
        }

        $settings = PanelControl::settings();
        $configured = (int)($settings['key_cost_per_day'] ?? -1);
        $daily = $configured >= 0
            ? $configured
            : max(0, (int)Config::get('key_cost', 1));
        $days = max(1, (int)ceil(max(86400, $durationSeconds) / 86400));
        return $days * $daily;
    }
"""
s = must_replace(s, old, new, p)
marker = """        if (($actor['role'] ?? '') !== 'owner' && ($unlimitedExpiry || $unlimitedDevices)) {
            throw new RuntimeException('Unlimited validity and unlimited devices are reserved for Owner.');
        }

"""
s = must_replace(s, marker, marker + """        $devicePolicy = PanelControl::settings();
        if (!$unlimitedDevices && (bool)($devicePolicy['force_one_device_new_keys'] ?? false)) {
            $maxDevices = 1;
        }

""", p)
old = """    private static function newLicense(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $token = '';

        for ($i = 0; $i < 16; $i++) {
            $token .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return 'Team-Dark-'.$token;
    }
"""
new = """    private static function newLicense(): string
    {
        $settings = PanelControl::settings();
        $prefix = trim((string)($settings['generated_key_prefix'] ?? 'Team-Dark-'));
        if ($prefix === '' || strlen($prefix) > 30 || !preg_match('/^[A-Za-z0-9_-]+$/', $prefix)) {
            $prefix = 'Team-Dark-';
        }
        $length = max(8, min(32, (int)($settings['generated_key_length'] ?? 16)));
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $token = '';

        for ($i = 0; $i < $length; $i++) {
            $token .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $prefix.$token;
    }
"""
s = must_replace(s, old, new, p)
p.write_text(s)

# OwnerSystem: validate package file before persisting it.
p, s = load('teamdark-panel/app/OwnerSystem.php')
s = s.replace('        self::$method($actor, $flash);', '        self::{$method}($actor, $flash);')
marker = """        $revision = (int)($input['revision'] ?? -1);
        if ($revision < 0) throw new RuntimeException('Missing settings revision.');

        self::saveTransaction"""
repl = """        $revision = (int)($input['revision'] ?? -1);
        if ($revision < 0) throw new RuntimeException('Missing settings revision.');

        if ($section === 'packages') {
            $fileId = max(0, (int)($input['package_file_id'] ?? 0));
            if ($fileId > 0) {
                $q = Database::pdo()->prepare('SELECT 1 FROM user_uploads WHERE id=? AND user_id=? LIMIT 1');
                $q->execute([$fileId, (int)$actor['id']]);
                if (!$q->fetchColumn()) throw new RuntimeException('Selected package file must belong to the Owner File Manager.');
            }
        }

        self::saveTransaction"""
s = must_replace(s, marker, repl, p)
trailing = """
        if ($section === 'packages') {
            $fileId = max(0, (int)($input['package_file_id'] ?? 0));
            if ($fileId > 0) {
                $q = Database::pdo()->prepare('SELECT 1 FROM user_uploads WHERE id=? AND user_id=? LIMIT 1');
                $q->execute([$fileId, (int)$actor['id']]);
                if (!$q->fetchColumn()) throw new RuntimeException('Selected package file must belong to the Owner File Manager.');
            }
        }

        return 'Settings saved.';
"""
s = must_replace(s, trailing, "\n        return 'Settings saved.';\n", p)
p.write_text(s)

# View: brand-aware shell, screenshot-style grouped navigation, System CSS.
p, s = load('teamdark-panel/app/View.php')
old = """        $active = $path === $href
            || ($href === '/keys' && str_starts_with($path, '/keys'))
            || ($href === '/users' && str_starts_with($path, '/users'));
"""
new = """        $hrefPath = (string)(parse_url($href, PHP_URL_PATH) ?: $href);
        $active = $path === $hrefPath
            || ($hrefPath === '/keys' && str_starts_with($path, '/keys'))
            || ($hrefPath === '/users' && str_starts_with($path, '/users'));
"""
s = must_replace(s, old, new, p)
old = """        $app = self::e(Config::get('app_name'));
        $safeTitle = self::e($title);
        $path = (string)(
            parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH)
            ?: '/'
        );
        $csrf = self::e(Security::csrfToken());
        $settings = PanelControl::settings();
        $splash = self::splash($settings);
"""
new = """        $settings = PanelControl::settings();
        $rawApp = trim((string)($settings['brand_name'] ?? '')) ?: (string)Config::get('app_name');
        $app = self::e($rawApp);
        $brandSubtitle = self::e(trim((string)($settings['brand_subtitle'] ?? '')) ?: 'Secure control plane');
        $brandFooter = self::e(trim((string)($settings['brand_footer'] ?? '')) ?: $rawApp.' secure control plane');
        $brandMark = self::e(strtoupper(trim((string)($settings['brand_mark'] ?? 'TD'))) ?: 'TD');
        $safeTitle = self::e($title);
        $path = (string)(
            parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH)
            ?: '/'
        );
        $csrf = self::e(Security::csrfToken());
        $splash = self::splash($settings);
"""
s = must_replace(s, old, new, p)
s = must_replace(s, "            .'<link rel=\"stylesheet\" href=\"/assets/cinematic.css?v=20260910-2\">'\n", "            .'<link rel=\"stylesheet\" href=\"/assets/cinematic.css?v=20260910-2\">'\n            .'<link rel=\"stylesheet\" href=\"/assets/system-console.css?v=20260910-1\">'\n", p)
s = s.replace("'<span class=\"brand-mark\"><b>TD</b><i></i></span>'", "'<span class=\"brand-mark\"><b>'.$brandMark.'</b><i></i></span>'")
s = s.replace(".'<span><strong>'.$app.'</strong><small>Secure control plane</small></span></a>'", ".'<span><strong>'.$app.'</strong><small>'.$brandSubtitle.'</small></span></a>'")
s = s.replace(".'<span><strong>'.$app.'</strong><small>'.self::e(ucfirst($user['role'])).' console</small></span></a>'", ".'<span><strong>'.$app.'</strong><small>'.$brandSubtitle.'</small></span></a>'")
s = s.replace(".'<footer><span><i class=\"footer-dot\"></i> TeamDark secure control plane</span><span>Session encrypted • Personal theme enabled</span></footer>'", ".'<footer><span><i class=\"footer-dot\"></i> '.$brandFooter.'</span><span>Session encrypted • Personal theme enabled</span></footer>'")
start = s.index("        $nav = '<div class=\"nav-group\"><span class=\"nav-label\">Workspace</span>'")
end = s.index("\n\n        $notice = '';", start)
nav = """        $nav = '<div class="nav-group"><span class="nav-label">Main</span>'
            .self::navLink('/dashboard', 'Dashboard', 'dashboard', $path)
            .self::navLink('/keys', 'All Keys', 'keys', $path)
            .self::navLink('/keys#key-generator', 'Generate Key', 'spark', $path)
            .self::navLink('/keys?generator=random#key-generator', 'Random Keys', 'spark', $path)
            .self::navLink('/files', 'File Manager', 'spark', $path)
            .'</div>';

        if (in_array($user['role'], ['owner','admin'], true)) {
            $nav .= '<div class="nav-group"><span class="nav-label">Management</span>'
                .self::navLink('/users', 'Manage Users', 'users', $path)
                .self::navLink('/users#referral-center', 'Referral Codes', 'users', $path)
                .self::navLink('/users#balance-center', 'Balance', 'spark', $path);
            if ($user['role'] === 'owner') {
                $nav .= self::navLink('/owner/users', 'User Insights', 'users', $path)
                    .self::navLink('/telegram-users', 'Telegram Users', 'telegram', $path);
            }
            $nav .= '</div>';
        }

        if (($user['role'] ?? '') === 'owner') {
            $nav .= '<div class="nav-group system-group"><span class="nav-label">System</span>'
                .self::navLink('/owner/system', 'System Overview', 'dashboard', $path)
                .self::navLink('/owner/server', 'Server & Maint.', 'dashboard', $path)
                .self::navLink('/owner/device-policy', 'One Device', 'keys', $path)
                .self::navLink('/owner/key-format', 'Key Format', 'keys', $path)
                .self::navLink('/owner/pricing', 'Pricing', 'spark', $path)
                .self::navLink('/owner/ip-management', 'IP Management', 'activity', $path)
                .self::navLink('/owner/rebranding', 'Manage Rebranding', 'spark', $path)
                .self::navLink('/owner/security', 'Heartbeat & Security', 'activity', $path)
                .self::navLink('/owner/session-controls', 'Heartbeat Kicks', 'activity', $path)
                .self::navLink('/owner/packages', 'Package Manager', 'spark', $path)
                .self::navLink('/owner/alerts', 'Panel Alert', 'telegram', $path)
                .self::navLink('/owner/update', 'Panel Update', 'spark', $path)
                .self::navLink('/owner/settings', 'Settings', 'dashboard', $path)
                .self::navLink('/activity', 'Activity Logs', 'activity', $path)
                .self::navLink('/owner/developer', 'Developer API', 'spark', $path)
                .'</div>';
        }"""
s = s[:start] + nav + s[end:]
s = s.replace("if (($user['role'] ?? '') === 'owner' && $path === '/owner/settings')", "if (($user['role'] ?? '') === 'owner' && $path === '/owner/developer')")
p.write_text(s)

# Remove old JS-injected Binary Vault entry.
p, s = load('teamdark-panel/public/assets/owner-tools.js')
s = re.sub(r"\n    var nav = document\.querySelector\('\.sidebar nav \.nav-group'\);\n    if \(nav && !document\.querySelector\('\[data-private-vault-link\]'\)\) \{.*?\n    \}\n", "\n", s, count=1, flags=re.S)
s = s.replace('Binary Vault', 'File Manager')
p.write_text(s)

# Vault UI rename + load CDN purge implementation.
p, s = load('teamdark-panel/public/vault.php')
s = s.replace("use TeamDark\\Panel\\{Auth,Config,Database,PanelControl,Security,UploadManager,View};", "use TeamDark\\Panel\\{Auth,CdnCache,Config,Database,PanelControl,Security,UploadManager,View};")
s = s.replace("['Config','Database','Security','PanelControl','Auth','View','UploadManager']", "['Config','Database','Security','PanelControl','Auth','View','CdnCache','UploadManager']")
s = s.replace('Binary Vault', 'File Manager').replace('PRIVATE STORAGE', 'PRIVATE FILE MANAGER')
p.write_text(s)
p, s = load('teamdark-panel/public/vault-entry.php')
s = s.replace('Binary Vault', 'File Manager').replace('TEAM DARK / BINARY VAULT', 'TEAM DARK / FILE MANAGER')
p.write_text(s)

# Main controller bootstrap/routes.
p, s = load('teamdark-panel/public/index.php')
old = """use TeamDark\\Panel\\{PanelControl, OwnerConsole};
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
    'BroadcastService',
    'TwoFactorService',
] as $file) {
    require $root.'/app/'.$file.'.php';
}
"""
new = """use TeamDark\\Panel\\{PanelControl, OwnerConsole, OwnerSystem, CdnCache};

$root = dirname(__DIR__);

foreach ([
    'Config',
    'Database',
    'PanelControl',
    'Security',
    'Crypto',
    'Auth',
    'View',
    'LoaderAuthService',
    'KeyManager',
    'LicenseService',
    'ReferralManager',
    'TelegramService',
    'BroadcastService',
    'TwoFactorService',
    'CdnCache',
    'OwnerConsole',
    'OwnerSystem',
] as $file) {
    require_once $root.'/app/'.$file.'.php';
}
"""
s = must_replace(s, old, new, p)
s = must_replace(s, "    if (PanelControl::blocked($u)) jsonOut(['ok'=>false, 'error'=>'Panel under maintenance'], 503);\n\n    Database::pdo()", "    if (PanelControl::blocked($u)) jsonOut(['ok'=>false, 'error'=>'Panel under maintenance'], 503);\n    if (Security::ownerIpPolicyBlocked($u)) jsonOut(['ok'=>false, 'error'=>'Access denied by IP policy'], 403);\n\n    Database::pdo()", p)
s = must_replace(s, """        if (!PanelControl::settings()['registration_open']) {
            flash('err', 'New registrations are paused by the owner.');
            redirectTo('/register');
        }
        Security::rateLimit('register', 6, 3600);
""", """        if (!PanelControl::settings()['registration_open']) {
            flash('err', 'New registrations are paused by the owner.');
            redirectTo('/register');
        }
        if (Security::ownerIpPolicyBlocked(null)) {
            http_response_code(403);
            flash('err', 'Registration is not available from this network.');
            redirectTo('/register');
        }
        Security::rateLimit('register', 6, 3600);
""", p)
s = s.replace("        '/owner/settings',\n        '/owner/announcements/create',", "        '/owner/settings',\n        '/owner/system/save',\n        '/owner/system/action',\n        '/owner/announcements/create',")
s = must_replace(s, """    if (PanelControl::blocked($user)) redirectTo('/');
    if ($method === 'POST') {
""", """    if (PanelControl::blocked($user)) redirectTo('/');
    if (Security::ownerIpPolicyBlocked($user)) {
        http_response_code(403);
        View::page('Access denied', '<section class="auth"><div class="card"><h1>Network blocked</h1><p class="muted">This account cannot access the panel from the current IP policy.</p></div></section>', $user);
        exit;
    }
    if ($method === 'POST') {
""", p)
s = must_replace(s, """    if ($method === 'GET' && in_array($path, ['/dashboard','/keys','/keys/expired','/keys/devices','/users','/telegram-users','/activity','/owner/users','/owner/settings'], true)) {
        Security::audit((int)$user['id'], 'page_viewed', ['path'=>$path]);
    }
""", """    if ($method === 'GET' && (in_array($path, ['/dashboard','/keys','/keys/expired','/keys/devices','/users','/telegram-users','/activity','/owner/users'], true) || str_starts_with($path, '/owner/'))) {
        Security::audit((int)$user['id'], 'page_viewed', ['path'=>$path]);
    }
""", p)
insert_before = "    if ($path === '/owner/announcements/create' && $method === 'POST') {\n"
owner_routes = """    if ($path === '/owner/system/save' && $method === 'POST') {
        Auth::requireRole($user, 'owner');
        $section = input('section');
        try {
            $message = OwnerSystem::saveSection($user, $section, $_POST);
            flash('ok', $message);
        } catch (Throwable $e) {
            flash('err', safeMessage($e));
        }
        redirectTo(OwnerSystem::redirectForSection($section));
    }

    if ($path === '/owner/system/action' && $method === 'POST') {
        Auth::requireRole($user, 'owner');
        $returnTo = input('return_to', '/owner/system');
        $allowedReturn = ['/owner/system','/owner/session-controls','/owner/update','/owner/packages'];
        if (!in_array($returnTo, $allowedReturn, true)) $returnTo = '/owner/system';
        try {
            flash('ok', OwnerSystem::action($user, input('action'), $_POST));
        } catch (Throwable $e) {
            flash('err', safeMessage($e));
        }
        redirectTo($returnTo);
    }

    if ($method === 'GET' && OwnerSystem::handles($path)) {
        OwnerSystem::render($path, $user, takeFlash());
        exit;
    }

"""
s = must_replace(s, insert_before, owner_routes + insert_before, p)
s = s.replace("redirectTo('/owner/settings#announcements');", "redirectTo('/owner/alerts');")
old = """    if ($path === '/owner/settings' && $method === 'POST') {
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
"""
new = """    if ($path === '/owner/settings' && $method === 'POST') {
        Auth::requireRole($user, 'owner');
        try {
            PanelControl::save($user, $_POST);
            flash('ok', 'Legacy server controls updated.');
        } catch (Throwable $e) {
            flash('err', safeMessage($e));
        }
        redirectTo('/owner/server');
    }
"""
s = must_replace(s, old, new, p)
s = s.replace('href="/owner/settings"><span>⏻</span><div><strong>Server controls</strong><small>Panel access and availability</small></div></a>', 'href="/owner/system"><span>⏻</span><div><strong>System controls</strong><small>Dedicated owner modules</small></div></a>')
old = """        $deviceOptions = '';
        foreach (KeyManager::DEVICE_LIMITS as $limit) {
            $selected = $limit === 10 ? ' selected' : '';
            $deviceOptions .= '<option value="'.$limit.'"'.$selected.'>'
                .$limit.' devices</option>';
        }
"""
new = """        $keyPolicy = PanelControl::settings();
        $defaultDevices = (bool)($keyPolicy['force_one_device_new_keys'] ?? false)
            ? 1
            : (int)($keyPolicy['default_max_devices'] ?? 10);
        if (!in_array($defaultDevices, KeyManager::DEVICE_LIMITS, true)) $defaultDevices = 10;
        $deviceOptions = '';
        foreach (KeyManager::DEVICE_LIMITS as $limit) {
            $selected = $limit === $defaultDevices ? ' selected' : '';
            $deviceOptions .= '<option value="'.$limit.'"'.$selected.'>'
                .$limit.' devices</option>';
        }
"""
s = must_replace(s, old, new, p)
old = """        $pricing = ownerUnlimited($user)
            ? 'Owner generation cost: 0 credits. Unlimited validity and devices available.'
            : 'Timed cost: '.(int)Config::get('key_cost')
                .' credit(s) per day. Unlimited options are Owner-only.';
"""
new = """        $dailyPrice = KeyManager::price(86400, false);
        $pricing = ownerUnlimited($user)
            ? 'Owner generation cost: 0 credits. Unlimited validity and devices available.'
            : 'Timed cost: '.$dailyPrice.' credit(s) per day. Unlimited options are Owner-only.';
        $autoPrefix = (string)($keyPolicy['generated_key_prefix'] ?? 'Team-Dark-');
        $autoLength = max(8, min(32, (int)($keyPolicy['generated_key_length'] ?? 16)));
        $autoPreview = $autoPrefix.str_repeat('X', min($autoLength, 20)).($autoLength > 20 ? '…' : '');
"""
s = must_replace(s, old, new, p)
s = s.replace('<input name="custom_key" minlength="24" maxlength="80" placeholder="Team-Dark-MyVIPKey9" autocomplete="off">', '<input name="custom_key" minlength="5" maxlength="80" placeholder="Custom key • 5–80 characters" autocomplete="off">')
s = s.replace(".'<p class=\"hint\">'.$pricing.' Auto format: Team-Dark-XXXXXXXXX.</p>'", ".'<p class=\"hint\">'.$pricing.' Auto format: '.View::e($autoPreview).'.'.((bool)($keyPolicy['force_one_device_new_keys'] ?? false) ? ' One-device policy is active.' : '').'</p>'")
s = s.replace(".'<div class=\"card third spotlight\"><div class=\"eyebrow\">CREATE REFERRAL</div>'", ".'<div class=\"card third spotlight\" id=\"referral-center\"><div class=\"eyebrow\">CREATE REFERRAL</div>'")
s = s.replace(".'<div class=\"card quarter metric\"><div class=\"eyebrow\">TOTAL USERS</div>", ".'<div id=\"balance-center\"></div><div class=\"card quarter metric\"><div class=\"eyebrow\">TOTAL USERS</div>")
p.write_text(s)

# Secret hygiene check.
for check in Path('teamdark-panel').rglob('*'):
    if check.is_file() and check.suffix in {'.php', '.js', '.css', '.md', '.example', '.ini'}:
        txt = check.read_text(errors='ignore')
        if 'cfut_' in txt:
            raise SystemExit(f'Cloudflare token-like secret found in {check}')
