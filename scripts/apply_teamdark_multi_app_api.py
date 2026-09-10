from pathlib import Path
import re


def read(path: str) -> tuple[Path, str]:
    p = Path(path)
    return p, p.read_text(encoding='utf-8')


def once(text: str, old: str, new: str, path: Path) -> str:
    if old not in text:
        raise SystemExit(f'missing marker in {path}: {old[:140]!r}')
    return text.replace(old, new, 1)


# -----------------------------------------------------------------------------
# schema.sql: app registry, account/referral grants, key app binding.
# -----------------------------------------------------------------------------
p, s = read('teamdark-panel/database/schema.sql')
marker = "CREATE TABLE IF NOT EXISTS user_uploads ("
block = r'''CREATE TABLE IF NOT EXISTS app_registry (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  endpoint_token VARCHAR(64) NOT NULL UNIQUE,
  notes VARCHAR(240) NOT NULL DEFAULT '',
  status ENUM('active','disabled') NOT NULL DEFAULT 'active',
  is_official TINYINT(1) NOT NULL DEFAULT 0,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_app_registry_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  UNIQUE KEY uq_app_registry_name(name),
  INDEX idx_app_registry_status(status),
  INDEX idx_app_registry_official(is_official)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO app_registry(id,name,endpoint_token,notes,status,is_official,created_by)
VALUES(1,'Official','official','Permanent backward-compatible /connect application API.','active',1,NULL)
ON DUPLICATE KEY UPDATE
  name=IF(is_official=1,name,VALUES(name)),
  status=IF(is_official=1,'active',status),
  is_official=IF(id=1,1,is_official);

CREATE TABLE IF NOT EXISTS user_app_access (
  user_id BIGINT UNSIGNED NOT NULL,
  app_id BIGINT UNSIGNED NOT NULL,
  granted_by BIGINT UNSIGNED NULL,
  source VARCHAR(24) NOT NULL DEFAULT 'owner',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(user_id,app_id),
  CONSTRAINT fk_user_app_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_user_app_app FOREIGN KEY (app_id) REFERENCES app_registry(id) ON DELETE CASCADE,
  CONSTRAINT fk_user_app_granter FOREIGN KEY (granted_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_user_app_app(app_id),
  INDEX idx_user_app_granter(granted_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Existing accounts keep the historical Official application automatically.
INSERT IGNORE INTO user_app_access(user_id,app_id,granted_by,source)
SELECT id,1,NULL,'migration' FROM users;

'''
s = once(s, marker, block + marker, p)

marker = "CREATE TABLE IF NOT EXISTS telegram_users ("
block = r'''CREATE TABLE IF NOT EXISTS referral_app_access (
  referral_id BIGINT UNSIGNED NOT NULL,
  app_id BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(referral_id,app_id),
  CONSTRAINT fk_ref_app_referral FOREIGN KEY (referral_id) REFERENCES referral_invites(id) ON DELETE CASCADE,
  CONSTRAINT fk_ref_app_app FOREIGN KEY (app_id) REFERENCES app_registry(id) ON DELETE CASCADE,
  INDEX idx_ref_app_app(app_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Existing pending/used referrals preserve old behavior by granting Official.
INSERT IGNORE INTO referral_app_access(referral_id,app_id)
SELECT id,1 FROM referral_invites;

'''
s = once(s, marker, block + marker, p)

marker = "CALL td_add_column(\n  'license_keys','telegram_user_id',\n  'ALTER TABLE license_keys ADD COLUMN telegram_user_id BIGINT UNSIGNED NULL AFTER key_source'\n);"
addition = marker + r'''
CALL td_add_column(
  'license_keys','app_id',
  'ALTER TABLE license_keys ADD COLUMN app_id BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER telegram_user_id'
);

UPDATE license_keys SET app_id=1 WHERE app_id IS NULL OR app_id=0;
'''
s = once(s, marker, addition, p)

