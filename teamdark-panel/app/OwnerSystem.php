<?php
declare(strict_types=1);

namespace TeamDark\Panel;

use PDO;
use RuntimeException;
use Throwable;

final class OwnerSystem
{
    private const ROUTES = [
        '/owner/system' => 'overview',
        '/owner/server' => 'server',
        '/owner/device-policy' => 'devices',
        '/owner/key-format' => 'keyFormat',
        '/owner/pricing' => 'pricing',
        '/owner/ip-management' => 'ipManagement',
        '/owner/rebranding' => 'rebranding',
        '/owner/security' => 'security',
        '/owner/session-controls' => 'sessions',
        '/owner/packages' => 'packages',
        '/owner/alerts' => 'alerts',
        '/owner/update' => 'updateCenter',
        '/owner/settings' => 'general',
        '/owner/developer' => 'developer',
    ];

    public static function handles(string $path): bool
    {
        return isset(self::ROUTES[$path]);
    }

    public static function render(string $path, array $actor, string $flash = ''): void
    {
        Auth::requireRole($actor, 'owner');
        $method = self::ROUTES[$path] ?? null;
        if (!$method) {
            throw new RuntimeException('Owner system page not found.');
        }
        self::{$method}($actor, $flash);
    }

    public static function redirectForSection(string $section): string
    {
        return [
            'server'=>'/owner/server',
            'devices'=>'/owner/device-policy',
            'key-format'=>'/owner/key-format',
            'pricing'=>'/owner/pricing',
            'ip'=>'/owner/ip-management',
            'branding'=>'/owner/rebranding',
            'packages'=>'/owner/packages',
            'update'=>'/owner/update',
        ][$section] ?? '/owner/system';
    }

    private static function hero(string $eyebrow, string $title, string $copy, string $right = ''): string
    {
        return '<section class="hero system-hero"><div><div class="eyebrow">'.View::e($eyebrow).'</div><h1>'.View::e($title).'</h1><p class="muted">'.View::e($copy).'</p></div>'.$right.'</section>';
    }

    private static function badge(bool $on, string $onText = 'ON', string $offText = 'OFF'): string
    {
        return '<span class="status-chip status-'.($on ? 'active' : 'disabled').'">'.View::e($on ? $onText : $offText).'</span>';
    }

    private static function currentSettings(): array
    {
        return PanelControl::settings();
    }

