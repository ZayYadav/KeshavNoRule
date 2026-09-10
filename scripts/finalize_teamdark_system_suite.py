from pathlib import Path


def load(path):
    p = Path(path)
    return p, p.read_text()


def replace_once(s, old, new, path):
    if old not in s:
        raise SystemExit(f"missing marker in {path}: {old[:120]!r}")
    return s.replace(old, new, 1)


# View: add Extend Duration navigation and complete live rebranding.
p, s = load('teamdark-panel/app/View.php')
s = replace_once(
    s,
    "            $nav .= '<div class=\"nav-group\"><span class=\"nav-label\">Management</span>'\n                .self::navLink('/users', 'Manage Users', 'users', $path)",
    "            $nav .= '<div class=\"nav-group\"><span class=\"nav-label\">Management</span>'\n                .self::navLink('/keys/extend', 'Extend Duration', 'keys', $path)\n                .self::navLink('/users', 'Manage Users', 'users', $path)",
    p,
)
s = s.replace(".'<a class=\"sidebar-brand\" href=\"/dashboard\"><span class=\"brand-mark\"><b>TD</b><i></i></span>'", ".'<a class=\"sidebar-brand\" href=\"/dashboard\"><span class=\"brand-mark\"><b>'.$brandMark.'</b><i></i></span>'")
s = s.replace(".'<div class=\"announcement-mark\">TD</div>'", ".'<div class=\"announcement-mark\">'.$brandMark.'</div>'")
s = s.replace(".'<small>Team Dark • '.self::e((string)($settings['announcement_published_at'] ?: 'Official update')).'</small></div>'", ".'<small>'.$app.' • '.self::e((string)($settings['announcement_published_at'] ?: 'Official update')).'</small></div>'")
s = s.replace(".'<button class=\"mobile-menu\" type=\"button\" data-sidebar-toggle aria-controls=\"app-sidebar\" aria-expanded=\"false\">'\n            .self::icon('menu').'<span>Menu</span></button><div class=\"breadcrumb\"><span>TeamDark</span>'", ".'<button class=\"mobile-menu\" type=\"button\" data-sidebar-toggle aria-controls=\"app-sidebar\" aria-expanded=\"false\">'\n            .self::icon('menu').'<span>Menu</span></button><div class=\"breadcrumb\"><span>'.$app.'</span>'")
s = s.replace("'<div class=\"eyebrow\">TEAM DARK / ACCESS</div><h2>Your network.<br>Your control.</h2>", "'<div class=\"eyebrow\">'.$app.' / ACCESS</div><h2>Your network.<br>Your control.</h2>")

# Splash uses current brand mark/name while retaining configured splash title/subtitle.
marker = """        $title = self::e((string)($settings['splash_title'] ?? 'TEAM DARK'));
        $subtitle = self::e((string)($settings['splash_subtitle'] ?? 'Secure control plane'));
        $stars = str_repeat('<i></i>', 18);
"""
replacement = """        $brandName = self::e(trim((string)($settings['brand_name'] ?? '')) ?: (string)Config::get('app_name'));
        $brandMark = self::e(strtoupper(trim((string)($settings['brand_mark'] ?? 'TD'))) ?: 'TD');
        $title = self::e((string)($settings['splash_title'] ?? 'TEAM DARK'));
        $subtitle = self::e((string)($settings['splash_subtitle'] ?? 'Secure control plane'));
        $stars = str_repeat('<i></i>', 18);
"""
s = replace_once(s, marker, replacement, p)
s = s.replace('aria-label="Team Dark cinematic opening"', 'aria-label="'.$brandName.' cinematic opening"')
s = s.replace(".'<div class=\"td-cinema-overline\"><span>TEAM DARK</span><i></i><span>SECURE PREMIERE</span></div>'", ".'<div class=\"td-cinema-overline\"><span>'.$brandName.'</span><i></i><span>SECURE PREMIERE</span></div>'")
s = s.replace(".'<div class=\"td-splash-logo\"><span>TD</span><i></i></div>'", ".'<div class=\"td-splash-logo\"><span>'.$brandMark.'</span><i></i></div>'")
p.write_text(s)