marker = "CALL td_add_index(\n  'license_keys','idx_keys_source',\n  'ALTER TABLE license_keys ADD INDEX idx_keys_source(key_source)'\n);"
addition = marker + r'''
CALL td_add_index(
  'license_keys','idx_keys_app',
  'ALTER TABLE license_keys ADD INDEX idx_keys_app(app_id)'
);
'''
s = once(s, marker, addition, p)
p.write_text(s, encoding='utf-8')


# -----------------------------------------------------------------------------
# ReferralManager: atomic app allocation + visible app summary.
# -----------------------------------------------------------------------------
p, s = read('teamdark-panel/app/ReferralManager.php')
s = once(s, "namespace TeamDark\\Panel;\n", "namespace TeamDark\\Panel;\n\nrequire_once __DIR__.'/AppRegistry.php';\n", p)
pattern = re.compile(r"    public static function create\(array \$actor, string \$role\): array\n    \{.*?\n    \}\n\n    public static function creatorCanIssueRole", re.S)
replacement = r'''    public static function create(array $actor, string $role, mixed $appIds = []): array
    {
        $allowed = self::allowedRoles($actor);
        if (!in_array($role, $allowed, true)) {
            throw new RuntimeException('You cannot create a referral for this role.');
        }

        $selectedApps = AppRegistry::validateReferralApps($actor, $appIds);
        $pdo = Database::pdo();

        for ($attempt = 0; $attempt < 8; $attempt++) {
            $code = 'TD-REF-'.strtoupper(bin2hex(random_bytes(6)));
            $pdo->beginTransaction();
            try {
                $pdo->prepare(
                    "INSERT INTO referral_invites(code,created_by,role,status,expires_at)
                     VALUES(?,?,?,'pending',DATE_ADD(NOW(),INTERVAL 7 DAY))"
                )->execute([$code, $actor['id'], $role]);

                $id = (int)$pdo->lastInsertId();
                AppRegistry::attachAppsToReferral($pdo, $actor, $id, $selectedApps);
                $pdo->commit();

                try {
                    Security::audit((int)$actor['id'], 'referral_created', [
                        'invite_id'=>$id,
                        'role'=>$role,
                        'app_ids'=>$selectedApps,
                    ]);
                } catch (Throwable) {
                }

                return [
                    'id'=>$id,
                    'code'=>$code,
                    'role'=>$role,
                    'app_ids'=>$selectedApps,
                ];
            } catch (\PDOException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                if ($e->getCode() !== '23000') throw $e;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
        }

        throw new RuntimeException('Could not generate a unique referral. Try again.');
    }

    public static function grantInviteAppsToUser(\PDO $pdo, int $inviteId, int $userId, int $grantedBy): array
    {
        return AppRegistry::grantReferralToUser($pdo, $inviteId, $userId, $grantedBy);
    }

    public static function creatorCanIssueRole'''
s, n = pattern.subn(replacement, s, count=1)
if n != 1:
    raise SystemExit('ReferralManager create method marker not found')
old = """        $sql = \"SELECT i.id,i.code,i.role,i.status,i.created_at,i.used_at,
                       c.username creator_username,
                       u.username used_username,u.name used_name
                FROM referral_invites i
"""
new = """        $sql = \"SELECT i.id,i.code,i.role,i.status,i.created_at,i.used_at,
                       c.username creator_username,
                       u.username used_username,u.name used_name,
                       COALESCE((SELECT GROUP_CONCAT(a.name ORDER BY a.is_official DESC,a.name SEPARATOR ', ')
                                 FROM referral_app_access ra
                                 JOIN app_registry a ON a.id=ra.app_id
                                 WHERE ra.referral_id=i.id),'No App API') AS app_names,
                       (SELECT COUNT(*) FROM referral_app_access ra2 WHERE ra2.referral_id=i.id) AS app_count
                FROM referral_invites i
"""
s = once(s, old, new, p)
p.write_text(s, encoding='utf-8')