    private static function saveTransaction(array $actor, int $expectedRevision, callable $mutator, string $auditAction): void
    {
        Auth::requireRole($actor, 'owner');
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $row = $pdo->query('SELECT settings_json,revision FROM panel_settings WHERE id=1 FOR UPDATE')->fetch();
            if (!$row) {
                throw new RuntimeException('Panel settings are not initialized. Import database/schema.sql.');
            }
            $revision = (int)$row['revision'];
            if ($expectedRevision !== $revision) {
                throw new RuntimeException('Settings changed in another session. Reload and try again.');
            }
            $stored = json_decode((string)$row['settings_json'], true);
            $settings = array_replace(PanelControl::DEFAULTS, is_array($stored) ? $stored : []);
            $settings = $mutator($settings);
            $json = json_encode($settings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $q = $pdo->prepare('UPDATE panel_settings SET settings_json=?,revision=revision+1,updated_by=? WHERE id=1 AND revision=?');
            $q->execute([$json, (int)$actor['id'], $revision]);
            if ($q->rowCount() !== 1) {
                throw new RuntimeException('Settings update conflict. Reload and try again.');
            }
            Security::audit((int)$actor['id'], $auditAction, ['section'=>$auditAction]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public static function saveSection(array $actor, string $section, array $input): string
    {
        $revision = (int)($input['revision'] ?? -1);
        if ($revision < 0) throw new RuntimeException('Missing settings revision.');

        if ($section === 'packages') {
            $fileId = max(0, (int)($input['package_file_id'] ?? 0));
            if ($fileId > 0) {
                $q = Database::pdo()->prepare('SELECT 1 FROM user_uploads WHERE id=? AND user_id=? LIMIT 1');
                $q->execute([$fileId, (int)$actor['id']]);
                if (!$q->fetchColumn()) throw new RuntimeException('Selected package file must belong to the Owner File Manager.');
            }
        }

        self::saveTransaction($actor, $revision, static function (array $s) use ($section, $input): array {
            if ($section === 'server') {
                $s['panel_online'] = ($input['panel_online'] ?? '') === '1';
                $s['registration_open'] = ($input['registration_open'] ?? '') === '1';
                $s['generation_open'] = ($input['generation_open'] ?? '') === '1';
                $message = trim((string)($input['message'] ?? ''));
                if (strlen($message) > 500) throw new RuntimeException('Maintenance message must be 500 bytes or fewer.');
                $s['message'] = $message === '' ? PanelControl::DEFAULTS['message'] : $message;
                return $s;
            }

            if ($section === 'devices') {
                $limit = (int)($input['default_max_devices'] ?? 10);
                if (!in_array($limit, KeyManager::DEVICE_LIMITS, true)) throw new RuntimeException('Invalid default device limit.');
                $s['default_max_devices'] = $limit;
                $s['force_one_device_new_keys'] = ($input['force_one_device_new_keys'] ?? '') === '1';
                return $s;
            }

            if ($section === 'key-format') {
                $prefix = trim((string)($input['generated_key_prefix'] ?? ''));
                $length = (int)($input['generated_key_length'] ?? 16);
                if ($prefix === '' || strlen($prefix) > 30 || !preg_match('/^[A-Za-z0-9_-]+$/', $prefix)) {
                    throw new RuntimeException('Key prefix must be 1-30 characters using letters, numbers, underscore or dash.');
                }
                if ($length < 8 || $length > 32) throw new RuntimeException('Random key length must be between 8 and 32.');
                $s['generated_key_prefix'] = $prefix;
                $s['generated_key_length'] = $length;
                return $s;
            }

            if ($section === 'pricing') {
                $raw = trim((string)($input['key_cost_per_day'] ?? ''));
                if (!preg_match('/^\d{1,6}$/', $raw)) throw new RuntimeException('Daily key price must be between 0 and 100000 credits.');
                $value = (int)$raw;
                if ($value > 100000) throw new RuntimeException('Daily key price must be between 0 and 100000 credits.');
                $s['key_cost_per_day'] = $value;
                return $s;
            }

            if ($section === 'ip') {
                $raw = trim((string)($input['blocked_ip_rules'] ?? ''));
                $lines = preg_split('/\R+/', $raw) ?: [];
                $rules = [];
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '') continue;
                    if (!self::validIpRule($line)) throw new RuntimeException('Invalid IP/CIDR rule: '.$line);
                    $rules[$line] = $line;
                }
                if (count($rules) > 100) throw new RuntimeException('Maximum 100 IP/CIDR rules are allowed.');
                $s['blocked_ip_rules'] = array_values($rules);
                return $s;
            }

            if ($section === 'branding') {
                $name = trim((string)($input['brand_name'] ?? ''));
                $subtitle = trim((string)($input['brand_subtitle'] ?? ''));
                $footer = trim((string)($input['brand_footer'] ?? ''));
                $mark = strtoupper(trim((string)($input['brand_mark'] ?? 'TD')));
                if ($name === '' || strlen($name) > 40) throw new RuntimeException('Brand name must be 1-40 characters.');
                if (strlen($subtitle) > 120) throw new RuntimeException('Brand subtitle must be 120 characters or fewer.');
                if (strlen($footer) > 120) throw new RuntimeException('Footer text must be 120 characters or fewer.');
                if (!preg_match('/^[A-Z0-9]{1,3}$/', $mark)) throw new RuntimeException('Brand mark must be 1-3 letters or numbers.');

                $s['brand_name'] = $name;
                $s['brand_subtitle'] = $subtitle;
                $s['brand_footer'] = $footer;
                $s['brand_mark'] = $mark;

                $splashEnabled = ($input['splash_enabled'] ?? '') === '1';
                $splashTitle = trim((string)($input['splash_title'] ?? $name));
                $splashSubtitle = trim((string)($input['splash_subtitle'] ?? $subtitle));
                $duration = (int)($input['splash_duration_ms'] ?? 2400);
                if ($splashTitle === '' || strlen($splashTitle) > 60) throw new RuntimeException('Splash title must be 1-60 characters.');
                if (strlen($splashSubtitle) > 160) throw new RuntimeException('Splash subtitle must be 160 characters or fewer.');
                if (!in_array($duration, [1400,2000,2400,3200,4200], true)) throw new RuntimeException('Invalid splash duration.');
                $changed = (bool)$s['splash_enabled'] !== $splashEnabled
                    || (string)$s['splash_title'] !== $splashTitle
                    || (string)$s['splash_subtitle'] !== $splashSubtitle
                    || (int)$s['splash_duration_ms'] !== $duration;
                $s['splash_enabled'] = $splashEnabled;
                $s['splash_title'] = $splashTitle;
                $s['splash_subtitle'] = $splashSubtitle;
                $s['splash_duration_ms'] = $duration;
                if ($changed) $s['splash_version'] = max(1, (int)$s['splash_version'] + 1);
                return $s;
            }

            if ($section === 'packages') {
                $name = trim((string)($input['package_name'] ?? ''));
                $version = trim((string)($input['package_version'] ?? ''));
                $notes = trim((string)($input['package_notes'] ?? ''));
                $fileId = max(0, (int)($input['package_file_id'] ?? 0));
                if (strlen($name) > 80 || strlen($version) > 40 || strlen($notes) > 500) {
                    throw new RuntimeException('Package metadata is too long.');
                }
                $s['package_enabled'] = ($input['package_enabled'] ?? '') === '1';
                $s['package_name'] = $name;
                $s['package_version'] = $version;
                $s['package_notes'] = $notes;
                $s['package_file_id'] = $fileId;
                return $s;
            }

            if ($section === 'update') {
                $label = trim((string)($input['panel_release_label'] ?? ''));
                if (strlen($label) > 80) throw new RuntimeException('Release label must be 80 characters or fewer.');
                $s['panel_release_label'] = $label;
                return $s;
            }

            throw new RuntimeException('Unknown settings section.');
        }, 'owner_system_'.$section.'_changed');

        return 'Settings saved.';
    }

    public static function action(array $actor, string $action, array $input): string
    {
        Auth::requireRole($actor, 'owner');
        $pdo = Database::pdo();

        if ($action === 'revoke_all_api_tokens') {
            $count = $pdo->exec('DELETE FROM api_tokens');
            Security::audit((int)$actor['id'], 'owner_all_api_tokens_revoked', ['count'=>(int)$count]);
            return 'Revoked '.(int)$count.' active/stored API token(s).';
        }

        if ($action === 'revoke_user_api_tokens') {
            $uid = max(1, (int)($input['user_id'] ?? 0));
            if ($uid === (int)$actor['id']) throw new RuntimeException('Use account security controls for the Owner account.');
            $q = $pdo->prepare('DELETE FROM api_tokens WHERE user_id=?');
            $q->execute([$uid]);
            Security::audit((int)$actor['id'], 'owner_user_api_tokens_revoked', ['target_id'=>$uid,'count'=>$q->rowCount()]);
            return 'User API access revoked.';
        }

        if ($action === 'kick_device') {
            $deviceId = max(1, (int)($input['device_id'] ?? 0));
            $q = $pdo->prepare('SELECT d.id,d.license_key_id,k.owner_user_id FROM license_devices d JOIN license_keys k ON k.id=d.license_key_id WHERE d.id=? LIMIT 1');
            $q->execute([$deviceId]);
            $row = $q->fetch();
            if (!$row) throw new RuntimeException('Device binding not found.');
            $u = $pdo->prepare('UPDATE license_devices SET active=0 WHERE id=? AND active=1');
            $u->execute([$deviceId]);
            Security::audit((int)$actor['id'], 'owner_device_binding_revoked', ['device_id'=>$deviceId,'license_id'=>(int)$row['license_key_id'],'owner_id'=>(int)$row['owner_user_id']]);
            return $u->rowCount() ? 'Device binding revoked.' : 'Device binding was already inactive.';
        }

        if ($action === 'purge_panel_cache') {
            if (!CdnCache::enabled()) throw new RuntimeException('Cloudflare cache purge is disabled in .env.');
            $base = rtrim((string)Config::get('app_url', ''), '/');
            if ($base === '') throw new RuntimeException('APP_URL is not configured.');
            $ok = CdnCache::purgeUrls([
                $base.'/',
                $base.'/dashboard',
                $base.'/files',
                $base.'/assets/app.css',
                $base.'/assets/themes.css',
                $base.'/assets/owner-tools.css',
                $base.'/assets/system-console.css',
                $base.'/assets/app.js',
                $base.'/assets/owner-tools.js',
                $base.'/assets/app.css?v=20260910-2',
                $base.'/assets/themes.css?v=20260910-2',
                $base.'/assets/owner-tools.css?v=20260910-2',
                $base.'/assets/system-console.css?v=20260910-1',
                $base.'/assets/app.js?v=20260910-2',
                $base.'/assets/owner-tools.js?v=20260910-2',
            ]);
            Security::audit((int)$actor['id'], 'owner_cdn_cache_purge', ['success'=>$ok]);
            if (!$ok) throw new RuntimeException('Cloudflare purge request failed. Check server logs and .env.');
            return 'Panel CDN cache purge sent successfully.';
        }

        throw new RuntimeException('Unknown Owner action.');
    }

    private static function validIpRule(string $rule): bool
    {
        if (!str_contains($rule, '/')) return filter_var($rule, FILTER_VALIDATE_IP) !== false;
        [$network, $prefixRaw] = explode('/', $rule, 2);
        $bin = @inet_pton(trim($network));
        if ($bin === false) return false;
        $max = strlen($bin) * 8;
        return filter_var($prefixRaw, FILTER_VALIDATE_INT, ['options'=>['min_range'=>0,'max_range'=>$max]]) !== false;
    }

    private static function overview(array $actor, string $flash): void
    {
        $s = self::currentSettings();
        $pdo = Database::pdo();
        $users = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE status='active'")->fetchColumn();
        $keys = (int)$pdo->query("SELECT COUNT(*) FROM license_keys WHERE status IN ('unused','active','disabled')")->fetchColumn();
        $devices = (int)$pdo->query("SELECT COUNT(*) FROM license_devices WHERE active=1")->fetchColumn();
        $files = 0;
        try { $files = (int)$pdo->query('SELECT COUNT(*) FROM user_uploads')->fetchColumn(); } catch (Throwable) {}
        $blocked = is_array($s['blocked_ip_rules'] ?? null) ? count($s['blocked_ip_rules']) : 0;
        $cards = [
            ['/owner/server','Server & Maintenance',($s['panel_online'] ? 'Online' : 'Offline').' • registrations '.($s['registration_open'] ? 'on' : 'off')],
            ['/keys/extend','Extend Duration','Edit key validity through the existing entitlement-aware Key Editor'],
            ['/owner/device-policy','One Device / Device Policy',($s['force_one_device_new_keys'] ? 'One-device force ON' : 'Default '.(int)$s['default_max_devices'].' devices')],
            ['/owner/key-format','Key Format',(string)$s['generated_key_prefix'].' + '.(int)$s['generated_key_length'].' chars'],
            ['/owner/pricing','Pricing',self::effectiveDailyPrice($s).' credit(s) / day'],
            ['/owner/ip-management','IP Management',$blocked.' blocked rule(s)'],
            ['/owner/rebranding','Manage Rebranding',(string)($s['brand_name'] ?: Config::get('app_name'))],
            ['/owner/security','Heartbeat & Security',$devices.' active device binding(s)'],
            ['/owner/session-controls','Heartbeat Kicks','Revoke API access and device bindings'],
            ['/owner/packages','Package Manager',$files.' File Manager object(s)'],
            ['/owner/alerts','Panel Alert',trim((string)$s['announcement']) !== '' ? 'Live announcement active' : 'No live alert'],
            ['/owner/update','Panel Update','Release '.((string)$s['panel_release_label'] !== '' ? $s['panel_release_label'] : 'unlabeled')],
            ['/owner/settings','Settings','Environment and system posture'],
        ];
        $html = '';
        foreach ($cards as $card) {
            $html .= '<a class="system-module-card" href="'.View::e($card[0]).'"><span class="module-orb">→</span><div><strong>'.View::e($card[1]).'</strong><small>'.View::e($card[2]).'</small></div><b>OPEN</b></a>';
        }
        $body = self::hero('OWNER SYSTEM', 'System Control Center', 'Every server control is separated into its own focused module.', self::badge((bool)$s['panel_online'], 'ONLINE', 'OFFLINE'))
            .$flash
            .'<div class="system-stats"><div><span>ACTIVE USERS</span><b>'.$users.'</b></div><div><span>LIVE KEYS</span><b>'.$keys.'</b></div><div><span>ACTIVE DEVICES</span><b>'.$devices.'</b></div><div><span>FILES</span><b>'.$files.'</b></div></div>'
            .'<section class="system-module-grid">'.$html.'</section>';
        View::page('System Control Center', $body, $actor);
    }

    private static function server(array $actor, string $flash): void
    {
        $s = self::currentSettings();
        $body = self::hero('SYSTEM / SERVER', 'Server & Maintenance', 'Panel availability, registration and key-generation gates.', self::badge((bool)$s['panel_online'], 'SERVER ONLINE', 'MAINTENANCE'))
            .$flash
            .'<form method="post" action="/owner/system/save" class="card system-form stack" data-confirm="Save server and maintenance policy?">'.View::csrf()
            .'<input type="hidden" name="section" value="server"><input type="hidden" name="revision" value="'.(int)$s['revision'].'">'
            .self::toggle('panel_online','Panel ON / OFF','Owner access stays available while non-owner panel access is paused.',(bool)$s['panel_online'])
            .self::toggle('registration_open','New registrations','Allow valid referral invites to create accounts.',(bool)$s['registration_open'])
            .self::toggle('generation_open','User key generation','Controls non-owner and Telegram guest generation. Owner remains unrestricted.',(bool)$s['generation_open'])
            .'<div class="field"><label>Maintenance message</label><textarea name="message" maxlength="500" rows="4">'.View::e((string)$s['message']).'</textarea></div>'
            .'<button class="primary wide">Save Server Policy</button></form>';
        View::page('Server & Maintenance', $body, $actor);
    }

    private static function devices(array $actor, string $flash): void
    {
        $s = self::currentSettings();
        $options = '';
        foreach (KeyManager::DEVICE_LIMITS as $limit) {
            $options .= '<option value="'.$limit.'"'.((int)$s['default_max_devices'] === $limit ? ' selected' : '').'>'.$limit.' device'.($limit === 1 ? '' : 's').'</option>';
        }
        $body = self::hero('SYSTEM / DEVICE POLICY', 'One Device', 'Set the default device count for new keys or force all new finite keys to one device.')
            .$flash
            .'<form method="post" action="/owner/system/save" class="card system-form stack">'.View::csrf()
            .'<input type="hidden" name="section" value="devices"><input type="hidden" name="revision" value="'.(int)$s['revision'].'">'
            .'<div class="field"><label>Default maximum devices</label><select name="default_max_devices">'.$options.'</select></div>'
            .self::toggle('force_one_device_new_keys','Force One Device for new finite keys','Does not rewrite old keys. Owner Unlimited Devices remains an explicit override.',(bool)$s['force_one_device_new_keys'])
            .'<button class="primary wide">Save Device Policy</button></form>';
        View::page('One Device', $body, $actor);
    }

    private static function keyFormat(array $actor, string $flash): void
    {
        $s = self::currentSettings();
        $preview = (string)$s['generated_key_prefix'].str_repeat('X', (int)$s['generated_key_length']);
        $body = self::hero('SYSTEM / LICENSE FORMAT', 'Key Format', 'Customize automatically generated keys. Manually entered custom keys remain untouched.')
            .$flash
            .'<form method="post" action="/owner/system/save" class="card system-form stack">'.View::csrf()
            .'<input type="hidden" name="section" value="key-format"><input type="hidden" name="revision" value="'.(int)$s['revision'].'">'
            .'<div class="system-preview"><span>LIVE PREVIEW</span><code>'.View::e($preview).'</code></div>'
            .'<div class="form-row"><div class="field"><label>Prefix</label><input name="generated_key_prefix" maxlength="30" required value="'.View::e((string)$s['generated_key_prefix']).'"></div>'
            .'<div class="field"><label>Random characters</label><input name="generated_key_length" type="number" min="8" max="32" required value="'.(int)$s['generated_key_length'].'"></div></div>'
            .'<button class="primary wide">Save Key Format</button></form>';
        View::page('Key Format', $body, $actor);
    }

    private static function pricing(array $actor, string $flash): void
    {
        $s = self::currentSettings();
        $price = self::effectiveDailyPrice($s);
        $body = self::hero('SYSTEM / PRICING', 'Pricing', 'Control the credit price used for finite non-owner key generation.')
            .$flash
            .'<div class="system-stats"><div><span>1 DAY</span><b>'.$price.'</b></div><div><span>7 DAYS</span><b>'.($price*7).'</b></div><div><span>30 DAYS</span><b>'.($price*30).'</b></div><div><span>OWNER</span><b>FREE</b></div></div>'
            .'<form method="post" action="/owner/system/save" class="card system-form stack">'.View::csrf()
            .'<input type="hidden" name="section" value="pricing"><input type="hidden" name="revision" value="'.(int)$s['revision'].'">'
            .'<div class="field"><label>Credits per started 24-hour period</label><input name="key_cost_per_day" type="number" min="0" max="100000" required value="'.$price.'"></div>'
            .'<p class="hint">Unlimited validity remains Owner-only, so this price applies to finite user/reseller/admin generation.</p>'
            .'<button class="primary wide">Save Pricing</button></form>';
        View::page('Pricing', $body, $actor);
    }

    private static function ipManagement(array $actor, string $flash): void
    {
        $s = self::currentSettings();
        $rules = is_array($s['blocked_ip_rules'] ?? null) ? $s['blocked_ip_rules'] : [];
        $recent = Database::pdo()->query("SELECT ip_address,COUNT(*) hits,MAX(created_at) last_seen FROM audit_logs WHERE ip_address<>'' GROUP BY ip_address ORDER BY last_seen DESC LIMIT 30")->fetchAll() ?: [];
        $rows = '';
        foreach ($recent as $row) $rows .= '<tr><td><code>'.View::e((string)$row['ip_address']).'</code></td><td>'.(int)$row['hits'].'</td><td>'.View::e((string)$row['last_seen']).'</td></tr>';
        if ($rows === '') $rows = '<tr><td colspan="3">No observed IP history.</td></tr>';
        $body = self::hero('SYSTEM / NETWORK', 'IP Management', 'Block non-owner authenticated panel/API access by exact IP or CIDR. Owner access always bypasses this list.')
            .$flash
            .'<div class="system-split"><form method="post" action="/owner/system/save" class="card system-form stack">'.View::csrf()
            .'<input type="hidden" name="section" value="ip"><input type="hidden" name="revision" value="'.(int)$s['revision'].'">'
            .'<div class="field"><label>Blocked IP / CIDR rules — one per line</label><textarea name="blocked_ip_rules" rows="12" placeholder="203.0.113.44&#10;198.51.100.0/24">'.View::e(implode("\n", $rules)).'</textarea></div>'
            .'<button class="primary wide">Save IP Policy</button></form>'
            .'<div class="card"><div class="toolbar"><div><span class="eyebrow">RECENT NETWORKS</span><h3>Observed panel IPs</h3></div><span class="tag">'.count($recent).' shown</span></div><div class="table-wrap"><table><thead><tr><th>IP</th><th>Events</th><th>Last seen</th></tr></thead><tbody>'.$rows.'</tbody></table></div></div></div>';
        View::page('IP Management', $body, $actor);
    }

    private static function rebranding(array $actor, string $flash): void
    {
        $s = self::currentSettings();
        $name = (string)($s['brand_name'] ?: Config::get('app_name'));
        $subtitle = (string)$s['brand_subtitle'];
        $footer = (string)$s['brand_footer'];
        $mark = (string)$s['brand_mark'];
        $durations = '';
        foreach ([1400=>'Fast 1.4s',2000=>'Quick 2.0s',2400=>'Balanced 2.4s',3200=>'Cinematic 3.2s',4200=>'Showcase 4.2s'] as $ms=>$label) {
            $durations .= '<option value="'.$ms.'"'.((int)$s['splash_duration_ms'] === $ms ? ' selected' : '').'>'.View::e($label).'</option>';
        }
        $body = self::hero('SYSTEM / BRAND', 'Manage Rebranding', 'Change panel identity and opening splash without touching Loader API behavior.')
            .$flash
            .'<form method="post" action="/owner/system/save" class="card system-form stack">'.View::csrf()
            .'<input type="hidden" name="section" value="branding"><input type="hidden" name="revision" value="'.(int)$s['revision'].'">'
            .'<div class="form-row"><div class="field"><label>Panel brand name</label><input name="brand_name" maxlength="40" required value="'.View::e($name).'"></div><div class="field"><label>Brand mark</label><input name="brand_mark" maxlength="3" required value="'.View::e($mark).'"></div></div>'
            .'<div class="field"><label>Subtitle</label><input name="brand_subtitle" maxlength="120" value="'.View::e($subtitle).'"></div>'
            .'<div class="field"><label>Footer text</label><input name="brand_footer" maxlength="120" value="'.View::e($footer).'"></div>'
            .self::toggle('splash_enabled','Opening splash','Show the cinematic opening once per splash version.',(bool)$s['splash_enabled'])
            .'<div class="form-row"><div class="field"><label>Splash title</label><input name="splash_title" maxlength="60" required value="'.View::e((string)$s['splash_title']).'"></div><div class="field"><label>Splash duration</label><select name="splash_duration_ms">'.$durations.'</select></div></div>'
            .'<div class="field"><label>Splash subtitle</label><input name="splash_subtitle" maxlength="160" value="'.View::e((string)$s['splash_subtitle']).'"></div>'
            .'<button class="primary wide">Save Rebranding</button></form>';
        View::page('Manage Rebranding', $body, $actor);
    }

    private static function security(array $actor, string $flash): void
    {
        $pdo = Database::pdo();
        $apiTokens = (int)$pdo->query('SELECT COUNT(*) FROM api_tokens WHERE expires_at>NOW()')->fetchColumn();
        $twoFa = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE telegram_2fa_enabled=1 AND status=\'active\'')->fetchColumn();
        $devices15 = (int)$pdo->query('SELECT COUNT(*) FROM license_devices WHERE active=1 AND last_seen_at>=DATE_SUB(NOW(),INTERVAL 15 MINUTE)')->fetchColumn();
        $keys15 = (int)$pdo->query('SELECT COUNT(*) FROM license_keys WHERE last_used_at>=DATE_SUB(NOW(),INTERVAL 15 MINUTE)')->fetchColumn();
        $body = self::hero('SYSTEM / SECURITY', 'Heartbeat & Security', 'Operational security posture using retained panel and license activity. These are observed timestamps, not a hidden background heartbeat.')
            .$flash
            .'<div class="system-stats"><div><span>ACTIVE API TOKENS</span><b>'.$apiTokens.'</b></div><div><span>2FA USERS</span><b>'.$twoFa.'</b></div><div><span>DEVICES / 15M</span><b>'.$devices15.'</b></div><div><span>KEYS / 15M</span><b>'.$keys15.'</b></div></div>'
            .'<section class="system-module-grid">'
            .self::infoCard('Session security','Idle '.(int)Config::get('session_idle_seconds',1800).'s • rotation '.(int)Config::get('session_rotate_seconds',900).'s • absolute '.(int)Config::get('session_absolute_seconds',43200).'s')
            .self::infoCard('Transport','HTTPS enforcement, HSTS, CSP, no-store responses and secure cookies are enabled by the panel security layer.')
            .self::infoCard('Native endpoint','The System console does not modify the existing /connect request/response contract.')
            .self::infoCard('Fast response','Use Heartbeat Kicks to revoke API tokens or individual device bindings immediately.')
            .'</section>';
        View::page('Heartbeat & Security', $body, $actor);
    }