# Keys: a real dedicated Extend Duration view using current KeyEditor permissions.
p, s = load('teamdark-panel/public/index.php')
s = replace_once(
    s,
    "    if (($path === '/keys' || $path === '/keys/expired') && $method === 'GET') {\n        $filter = $path === '/keys/expired' ? 'expired' : 'current';",
    "    if (in_array($path, ['/keys','/keys/expired','/keys/extend'], true) && $method === 'GET') {\n        $extendMode = $path === '/keys/extend';\n        $filter = $path === '/keys/expired' ? 'expired' : 'current';",
    p,
)
s = replace_once(s, "        $create = $filter === 'current'\n", "        $create = ($filter === 'current' && !$extendMode)\n", p)
old = """            $actions = $canRevealSecret
                ? '<button type="button" class="ghost compact" data-copy="'.View::e($plain).'">Copy</button>'
                : '<span class="tag">SECRET PROTECTED</span>';
"""
new = """            $actions = $canRevealSecret
                ? '<button type="button" class="ghost compact" data-copy="'.View::e($plain).'">Copy</button>'
                    .'<a class="ghost compact" href="/key-edit?id='.(int)$row['id'].'">Extend / Edit</a>'
                : '<span class="tag">SECRET PROTECTED</span>';
"""
s = replace_once(s, old, new, p)
s = replace_once(
    s,
    ".'<h1>'.($filter === 'expired' ? 'Expired Keys' : 'Keys').'</h1>'\n            .'<p class=\"muted\">Self-owned keys with one-tap block, reset and delete controls.</p></div>'\n            .($filter === 'current'",
    ".'<h1>'.($extendMode ? 'Extend Duration' : ($filter === 'expired' ? 'Expired Keys' : 'Keys')).'</h1>'\n            .'<p class=\"muted\">'.($extendMode ? 'Select a key and open Extend / Edit to change its finite validity with the existing permission and credit rules.' : 'Self-owned keys with one-tap block, reset and delete controls.').'</p></div>'\n            .($filter === 'current' && !$extendMode",
    p,
)
s = replace_once(
    s,
    ".'<a '.($filter === 'current' ? 'class=\"active\"' : '').' href=\"/keys\">Current</a>'\n            .'<a '.($filter === 'expired' ? 'class=\"active\"' : '').' href=\"/keys/expired\">Expired</a>'",
    ".'<a '.(!$extendMode && $filter === 'current' ? 'class=\"active\"' : '').' href=\"/keys\">Current</a>'\n            .'<a '.($extendMode ? 'class=\"active\"' : '').' href=\"/keys/extend\">Extend Duration</a>'\n            .'<a '.($filter === 'expired' ? 'class=\"active\"' : '').' href=\"/keys/expired\">Expired</a>'",
    p,
)
s = replace_once(
    s,
    "            $filter === 'expired' ? 'Expired Keys' : 'Keys',\n            $body,",
    "            $extendMode ? 'Extend Duration' : ($filter === 'expired' ? 'Expired Keys' : 'Keys'),\n            $body,",
    p,
)
# Page-view audit includes dedicated extend route.
s = s.replace("'/keys','/keys/expired','/keys/devices'", "'/keys','/keys/expired','/keys/extend','/keys/devices'")
p.write_text(s)

# Owner overview includes the dedicated duration-management entry.
p, s = load('teamdark-panel/app/OwnerSystem.php')
marker = """            ['/owner/server','Server & Maintenance',($s['panel_online'] ? 'Online' : 'Offline').' • registrations '.($s['registration_open'] ? 'on' : 'off')],
            ['/owner/device-policy','One Device / Device Policy',($s['force_one_device_new_keys'] ? 'One-device force ON' : 'Default '.(int)$s['default_max_devices'].' devices')],
"""
replacement = """            ['/owner/server','Server & Maintenance',($s['panel_online'] ? 'Online' : 'Offline').' • registrations '.($s['registration_open'] ? 'on' : 'off')],
            ['/keys/extend','Extend Duration','Edit key validity through the existing entitlement-aware Key Editor'],
            ['/owner/device-policy','One Device / Device Policy',($s['force_one_device_new_keys'] ? 'One-device force ON' : 'Default '.(int)$s['default_max_devices'].' devices')],
"""
s = replace_once(s, marker, replacement, p)
p.write_text(s)