# -----------------------------------------------------------------------------
# Registration: referral App APIs become account App APIs atomically.
# -----------------------------------------------------------------------------
for filename in ['teamdark-panel/public/register-relaxed.php', 'teamdark-panel/public/index.php']:
    p, s = read(filename)
    marker = "            $uid = (int)$pdo->lastInsertId();\n"
    addition = marker + "            $grantedAppIds = ReferralManager::grantInviteAppsToUser(\n                $pdo,\n                (int)$invite['id'],\n                $uid,\n                (int)$invite['created_by']\n            );\n"
    s = once(s, marker, addition, p)
    marker = "            'invite_id'=>(int)$invite['id'],\n"
    s = once(s, marker, marker + "            'app_ids'=>$grantedAppIds,\n", p)
    p.write_text(s, encoding='utf-8')


# -----------------------------------------------------------------------------
# KeyManager: app-scoped key creation and labels.
# -----------------------------------------------------------------------------
p, s = read('teamdark-panel/app/KeyManager.php')
s = once(s, "namespace TeamDark\\Panel;\n", "namespace TeamDark\\Panel;\n\nrequire_once __DIR__.'/AppRegistry.php';\n", p)
s = once(
    s,
    "                       c.username creator_name,\n                       (SELECT COUNT(*) FROM license_devices d",
    "                       c.username creator_name,\n                       COALESCE(a.name,'Official') app_name,\n                       COALESCE(a.id,1) app_id_resolved,\n                       (SELECT COUNT(*) FROM license_devices d",
    p,
)
s = once(
    s,
    "                JOIN users c ON c.id=k.created_by\";",
    "                JOIN users c ON c.id=k.created_by\n                LEFT JOIN app_registry a ON a.id=k.app_id\";",
    p,
)
s = once(
    s,
    "        bool $unlimitedDevices,\n        string $customKey = ''\n    ): array {\n        PanelControl::assertGeneration($actor);",
    "        bool $unlimitedDevices,\n        string $customKey = '',\n        ?int $appId = null\n    ): array {\n        PanelControl::assertGeneration($actor);\n        $app = AppRegistry::generationApp($actor, $appId);",
    p,
)
s = once(s, "                        'PUBG license generation',", "                        (string)$app['name'].' license generation',", p)
s = once(
    s,
    "                    label,game,duration_seconds,unlimited_expiry,\n                    activated_at,expires_at,last_used_at,\n                    max_devices,unlimited_devices,status\n                 ) VALUES(?,?,?,?,?,?,?,?,'PUBG',?,?,NULL,NULL,NULL,?,?,'unused')\"",
    "                    label,game,app_id,duration_seconds,unlimited_expiry,\n                    activated_at,expires_at,last_used_at,\n                    max_devices,unlimited_devices,status\n                 ) VALUES(?,?,?,?,?,?,?,?,'PUBG',?,?,?,NULL,NULL,NULL,?,?,'unused')\"",
    p,
)
s = once(
    s,
    "                substr(trim($label), 0, 100),\n                $unlimitedExpiry ? 0 : max(86400, $durationSeconds),",
    "                substr(trim($label), 0, 100),\n                (int)$app['id'],\n                $unlimitedExpiry ? 0 : max(86400, $durationSeconds),",
    p,
)
s = once(
    s,
    "                'unlimited_devices'=>$unlimitedDevices,\n            ]);",
    "                'unlimited_devices'=>$unlimitedDevices,\n                'app_id'=>(int)$app['id'],\n                'app_name'=>(string)$app['name'],\n            ]);",
    p,
)
s = once(
    s,
    "                'cost'=>$cost,\n            ];",
    "                'cost'=>$cost,\n                'app_id'=>(int)$app['id'],\n                'app_name'=>(string)$app['name'],\n            ];",
    p,
)
s = once(
    s,
    "                    label,game,duration_seconds,unlimited_expiry,\n                    activated_at,expires_at,last_used_at,\n                    max_devices,unlimited_devices,status,key_source,telegram_user_id\n                 ) VALUES(?,?,?,?,?,?,?,?,'PUBG',7200,0,NULL,NULL,NULL,1,0,'unused','telegram_guest',?)\"",
    "                    label,game,app_id,duration_seconds,unlimited_expiry,\n                    activated_at,expires_at,last_used_at,\n                    max_devices,unlimited_devices,status,key_source,telegram_user_id\n                 ) VALUES(?,?,?,?,?,?,?,?,'PUBG',1,7200,0,NULL,NULL,NULL,1,0,'unused','telegram_guest',?)\"",
    p,
)
p.write_text(s, encoding='utf-8')