    private static function sessions(array $actor, string $flash): void
    {
        $pdo = Database::pdo();
        $tokens = $pdo->query("SELECT t.id,t.user_id,t.expires_at,t.last_used_at,u.username,u.name FROM api_tokens t JOIN users u ON u.id=t.user_id WHERE t.expires_at>NOW() ORDER BY COALESCE(t.last_used_at,t.created_at) DESC LIMIT 100")->fetchAll() ?: [];
        $tokenRows = '';
        foreach ($tokens as $row) {
            $tokenRows .= '<tr><td>'.View::e((string)($row['name'] ?: $row['username'])).'<br><small>@'.View::e((string)$row['username']).'</small></td><td>'.View::e((string)($row['last_used_at'] ?: 'Never')).'</td><td>'.View::e((string)$row['expires_at']).'</td><td>'
                .((int)$row['user_id'] === (int)$actor['id'] ? '<span class="muted">Owner protected</span>' : '<form method="post" action="/owner/system/action" class="inline" data-confirm="Revoke this user API access?"><input type="hidden" name="csrf" value="'.View::e(Security::csrfToken()).'"><input type="hidden" name="action" value="revoke_user_api_tokens"><input type="hidden" name="user_id" value="'.(int)$row['user_id'].'"><input type="hidden" name="return_to" value="/owner/session-controls"><button class="ghost danger compact">Revoke</button></form>')
                .'</td></tr>';
        }
        if ($tokenRows === '') $tokenRows = '<tr><td colspan="4">No active API tokens.</td></tr>';

        $devices = $pdo->query("SELECT d.id,d.license_key_id,d.last_seen_at,d.ip_address,u.username,u.name FROM license_devices d JOIN license_keys k ON k.id=d.license_key_id JOIN users u ON u.id=k.owner_user_id WHERE d.active=1 ORDER BY d.last_seen_at DESC LIMIT 100")->fetchAll() ?: [];
        $deviceRows = '';
        foreach ($devices as $row) {
            $deviceRows .= '<tr><td>#'.(int)$row['license_key_id'].'</td><td>'.View::e((string)($row['name'] ?: $row['username'])).'</td><td>'.View::e((string)$row['last_seen_at']).'</td><td><code>'.View::e((string)$row['ip_address']).'</code></td><td><form method="post" action="/owner/system/action" class="inline" data-confirm="Revoke this device binding?"><input type="hidden" name="csrf" value="'.View::e(Security::csrfToken()).'"><input type="hidden" name="action" value="kick_device"><input type="hidden" name="device_id" value="'.(int)$row['id'].'"><input type="hidden" name="return_to" value="/owner/session-controls"><button class="ghost danger compact">Kick</button></form></td></tr>';
        }
        if ($deviceRows === '') $deviceRows = '<tr><td colspan="5">No active device bindings.</td></tr>';
        $body = self::hero('SYSTEM / ACCESS CONTROL', 'Heartbeat Kicks', 'Immediate revocation controls for API sessions and license device bindings.')
            .$flash
            .'<div class="card history-card"><div class="toolbar"><div><span class="eyebrow">API ACCESS</span><h3>Active API tokens</h3></div><form method="post" action="/owner/system/action" data-confirm="Revoke every stored API token?"><input type="hidden" name="csrf" value="'.View::e(Security::csrfToken()).'"><input type="hidden" name="action" value="revoke_all_api_tokens"><input type="hidden" name="return_to" value="/owner/session-controls"><button class="ghost danger">Revoke all API tokens</button></form></div><div class="table-wrap"><table><thead><tr><th>User</th><th>Last used</th><th>Expires</th><th>Action</th></tr></thead><tbody>'.$tokenRows.'</tbody></table></div></div>'
            .'<div class="card history-card"><div class="toolbar"><div><span class="eyebrow">DEVICE ACCESS</span><h3>Active license bindings</h3></div><span class="tag">Latest 100</span></div><div class="table-wrap"><table><thead><tr><th>Key</th><th>Owner</th><th>Last seen</th><th>IP</th><th>Action</th></tr></thead><tbody>'.$deviceRows.'</tbody></table></div></div>';
        View::page('Heartbeat Kicks', $body, $actor);
    }

