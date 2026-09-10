from pathlib import Path


def replace_once(path: str, old: str, new: str, label: str) -> None:
    p = Path(path)
    text = p.read_text(encoding='utf-8')
    count = text.count(old)
    if count != 1:
        raise SystemExit(f'{label}: expected 1 match, found {count}')
    p.write_text(text.replace(old, new, 1), encoding='utf-8')


# -----------------------------------------------------------------------------
# AppRegistry: optional owner-selected prefix + strict referral API selection.
# -----------------------------------------------------------------------------
path = 'teamdark-panel/app/AppRegistry.php'
replace_once(
    path,
    "    public const MAX_REFERRAL_APPS = 25;\n",
    "    public const MAX_REFERRAL_APPS = 25;\n    public const MAX_ENDPOINT_PREFIX = 24;\n",
    'app registry prefix constant',
)
replace_once(
    path,
    "        $ids = self::normalizeIds($raw);\n        if (!$ids) $ids = self::defaultReferralAppIds($actor);\n        if (!$ids) throw new RuntimeException('Select at least one application API for this referral.');\n",
    "        $ids = self::normalizeIds($raw);\n        if (!$ids) throw new RuntimeException('Select at least one application API for this referral.');\n",
    'strict referral app selection',
)
replace_once(
    path,
    "    public static function createApp(array $actor, string $name, string $notes = ''): array\n",
    """    private static function normalizeEndpointPrefix(mixed $raw): string
    {
        $prefix = trim((string)$raw);
        if ($prefix === '') return '';
        if (
            strlen($prefix) > self::MAX_ENDPOINT_PREFIX
            || !preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9_-]{0,22}[A-Za-z0-9])?$/', $prefix)
        ) {
            throw new RuntimeException('Endpoint prefix must be 1-24 letters, numbers, dash or underscore, and start/end with a letter or number.');
        }
        return $prefix;
    }

    private static function newEndpointToken(mixed $rawPrefix = ''): string
    {
        $prefix = self::normalizeEndpointPrefix($rawPrefix);
        $random = bin2hex(random_bytes($prefix === '' ? 16 : 12));
        return $prefix === '' ? $random : $prefix.'-'.$random;
    }

    public static function createApp(array $actor, string $name, string $notes = '', string $endpointPrefix = ''): array
""",
    'app registry prefix helpers',
)
replace_once(
    path,
    "            $token = bin2hex(random_bytes(16));\n            try {\n                $pdo->prepare(\n                    \"INSERT INTO app_registry(name,endpoint_token,notes,status,is_official,created_by)\n                     VALUES(?,?,?,'active',0,?)\"\n                )->execute([$name, $token, $notes, (int)$actor['id']]);\n",
    "            $token = self::newEndpointToken($endpointPrefix);\n            try {\n                $pdo->prepare(\n                    \"INSERT INTO app_registry(name,endpoint_token,notes,status,is_official,created_by)\n                     VALUES(?,?,?,'active',0,?)\"\n                )->execute([$name, $token, $notes, (int)$actor['id']]);\n",
    'create app endpoint token',
)
replace_once(
    path,
    "    public static function rotateEndpoint(array $actor, int $appId): array\n",
    "    public static function rotateEndpoint(array $actor, int $appId, string $endpointPrefix = ''): array\n",
    'rotate endpoint signature',
)
replace_once(
    path,
    "        for ($attempt = 0; $attempt < 8; $attempt++) {\n            $token = bin2hex(random_bytes(16));\n            try {\n                $q = $pdo->prepare(\n                    \"UPDATE app_registry SET endpoint_token=? WHERE id=? AND is_official=0\"\n",
    "        for ($attempt = 0; $attempt < 8; $attempt++) {\n            $token = self::newEndpointToken($endpointPrefix);\n            try {\n                $q = $pdo->prepare(\n                    \"UPDATE app_registry SET endpoint_token=? WHERE id=? AND is_official=0\"\n",
    'rotate endpoint token',
)