# -----------------------------------------------------------------------------
# Loader auth: resolve expected app from endpoint and bind key/account to app_id.
# -----------------------------------------------------------------------------
p, s = read('teamdark-panel/app/LoaderAuthService.php')
s = once(s, "namespace TeamDark\\Panel;\n", "namespace TeamDark\\Panel;\n\nrequire_once __DIR__.'/AppRegistry.php';\n", p)
s = once(
    s,
    "        string $serial,\n        string $ipAddress\n    ): array {",
    "        string $serial,\n        string $ipAddress,\n        int $appId = 1\n    ): array {",
    p,
)
s = once(
    s,
    "        if ($game === '' || $userKey === '' || $serial === '') {",
    "        if ($appId <= 0) {\n            return self::fail('Invalid Application');\n        }\n\n        if ($game === '' || $userKey === '' || $serial === '') {",
    p,
)
s = once(
    s,
    "                \"SELECT k.*, u.status AS account_status\n                 FROM license_keys k",
    "                \"SELECT k.*, u.status AS account_status,u.role AS account_role\n                 FROM license_keys k",
    p,
)
s = once(
    s,
    "                 WHERE k.key_hash IN (?,?)\n                 ORDER BY CASE WHEN k.key_hash=? THEN 0 ELSE 1 END",
    "                 WHERE k.key_hash IN (?,?) AND k.app_id=?\n                 ORDER BY CASE WHEN k.key_hash=? THEN 0 ELSE 1 END",
    p,
)
s = once(s, "$q->execute([$keyHash, $legacyKeyHash, $keyHash]);", "$q->execute([$keyHash, $legacyKeyHash, $appId, $keyHash]);", p)
marker = """            if (($key['game'] ?? 'PUBG') !== $game) {
                $pdo->rollBack();
                return self::fail('Invalid Game');
            }

"""
addition = marker + """            if (!AppRegistry::userHasApp((int)$key['owner_user_id'], (string)($key['account_role'] ?? ''), $appId)) {
                $pdo->rollBack();
                return self::fail('Application Access Revoked');
            }

"""
s = once(s, marker, addition, p)
s = once(
    s,
    "                        'used_devices'=>$usedDevices,\n                    ]",
    "                        'used_devices'=>$usedDevices,\n                        'app_id'=>$appId,\n                    ]",
    p,
)
p.write_text(s, encoding='utf-8')