    private static function packages(array $actor, string $flash): void
    {
        $s = self::currentSettings();
        $pdo = Database::pdo();
        $files = [];
        try {
            $q = $pdo->prepare('SELECT id,original_name,size_bytes,version,updated_at FROM user_uploads WHERE user_id=? ORDER BY updated_at DESC');
            $q->execute([(int)$actor['id']]);
            $files = $q->fetchAll() ?: [];
        } catch (Throwable) {}
        $options = '<option value="0">No file selected</option>';
        foreach ($files as $file) $options .= '<option value="'.(int)$file['id'].'"'.((int)$s['package_file_id'] === (int)$file['id'] ? ' selected' : '').'>#'.(int)$file['id'].' • '.View::e((string)$file['original_name']).' • v'.(int)$file['version'].'</option>';
        $active = '';
        foreach ($files as $file) {
            if ((int)$file['id'] !== (int)$s['package_file_id']) continue;
            $active = '<div class="system-preview"><span>ACTIVE FILE</span><code>#'.(int)$file['id'].' • '.View::e((string)$file['original_name']).' • v'.(int)$file['version'].'</code><a class="ghost compact" href="/files/download?id='.(int)$file['id'].'">Download</a></div>';
        }
        $body = self::hero('SYSTEM / DISTRIBUTION', 'Package Manager', 'Point a named release channel at one of your Owner File Manager uploads. Downloads remain authenticated.')
            .$flash.$active
            .'<form method="post" action="/owner/system/save" class="card system-form stack">'.View::csrf()
            .'<input type="hidden" name="section" value="packages"><input type="hidden" name="revision" value="'.(int)$s['revision'].'">'
            .self::toggle('package_enabled','Package channel enabled','Publishes the internal Owner package selection in this console.',(bool)$s['package_enabled'])
            .'<div class="form-row"><div class="field"><label>Package / channel name</label><input name="package_name" maxlength="80" value="'.View::e((string)$s['package_name']).'" placeholder="Stable release"></div><div class="field"><label>Version</label><input name="package_version" maxlength="40" value="'.View::e((string)$s['package_version']).'" placeholder="4.6.0"></div></div>'
            .'<div class="field"><label>File Manager object</label><select name="package_file_id">'.$options.'</select></div>'
            .'<div class="field"><label>Release notes</label><textarea name="package_notes" maxlength="500" rows="5">'.View::e((string)$s['package_notes']).'</textarea></div>'
            .'<div class="inline"><button class="primary">Save Package</button><a class="ghost" href="/files">Open File Manager</a></div></form>';
        View::page('Package Manager', $body, $actor);
    }