# -----------------------------------------------------------------------------
# ReferralManager: validated invites carry the exact selected API endpoint info.
# -----------------------------------------------------------------------------
path = 'teamdark-panel/app/ReferralManager.php'
replace_once(
    path,
    """        $appQ = $pdo->prepare(
            \"SELECT a.id
             FROM referral_app_access ra
             JOIN app_registry a ON a.id=ra.app_id
             WHERE ra.referral_id=? AND a.status='active'
             ORDER BY a.is_official DESC,a.id ASC\"
        );
        $appQ->execute([(int)$invite['id']]);
        $appIds = array_map('intval', $appQ->fetchAll(\\PDO::FETCH_COLUMN) ?: []);
""",
    """        $appQ = $pdo->prepare(
            \"SELECT a.id,a.name,a.endpoint_token,a.is_official,a.status
             FROM referral_app_access ra
             JOIN app_registry a ON a.id=ra.app_id
             WHERE ra.referral_id=? AND a.status='active'
             ORDER BY a.is_official DESC,a.id ASC\"
        );
        $appQ->execute([(int)$invite['id']]);
        $appRows = $appQ->fetchAll() ?: [];
        $appIds = array_map(static fn(array $app): int => (int)$app['id'], $appRows);
""",
    'referral app detail query',
)
replace_once(
    path,
    """        $invite['app_ids'] = $appIds;
        return $invite;
""",
    """        $invite['app_ids'] = $appIds;
        $invite['app_apis'] = array_map(
            static fn(array $app): array => [
                'id'=>(int)$app['id'],
                'name'=>(string)$app['name'],
                'endpoint'=>AppRegistry::endpointUrl($app),
                'is_official'=>(int)$app['is_official'] === 1,
            ],
            $appRows
        );
        return $invite;
""",
    'referral endpoint details',
)

# -----------------------------------------------------------------------------
# Owner App API UI: optional prefix on create and rotate.
# -----------------------------------------------------------------------------
path = 'teamdark-panel/public/app-api-manager.php'
replace_once(
    path,
    """                $app = AppRegistry::createApp(
                    $user,
                    trim((string)($_POST['name'] ?? '')),
                    trim((string)($_POST['notes'] ?? ''))
                );
""",
    """                $app = AppRegistry::createApp(
                    $user,
                    trim((string)($_POST['name'] ?? '')),
                    trim((string)($_POST['notes'] ?? '')),
                    trim((string)($_POST['endpoint_prefix'] ?? ''))
                );
""",
    'create app prefix input',
)
replace_once(
    path,
    """                $app = AppRegistry::rotateEndpoint($user, (int)($_POST['app_id'] ?? 0));
""",
    """                $app = AppRegistry::rotateEndpoint(
                    $user,
                    (int)($_POST['app_id'] ?? 0),
                    trim((string)($_POST['endpoint_prefix'] ?? ''))
                );
""",
    'rotate app prefix input',
)
replace_once(
    path,
    """                .'<form method=\"post\" action=\"/owner/apps/rotate\" class=\"inline\" data-confirm=\"Rotate this Connect URL? The old URL will stop working immediately.\">'
                .View::csrf().'<input type=\"hidden\" name=\"app_id\" value=\"'.(int)$app['id'].'\"><button class=\"ghost compact danger\">Rotate URL</button></form>';
""",
    """                .'<form method=\"post\" action=\"/owner/apps/rotate\" class=\"inline endpoint-rotate-form\" data-confirm=\"Rotate this Connect URL? The old URL will stop working immediately.\">'
                .View::csrf().'<input type=\"hidden\" name=\"app_id\" value=\"'.(int)$app['id'].'\">'
                .'<input class=\"control-input\" name=\"endpoint_prefix\" maxlength=\"24\" pattern=\"[A-Za-z0-9](?:[A-Za-z0-9_-]{0,22}[A-Za-z0-9])?\" placeholder=\"Prefix (optional)\" title=\"1-24 letters, numbers, dash or underscore\">'
                .'<button class=\"ghost compact danger\">Rotate URL</button></form>';
""",
    'rotate prefix UI',
)
replace_once(
    path,
    """        .'<p class=\"muted\">The Official app always keeps /connect. Every new app receives a random stable /connect/&lt;channel&gt; URL.</p>'
        .'<form method=\"post\" action=\"/owner/apps/create\" class=\"stack\">'.View::csrf()
        .'<div class=\"form-row\"><div class=\"field\"><label>App / API name</label><input name=\"name\" maxlength=\"80\" required placeholder=\"Example: TeamDark Lite\"></div><div class=\"field\"><label>Notes</label><input name=\"notes\" maxlength=\"240\" placeholder=\"Optional client/package note\"></div></div>'
        .'<button class=\"primary\">Create App API</button></form></section>'
""",
    """        .'<p class=\"muted\">The Official app always keeps /connect. For a custom App API you may choose the beginning of its channel; a secure random suffix is always appended.</p>'
        .'<form method=\"post\" action=\"/owner/apps/create\" class=\"stack\">'.View::csrf()
        .'<div class=\"form-row\"><div class=\"field\"><label>App / API name</label><input name=\"name\" maxlength=\"80\" required placeholder=\"Example: TeamDark Lite\"></div><div class=\"field\"><label>Notes</label><input name=\"notes\" maxlength=\"240\" placeholder=\"Optional client/package note\"></div></div>'
        .'<div class=\"field\"><label>Endpoint prefix <span class=\"optional\">optional</span></label><input name=\"endpoint_prefix\" maxlength=\"24\" pattern=\"[A-Za-z0-9](?:[A-Za-z0-9_-]{0,22}[A-Za-z0-9])?\" placeholder=\"Example: teamdark\" title=\"1-24 letters, numbers, dash or underscore\"><p class=\"hint\">Example: teamdark → /connect/teamdark-&lt;secure-random&gt;. Leave blank to keep a fully random channel.</p></div>'
        .'<button class=\"primary\">Create App API</button></form></section>'
""",
    'create prefix UI',
)