# -----------------------------------------------------------------------------
# /connect and /connect/<channel> share the exact native payload contract.
# -----------------------------------------------------------------------------
p, s = read('teamdark-panel/public/connect.php')
s = once(s, "use TeamDark\\Panel\\{Config,Database,Security,Crypto,LoaderAuthService};", "use TeamDark\\Panel\\{AppRegistry,Config,Database,Security,Crypto,LoaderAuthService};", p)
s = once(s, "function teamdarkGateway(string $endpoint, bool $headOnly = false): never", "function teamdarkGateway(string $endpoint, string $appName, bool $headOnly = false): never", p)
s = once(
    s,
    "    $safeEndpoint = htmlspecialchars(\n        $endpoint,\n        ENT_QUOTES | ENT_SUBSTITUTE,\n        'UTF-8'\n    );",
    "    $safeEndpoint = htmlspecialchars(\n        $endpoint,\n        ENT_QUOTES | ENT_SUBSTITUTE,\n        'UTF-8'\n    );\n    $safeAppName = htmlspecialchars($appName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');",
    p,
)
s = once(s, "Encrypted license gateway for authorized Team Dark clients.", "'.$safeAppName.' application license gateway for authorized Team Dark clients.", p)
s = once(s, "        'LoaderAuthService',\n", "        'LoaderAuthService',\n        'AppRegistry',\n", p)
marker = """    $method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');

    if ($method === 'GET' || $method === 'HEAD') {
        $base = rtrim((string)Config::get('app_url', ''), '/');
        if ($base === '') {
            $host = preg_replace(
                '/[^A-Za-z0-9.\\-:\\[\\]]/',
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
"""
replacement = """    $method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $requestPath = rawurldecode((string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/connect'), PHP_URL_PATH) ?: '/connect'));
    $endpointToken = '';
    if ($requestPath === '/connect' || $requestPath === '/connect/') {
        $endpointToken = '';
    } elseif (preg_match('#^/connect/([A-Za-z0-9_-]{8,64})/?$#', $requestPath, $m)) {
        $endpointToken = (string)$m[1];
    } else {
        teamdarkJson(['status'=>false,'reason'=>'Invalid Application']);
    }

    $app = AppRegistry::resolveEndpoint($endpointToken);
    if (!$app) {
        teamdarkJson(['status'=>false,'reason'=>'Invalid Application']);
    }

    $endpoint = AppRegistry::endpointUrl($app);
    if ($method === 'GET' || $method === 'HEAD') {
        teamdarkGateway($endpoint, (string)$app['name'], $method === 'HEAD');
    }
"""
s = once(s, marker, replacement, p)
s = once(s, "Security::rateLimit('teamdark-loader-connect', 300, 60);", "Security::rateLimit('teamdark-loader-connect-app-'.(int)$app['id'], 300, 60);", p)
s = once(
    s,
    "        $serial,\n        Security::clientIp()\n    );",
    "        $serial,\n        Security::clientIp(),\n        (int)$app['id']\n    );",
    p,
)
p.write_text(s, encoding='utf-8')


# -----------------------------------------------------------------------------
# Rewrites for custom Connect URLs + App API owner controller.
# -----------------------------------------------------------------------------
for filename, prefix in [('teamdark-panel/.htaccess', 'public/'), ('teamdark-panel/public/.htaccess', '')]:
    p, s = read(filename)
    s = once(s, "RewriteRule ^connect/?$ " + prefix + "connect.php [L,QSA]", "RewriteRule ^connect(?:/[A-Za-z0-9_-]{8,64})?/?$ " + prefix + "connect.php [L,QSA]", p)
    marker = "# Dedicated panel feature controllers.\n"
    s = once(s, marker, marker + "RewriteRule ^owner/apps(?:/.*)?$ " + prefix + "app-api-manager.php [L,QSA]\n", p)
    p.write_text(s, encoding='utf-8')


# -----------------------------------------------------------------------------
# View sidebar: dedicated owner App APIs button.
# -----------------------------------------------------------------------------
p, s = read('teamdark-panel/app/View.php')
s = once(
    s,
    ".self::navLink('/owner/server', 'Server & Maint.', 'dashboard', $path)\n",
    ".self::navLink('/owner/server', 'Server & Maint.', 'dashboard', $path)\n                .self::navLink('/owner/apps', 'App APIs', 'spark', $path)\n",
    p,
)
p.write_text(s, encoding='utf-8')


# -----------------------------------------------------------------------------
# Main UI: app selector on key generation; app selector on referrals; app labels.
# -----------------------------------------------------------------------------
p, s = read('teamdark-panel/public/index.php')
s = once(s, "    Auth,\n    BroadcastService,", "    AppRegistry,\n    Auth,\n    BroadcastService,", p)
s = once(s, "    'Auth',\n    'View',", "    'Auth',\n    'View',\n    'AppRegistry',", p)