    private static function alerts(array $actor, string $flash): void
    {
        $s = self::currentSettings();
        $pdo = Database::pdo();
        $panelUsers = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE status='active'")->fetchColumn();
        $linked = (int)$pdo->query('SELECT COUNT(*) FROM telegram_users WHERE linked_user_id IS NOT NULL')->fetchColumn();
        $guests = (int)$pdo->query('SELECT COUNT(*) FROM telegram_users WHERE linked_user_id IS NULL')->fetchColumn();
        $recent = BroadcastService::recent(12);
        $rows = '';
        foreach ($recent as $job) {
            $rows .= '<tr><td>#'.(int)$job['id'].'</td><td>'.View::e((string)$job['message']).'</td><td>'.View::e((string)$job['status']).'</td><td>'.(int)$job['sent_count'].' / '.(int)$job['total_recipients'].'</td><td>'.View::e((string)$job['created_at']).'</td></tr>';
        }
        if ($rows === '') $rows = '<tr><td colspan="5">No broadcasts yet.</td></tr>';
        $current = trim((string)$s['announcement']);
        $body = self::hero('SYSTEM / ALERTS', 'Panel Alert', 'Publish a panel banner and optionally queue the same message for linked or guest Telegram audiences.', $current !== '' ? self::badge(true,'ALERT LIVE','') : self::badge(false,'','NO ALERT'))
            .$flash
            .($current !== '' ? '<div class="card system-live-alert"><div><span class="eyebrow">LIVE PANEL ALERT</span><strong>'.View::e($current).'</strong><small>'.View::e((string)$s['announcement_published_at']).'</small></div><form method="post" action="/owner/announcements/clear" data-confirm="Clear live panel alert?">'.View::csrf().'<button class="ghost danger">Clear</button></form></div>' : '')
            .'<form method="post" action="/owner/announcements/create" class="card system-form stack" data-confirm="Publish this announcement?">'.View::csrf()
            .'<div class="field"><label>Announcement</label><textarea name="announcement" maxlength="1000" rows="6" required></textarea></div>'
            .'<div class="system-choice-grid"><label><input type="checkbox" name="audience_panel" value="1" checked><span><b>Panel</b><small>'.$panelUsers.' active users</small></span></label><label><input type="checkbox" name="audience_linked" value="1"><span><b>Linked Telegram</b><small>'.$linked.' chats</small></span></label><label><input type="checkbox" name="audience_guests" value="1"><span><b>Guest Telegram</b><small>'.$guests.' chats</small></span></label></div>'
            .'<button class="primary wide">Publish Alert</button></form>'
            .'<div class="card history-card"><div class="toolbar"><h3>Recent delivery history</h3><span class="tag">12 latest</span></div><div class="table-wrap"><table><thead><tr><th>ID</th><th>Message</th><th>Status</th><th>Sent</th><th>Created</th></tr></thead><tbody>'.$rows.'</tbody></table></div></div>';
        View::page('Panel Alert', $body, $actor);
    }