# -----------------------------------------------------------------------------
# Main registration success + fallback registration controller parity.
# -----------------------------------------------------------------------------
path = 'teamdark-panel/public/index.php'
replace_once(
    path,
    """        unset($_SESSION['registration_success']);

        $body = '<section class=\"auth registration-success\"><div class=\"card\">'
""",
    """        unset($_SESSION['registration_success']);

        $registeredApiHtml = '';
        foreach (($state['app_apis'] ?? []) as $appApi) {
            if (!is_array($appApi)) continue;
            $apiName = trim((string)($appApi['name'] ?? ''));
            $apiEndpoint = trim((string)($appApi['endpoint'] ?? ''));
            if ($apiName === '' || $apiEndpoint === '') continue;
            $registeredApiHtml .= '<div class=\"system-preview\"><span>'.View::e($apiName).'</span><code>'.View::e($apiEndpoint).'</code><button type=\"button\" class=\"ghost compact\" data-copy=\"'.View::e($apiEndpoint).'\">Copy endpoint</button></div>';
        }
        if ($registeredApiHtml === '') {
            $registeredApiHtml = '<div class=\"alert\">Assigned App API details are available after login from My App APIs.</div>';
        }

        $body = '<section class=\"auth registration-success\"><div class=\"card\">'
""",
    'registration success endpoint cards',
)
replace_once(
    path,
    """            .'<div><span>Password</span><strong>Saved securely • not displayed</strong></div>'
            .'</div>'
            .'<div class=\"countdown-panel\"><span class=\"security-orb\">15</span>'
""",
    """            .'<div><span>Password</span><strong>Saved securely • not displayed</strong></div>'
            .'</div>'
            .'<div class=\"registration-api-access\"><div class=\"eyebrow\">ASSIGNED APP API ACCESS</div><h3>Your referral endpoints</h3><p class=\"muted\">These are exactly the App APIs allotted to the referral used for this account.</p>'.$registeredApiHtml.'</div>'
            .'<div class=\"countdown-panel\"><span class=\"security-orb\">15</span>'
""",
    'registration success assigned api section',
)
replace_once(
    path,
    """            $uid = (int)$pdo->lastInsertId();
            $grantedAppIds = ReferralManager::grantInviteAppsToUser(
                $pdo,
                (int)$invite['id'],
                $uid,
                (int)$invite['created_by']
            );
            $referralGrant = ReferralManager::grantInviteBalanceToUser($pdo, $invite, $uid);
""",
    """            $uid = (int)$pdo->lastInsertId();
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
""",
    'main registration exact api details',
)
replace_once(
    path,
    """            'starting_balance'=>$signup + $referralGrant,
            'created_at'=>date('Y-m-d H:i:s'),
""",
    """            'starting_balance'=>$signup + $referralGrant,
            'app_apis'=>$registrationApps,
            'created_at'=>date('Y-m-d H:i:s'),
""",
    'main registration state endpoints',
)