marker = """        $rows = KeyManager::visibleKeys($user, $filter);

        $keyPolicy = PanelControl::settings();
"""
addition = """        $rows = KeyManager::visibleKeys($user, $filter);

        $availableApps = AppRegistry::activeForUser($user);
        $appOptions = '';
        foreach ($availableApps as $i => $appChoice) {
            $selected = ((int)$appChoice['id'] === AppRegistry::OFFICIAL_ID || (count($availableApps) === 1 && $i === 0)) ? ' selected' : '';
            $appOptions .= '<option value="'.(int)$appChoice['id'].'"'.$selected.'>'.View::e((string)$appChoice['name']).'</option>';
        }
        $appField = $appOptions !== ''
            ? '<div class="field"><label>Application API</label><select name="app_id" required>'.$appOptions.'</select><p class="hint">Keys are locked to this app namespace and will be rejected by other Connect URLs.</p></div>'
            : '<div class="alert">No application API is assigned to this account.</div>';

        $keyPolicy = PanelControl::settings();
"""
s = once(s, marker, addition, p)
s = once(s, "                .View::csrf()\n                .'<div class=\"field\"><label>Custom key", "                .View::csrf()\n                .$appField\n                .'<div class=\"field\"><label>Custom key", p)
s = once(
    s,
    ".'<div class=\"key-meta\"><span>'.View::e($expiry).'</span><span>'.View::e($deviceText).'</span>'.$labelHtml.'</div></div>'",
    ".'<div class=\"key-meta\"><span class=\"tag\">'.View::e((string)($row['app_name'] ?? 'Official')).'</span><span>'.View::e($expiry).'</span><span>'.View::e($deviceText).'</span>'.$labelHtml.'</div></div>'",
    p,
)
s = once(
    s,
    "                $unlimitedDevices,\n                input('custom_key')\n            );",
    "                $unlimitedDevices,\n                input('custom_key'),\n                (int)($_POST['app_id'] ?? 0)\n            );",
    p,
)
s = once(
    s,
    "                'Generated: '.$created['key'].' • Cost: '.$created['cost'].' credit(s).',",
    "                'Generated for '.$created['app_name'].': '.$created['key'].' • Cost: '.$created['cost'].' credit(s).',",
    p,
)