    private static function updateCenter(array $actor, string $flash): void
    {
        $s = self::currentSettings();
        $cdn = CdnCache::enabled();
        $zoneReady = trim((string)Config::get('cloudflare_zone_id','')) !== '';
        $tokenReady = trim((string)Config::get('cloudflare_api_token','')) !== '';
        $body = self::hero('SYSTEM / DEPLOYMENT', 'Panel Update', 'Deployment status, release label and controlled CDN invalidation. This page never executes arbitrary remote code.')
            .$flash
            .'<div class="system-stats"><div><span>CDN PURGE</span><b>'.($cdn ? 'ON' : 'OFF').'</b></div><div><span>ZONE</span><b>'.($zoneReady ? 'READY' : 'MISSING').'</b></div><div><span>API TOKEN</span><b>'.($tokenReady ? 'READY' : 'MISSING').'</b></div><div><span>UPLOAD CAP</span><b>50 MB</b></div></div>'
            .'<div class="system-split"><form method="post" action="/owner/system/save" class="card system-form stack">'.View::csrf().'<input type="hidden" name="section" value="update"><input type="hidden" name="revision" value="'.(int)$s['revision'].'"><div class="field"><label>Current release label</label><input name="panel_release_label" maxlength="80" value="'.View::e((string)$s['panel_release_label']).'" placeholder="TeamDark 2026.09"></div><button class="primary">Save Release Label</button></form>'
            .'<div class="card system-form"><div class="eyebrow">CDN INVALIDATION</div><h3>Purge panel shell</h3><p class="muted">Clears known panel HTML/CSS/JS URLs from Cloudflare when the .env purge switch is enabled.</p><form method="post" action="/owner/system/action" data-confirm="Purge TeamDark panel URLs from Cloudflare cache?">'.View::csrf().'<input type="hidden" name="action" value="purge_panel_cache"><input type="hidden" name="return_to" value="/owner/update"><button class="ghost warning wide"'.(!$cdn ? ' disabled' : '').'>Purge CDN now</button></form></div></div>';
        View::page('Panel Update', $body, $actor);
    }