# -----------------------------------------------------------------------------
# Production relaxed registration route: preview + exact success details.
# -----------------------------------------------------------------------------
path = 'teamdark-panel/public/register-relaxed.php'
replace_once(
    path,
    """        $referralGrantPreview = (int)($invite['grant_balance'] ?? 0);
        $body = '<section class=\"auth\"><div class=\"card\"><div class=\"eyebrow\">SECURE REGISTRATION</div><h1>Create account</h1>'
""",
    """        $referralGrantPreview = (int)($invite['grant_balance'] ?? 0);
        $inviteApiPreview = '';
        foreach (($invite['app_apis'] ?? []) as $appApi) {
            if (!is_array($appApi)) continue;
            $apiName = trim((string)($appApi['name'] ?? ''));
            $apiEndpoint = trim((string)($appApi['endpoint'] ?? ''));
            if ($apiName === '' || $apiEndpoint === '') continue;
            $inviteApiPreview .= '<div class=\"system-preview\"><span>'.View::e($apiName).'</span><code>'.View::e($apiEndpoint).'</code><button type=\"button\" class=\"ghost compact\" data-copy=\"'.View::e($apiEndpoint).'\">Copy endpoint</button></div>';
        }
        $body = '<section class=\"auth\"><div class=\"card\"><div class=\"eyebrow\">SECURE REGISTRATION</div><h1>Create account</h1>'
""",
    'relaxed referral endpoint preview builder',
)
replace_once(
    path,
    """            .($referralGrantPreview > 0 ? ' Includes '.number_format($referralGrantPreview).' starting credits.' : '').'</p>'
            .'<form method=\"post\" action=\"/register\" class=\"stack\" data-busy=\"Creating account…\">'.View::csrf()
""",
    """            .($referralGrantPreview > 0 ? ' Includes '.number_format($referralGrantPreview).' starting credits.' : '').'</p>'
            .'<div class=\"registration-api-access\"><div class=\"eyebrow\">REFERRAL APP API</div><p class=\"muted\">Registration will grant exactly the App API access selected by the referral creator.</p>'.$inviteApiPreview.'</div>'
            .'<form method=\"post\" action=\"/register\" class=\"stack\" data-busy=\"Creating account…\">'.View::csrf()
""",
    'relaxed referral endpoint preview',
)
replace_once(
    path,
    """        $uid = (int)$pdo->lastInsertId();
        $grantedAppIds = ReferralManager::grantInviteAppsToUser(
            $pdo,
            (int)$invite['id'],
            $uid,
            (int)$invite['created_by']
        );
        $referralGrant = ReferralManager::grantInviteBalanceToUser($pdo, $invite, $uid);
""",
    """        $uid = (int)$pdo->lastInsertId();
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
""",
    'relaxed exact api details',
)
replace_once(
    path,
    """        'starting_balance'=>$signup + $referralGrant,
        'created_at'=>date('Y-m-d H:i:s'),
""",
    """        'starting_balance'=>$signup + $referralGrant,
        'app_apis'=>$registrationApps,
        'created_at'=>date('Y-m-d H:i:s'),
""",
    'relaxed registration state endpoints',
)