marker = """        foreach ($roles as $role) {
            $roleOptions .= '<option value="'.View::e($role).'">'.View::e(ucfirst($role)).'</option>';
        }

        $activeCount = 0;
"""
addition = """        foreach ($roles as $role) {
            $roleOptions .= '<option value="'.View::e($role).'">'.View::e(ucfirst($role)).'</option>';
        }

        $referralApps = AppRegistry::availableForReferral($user);
        $defaultReferralApps = array_flip(AppRegistry::defaultReferralAppIds($user));
        $referralAppChecks = '';
        foreach ($referralApps as $appChoice) {
            $appId = (int)$appChoice['id'];
            $referralAppChecks .= '<label class="system-choice-card"><input type="checkbox" name="app_ids[]" value="'.$appId.'"'.(isset($defaultReferralApps[$appId]) ? ' checked' : '').'>'
                .'<span><b>'.View::e((string)$appChoice['name']).'</b><small>'.View::e(AppRegistry::endpointUrl($appChoice)).'</small></span></label>';
        }
        if ($referralAppChecks === '') {
            $referralAppChecks = '<div class="alert">No active App API is available to allot. Ask Owner to assign one first.</div>';
        }

        $activeCount = 0;
"""
s = once(s, marker, addition, p)
s = once(
    s,
    "                .'<td><span class=\"tag\">'.View::e($invite['role']).'</span></td>'",
    "                .'<td><span class=\"tag\">'.View::e($invite['role']).'</span></td>'\n                .'<td>'.View::e((string)($invite['app_names'] ?? 'Official')).'</td>'",
    p,
)
s = once(s, "<tr><td colspan=\"7\">", "<tr><td colspan=\"8\">", p)
s = once(
    s,
    ".'<div class=\"field\"><label>Account role</label><select name=\"role\">'.$roleOptions.'</select></div>'\n            .'<button class=\"primary wide\">Create referral</button>'",
    ".'<div class=\"field\"><label>Account role</label><select name=\"role\">'.$roleOptions.'</select></div>'\n            .'<div class=\"field\"><label>Application APIs</label><div class=\"system-choice-grid\">'.$referralAppChecks.'</div><p class=\"hint\">Select one or many. The registered account automatically receives exactly these App APIs.</p></div>'\n            .'<button class=\"primary wide\">Create referral</button>'",
    p,
)
s = once(
    s,
    "<thead><tr><th>Referral</th><th>Role</th><th>Created by</th><th>Status</th><th>Registered user</th><th>Created</th><th>Action</th></tr></thead>",
    "<thead><tr><th>Referral</th><th>Role</th><th>App APIs</th><th>Created by</th><th>Status</th><th>Registered user</th><th>Created</th><th>Action</th></tr></thead>",
    p,
)
s = once(
    s,
    "$invite = ReferralManager::create($user, input('role', 'user'));",
    "$invite = ReferralManager::create($user, input('role', 'user'), $_POST['app_ids'] ?? []);",
    p,
)
s = once(
    s,
    "                'Referral created: '.$invite['code'],",
    "                'Referral created: '.$invite['code'].' • App APIs: '.count($invite['app_ids']),",
    p,
)
p.write_text(s, encoding='utf-8')


# -----------------------------------------------------------------------------
# Permanent CI guards.
# -----------------------------------------------------------------------------
p, s = read('.github/workflows/teamdark-panel-php-lint.yml')
marker = "      - name: Verify relaxed password and key editor wiring\n"
block = r'''      - name: Verify multi-app API registry and key isolation wiring
        shell: bash
        run: |
          set -euo pipefail
          test -f teamdark-panel/app/AppRegistry.php
          test -f teamdark-panel/public/app-api-manager.php
          grep -F 'CREATE TABLE IF NOT EXISTS app_registry' teamdark-panel/database/schema.sql >/dev/null
          grep -F 'CREATE TABLE IF NOT EXISTS user_app_access' teamdark-panel/database/schema.sql >/dev/null
          grep -F 'CREATE TABLE IF NOT EXISTS referral_app_access' teamdark-panel/database/schema.sql >/dev/null
          grep -F "'license_keys','app_id'" teamdark-panel/database/schema.sql >/dev/null
          grep -F "AppRegistry::generationApp" teamdark-panel/app/KeyManager.php >/dev/null
          grep -F "AND k.app_id=?" teamdark-panel/app/LoaderAuthService.php >/dev/null
          grep -F "AppRegistry::resolveEndpoint" teamdark-panel/public/connect.php >/dev/null
          grep -F 'connect(?:/[A-Za-z0-9_-]{8,64})?' teamdark-panel/.htaccess >/dev/null
          grep -F "'/owner/apps', 'App APIs'" teamdark-panel/app/View.php >/dev/null
          grep -F 'name="app_ids[]"' teamdark-panel/public/index.php >/dev/null

'''
s = once(s, marker, block + marker, p)
p.write_text(s, encoding='utf-8')

p, s = read('.github/workflows/teamdark-native-auth-contract.yml')
marker = "      - name: Run 19-case native auth integration suite\n"
block = "      - name: Run multi-app API isolation contract\n        shell: bash\n        run: php teamdark-panel/tests/multi_app_contract.php\n\n"
s = once(s, marker, block + marker, p)
p.write_text(s, encoding='utf-8')