    private static function general(array $actor, string $flash): void
    {
        $s = self::currentSettings();
        $rows = [
            ['APP URL',(string)Config::get('app_url','')],
            ['Cloudflare purge',CdnCache::enabled() ? 'Enabled' : 'Disabled'],
            ['PHP upload target','50 MB application cap'],
            ['Session idle',(string)Config::get('session_idle_seconds',1800).' seconds'],
            ['Session rotation',(string)Config::get('session_rotate_seconds',900).' seconds'],
            ['Audit retention',(string)Config::get('audit_retention_days',90).' days'],
            ['Broadcast retention',(string)Config::get('broadcast_retention_days',30).' days'],
            ['Settings revision','#'.(int)$s['revision']],
        ];
        $html = '';
        foreach ($rows as $row) $html .= '<div class="system-setting-line"><span>'.View::e($row[0]).'</span><strong>'.View::e($row[1]).'</strong></div>';
        $body = self::hero('SYSTEM / SETTINGS', 'Settings', 'Read-only environment posture plus shortcuts to editable system modules.')
            .$flash
            .'<div class="system-split"><div class="card system-settings-list">'.$html.'</div><div class="card system-form"><div class="eyebrow">EDITABLE SETTINGS</div><h3>Open a focused module</h3><div class="system-link-stack"><a href="/owner/server">Server & Maintenance</a><a href="/owner/rebranding">Rebranding</a><a href="/owner/pricing">Pricing</a><a href="/owner/ip-management">IP Management</a><a href="/owner/update">Panel Update / CDN</a></div></div></div>';
        View::page('Settings', $body, $actor);
    }

    private static function developer(array $actor, string $flash): void
    {
        $body = self::hero('SYSTEM / DEVELOPER', 'Developer API', 'Live /connect integration reference. No panel secrets are shown.').$flash;
        View::page('Developer API', $body, $actor);
    }

    private static function toggle(string $name, string $title, string $copy, bool $checked): string
    {
        return '<label class="setting-row premium-setting" for="'.View::e($name).'"><span><strong>'.View::e($title).'</strong><small>'.View::e($copy).'</small></span><input id="'.View::e($name).'" class="toggle-input" type="checkbox" name="'.View::e($name).'" value="1"'.($checked ? ' checked' : '').'></label>';
    }

    private static function infoCard(string $title, string $copy): string
    {
        return '<article class="system-module-card static"><span class="module-orb">•</span><div><strong>'.View::e($title).'</strong><small>'.View::e($copy).'</small></div></article>';
    }

    private static function effectiveDailyPrice(array $settings): int
    {
        $stored = (int)($settings['key_cost_per_day'] ?? -1);
        return $stored >= 0 ? $stored : max(0, (int)Config::get('key_cost', 1));
    }
}