# -----------------------------------------------------------------------------
# Contract tests: prefix, no fallback, endpoint details and exact referral grant.
# -----------------------------------------------------------------------------
path = 'teamdark-panel/tests/multi_app_contract.php'
replace_once(
    path,
    """$appA = AppRegistry::createApp($owner, 'Contract App Alpha', 'Multi-app contract A');
$appB = AppRegistry::createApp($owner, 'Contract App Beta', 'Multi-app contract B');
multiCheck((int)$appA['id'] !== (int)$appB['id'], 'custom app APIs are isolated records');
""",
    """$appA = AppRegistry::createApp($owner, 'Contract App Alpha', 'Multi-app contract A', 'alpha');
$appB = AppRegistry::createApp($owner, 'Contract App Beta', 'Multi-app contract B', 'beta');
multiCheck((int)$appA['id'] !== (int)$appB['id'], 'custom app APIs are isolated records');
multiCheck(str_starts_with((string)$appA['endpoint_token'], 'alpha-'), 'owner-selected endpoint prefix is preserved with random suffix');
multiCheck(str_starts_with((string)$appB['endpoint_token'], 'beta-'), 'second owner-selected endpoint prefix is preserved with random suffix');
""",
    'contract custom prefixes',
)
replace_once(
    path,
    """AppRegistry::replaceUserAccess($owner, $userId, [(int)$appA['id'], (int)$appB['id']]);
$invite = ReferralManager::create($owner, 'user', [(int)$appA['id'], (int)$appB['id']], 250);
""",
    """AppRegistry::replaceUserAccess($owner, $userId, [(int)$appA['id'], (int)$appB['id']]);
$missingSelectionRejected = false;
try {
    ReferralManager::create($owner, 'user', []);
} catch (RuntimeException $e) {
    $missingSelectionRejected = str_contains($e->getMessage(), 'Select at least one application API');
}
multiCheck($missingSelectionRejected, 'referral API selection is explicit and never silently falls back to Official');

$invite = ReferralManager::create($owner, 'user', [(int)$appA['id'], (int)$appB['id']], 250);
""",
    'contract explicit referral selection',
)
replace_once(
    path,
    """multiCheck((int)$countQ->fetchColumn() === 2, 'one referral can carry multiple app APIs');

$pdo->prepare(
""",
    """multiCheck((int)$countQ->fetchColumn() === 2, 'one referral can carry multiple app APIs');
$validatedInvite = ReferralManager::validateForRegistration((string)$invite['code']);
multiCheck(count($validatedInvite['app_apis'] ?? []) === 2, 'validated referral exposes exactly its allotted App API details');
foreach ($validatedInvite['app_apis'] as $referralApp) {
    multiCheck(str_contains((string)$referralApp['endpoint'], '/connect/'), 'validated referral exposes the allotted Connect endpoint');
}

$pdo->prepare(
""",
    'contract referral endpoint details',
)
replace_once(
    path,
    """$rotated = AppRegistry::rotateEndpoint($owner, (int)$appB['id']);
multiCheck((string)$rotated['endpoint_token'] !== $oldToken, 'owner can rotate custom Connect URL');
""",
    """$rotated = AppRegistry::rotateEndpoint($owner, (int)$appB['id'], 'rotated');
multiCheck((string)$rotated['endpoint_token'] !== $oldToken, 'owner can rotate custom Connect URL');
multiCheck(str_starts_with((string)$rotated['endpoint_token'], 'rotated-'), 'owner can choose the prefix when rotating a Connect URL');
""",
    'contract rotate prefix',
)

# -----------------------------------------------------------------------------
# Permanent audit assertions so future UI/backend changes cannot regress this.
# -----------------------------------------------------------------------------
path = '.github/workflows/teamdark-panel-php-lint.yml'
replace_once(
    path,
    """          grep -F \"name=\\\"grant_balance\\\"\" teamdark-panel/public/index.php >/dev/null
          grep -F 'grantInviteBalanceToUser' teamdark-panel/public/register-relaxed.php >/dev/null
""",
    """          grep -F \"name=\\\"grant_balance\\\"\" teamdark-panel/public/index.php >/dev/null
          grep -F 'grantInviteBalanceToUser' teamdark-panel/public/register-relaxed.php >/dev/null
          grep -F 'MAX_ENDPOINT_PREFIX = 24' teamdark-panel/app/AppRegistry.php >/dev/null
          grep -F 'newEndpointToken' teamdark-panel/app/AppRegistry.php >/dev/null
          grep -F 'name=\"endpoint_prefix\"' teamdark-panel/public/app-api-manager.php >/dev/null
          grep -F 'ASSIGNED APP API ACCESS' teamdark-panel/public/index.php >/dev/null
          grep -F 'REFERRAL APP API' teamdark-panel/public/register-relaxed.php >/dev/null
          grep -F \"'app_apis'=>\" teamdark-panel/public/register-relaxed.php >/dev/null
""",
    'panel audit assertions',
)

print('Endpoint prefix + strict referral endpoint patch applied.')
