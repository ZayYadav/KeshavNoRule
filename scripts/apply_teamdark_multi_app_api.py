from pathlib import Path
import re


def load(path: str):
    p = Path(path)
    return p, p.read_text(encoding='utf-8')


def save(p: Path, text: str):
    p.write_text(text, encoding='utf-8')


def replace_once(text: str, old: str, new: str, label: str, already: str | None = None) -> str:
    if already and already in text:
        return text
    if old not in text:
        raise SystemExit(f'{label}: marker missing')
    return text.replace(old, new, 1)


def regex_once(text: str, pattern: str, replacement: str, label: str, already: str | None = None) -> str:
    if already and already in text:
        return text
    out, n = re.subn(pattern, lambda _m: replacement, text, count=1, flags=re.S)
    if n != 1:
        raise SystemExit(f'{label}: expected one replacement, got {n}')
    return out


# 1) Referral validation must fail closed and the POST path must validate while
# holding the referral row lock. Runtime fallback to Official is intentionally
# removed; schema migration is responsible for one-time legacy backfill.
p, s = load('teamdark-panel/app/ReferralManager.php')
referral_replacement = r'''    public static function validateForRegistration(string $code): array
    {
        $code = trim($code);
        if ($code === '' || strlen($code) > 80) {
            throw new RuntimeException('A valid referral code is required.');
        }

        self::expireDue();
        $pdo = Database::pdo();
        $q = $pdo->prepare(
            "SELECT i.*,u.role AS creator_role,u.status AS creator_status
             FROM referral_invites i
             JOIN users u ON u.id=i.created_by
             WHERE i.code=?
             LIMIT 1"
        );
        $q->execute([$code]);
        return self::validateLoadedInvite($pdo, $q->fetch());
    }

    public static function lockForRegistration(\PDO $pdo, string $code): array
    {
        $code = trim($code);
        if ($code === '' || strlen($code) > 80) {
            throw new RuntimeException('A valid referral code is required.');
        }

        $q = $pdo->prepare(
            "SELECT i.*,u.role AS creator_role,u.status AS creator_status
             FROM referral_invites i
             JOIN users u ON u.id=i.created_by
             WHERE i.code=?
             LIMIT 1
             FOR UPDATE"
        );
        $q->execute([$code]);
        return self::validateLoadedInvite($pdo, $q->fetch());
    }

    private static function validateLoadedInvite(\PDO $pdo, array|false $invite): array
    {
        if (!$invite || ($invite['status'] ?? '') !== 'pending') {
            throw new RuntimeException('This referral is invalid, used, revoked or expired.');
        }
        if (($invite['expires_at'] ?? null) && strtotime((string)$invite['expires_at']) <= time()) {
            throw new RuntimeException('This referral is invalid, used, revoked or expired.');
        }
        if (($invite['creator_status'] ?? '') !== 'active') {
            throw new RuntimeException('This referral is no longer authorized.');
        }
        if (!self::creatorCanIssueRole((string)$invite['creator_role'], (string)$invite['role'])) {
            throw new RuntimeException('This referral is no longer authorized.');
        }

        $appQ = $pdo->prepare(
            "SELECT a.id
             FROM referral_app_access ra
             JOIN app_registry a ON a.id=ra.app_id
             WHERE ra.referral_id=? AND a.status='active'
             ORDER BY a.is_official DESC,a.id ASC"
        );
        $appQ->execute([(int)$invite['id']]);
        $appIds = array_map('intval', $appQ->fetchAll(\PDO::FETCH_COLUMN) ?: []);

        // Fail closed. Legacy referrals are backfilled exactly once by schema.sql.
        // An invite that loses all active app mappings must never silently become Official.
        if (!$appIds) {
            throw new RuntimeException('This referral has no active application API assigned.');
        }

        if (($invite['creator_role'] ?? '') !== 'owner') {
            $placeholders = implode(',', array_fill(0, count($appIds), '?'));
            $params = array_merge([(int)$invite['created_by']], $appIds);
            $accessQ = $pdo->prepare(
                "SELECT COUNT(DISTINCT ua.app_id)
                 FROM user_app_access ua
                 JOIN app_registry a ON a.id=ua.app_id
                 WHERE ua.user_id=?
                   AND a.status='active'
                   AND ua.app_id IN (".$placeholders.")"
            );
            $accessQ->execute($params);
            if ((int)$accessQ->fetchColumn() !== count($appIds)) {
                throw new RuntimeException('This referral contains application access that is no longer authorized.');
            }
        }

        $invite['app_ids'] = $appIds;
        return $invite;
    }

    public static function creatorCanIssueRole'''
s = regex_once(
    s,
    r"    public static function validateForRegistration\(string \$code\): array\n    \{.*?\n    \}\n\n    public static function creatorCanIssueRole",
    referral_replacement,
    'ReferralManager fail-closed validation',
    'public static function lockForRegistration'
)
save(p, s)


# 2) App disable/pruning is one atomic state transition. This removes the
# partial-state window where the app was disabled but pending referrals were
# not yet cleaned up.
p, s = load('teamdark-panel/app/AppRegistry.php')
set_enabled_replacement = r'''    public static function setEnabled(array $actor, int $appId, bool $enabled): void
    {
        Auth::requireRole($actor, 'owner');
        if ($appId <= 0 || $appId === self::OFFICIAL_ID) {
            throw new RuntimeException('Official API cannot be disabled.');
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $q = $pdo->prepare(
                'SELECT id,status,is_official FROM app_registry WHERE id=? LIMIT 1 FOR UPDATE'
            );
            $q->execute([$appId]);
            $app = $q->fetch();
            if (!$app || (int)$app['is_official'] === 1) {
                throw new RuntimeException('Application API not found.');
            }

            $next = $enabled ? 'active' : 'disabled';
            if ((string)$app['status'] === $next) {
                throw new RuntimeException('Application API is already in that state.');
            }

            $pdo->prepare('UPDATE app_registry SET status=? WHERE id=?')
                ->execute([$next, $appId]);

            if (!$enabled) {
                $pdo->prepare(
                    "DELETE ra FROM referral_app_access ra
                     JOIN referral_invites ri ON ri.id=ra.referral_id
                     WHERE ra.app_id=? AND ri.status='pending'"
                )->execute([$appId]);
                $pdo->exec(
                    "UPDATE referral_invites ri
                     SET ri.status='revoked'
                     WHERE ri.status='pending'
                       AND NOT EXISTS (
                           SELECT 1 FROM referral_app_access ra
                           WHERE ra.referral_id=ri.id
                       )"
                );
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        try {
            Security::audit((int)$actor['id'], 'app_api_status_changed', [
                'app_id'=>$appId,
                'enabled'=>$enabled,
            ]);
        } catch (Throwable) {
        }
    }

    public static function rotateEndpoint'''
s = regex_once(
    s,
    r"    public static function setEnabled\(array \$actor, int \$appId, bool \$enabled\): void\n    \{.*?\n    \}\n\n    public static function rotateEndpoint",
    set_enabled_replacement,
    'AppRegistry transactional app status',
    "SELECT id,status,is_official FROM app_registry WHERE id=? LIMIT 1 FOR UPDATE"
)
save(p, s)


# 3) Even internal/direct authentication calls must reject a disabled app.
p, s = load('teamdark-panel/app/LoaderAuthService.php')
s = replace_once(
    s,
    "                 FROM license_keys k\n                 JOIN users u ON u.id=k.owner_user_id\n                 WHERE k.key_hash IN (?,?) AND k.app_id=?",
    "                 FROM license_keys k\n                 JOIN users u ON u.id=k.owner_user_id\n                 JOIN app_registry a ON a.id=k.app_id AND a.status='active'\n                 WHERE k.key_hash IN (?,?) AND k.app_id=?",
    'LoaderAuthService active app guard',
    "JOIN app_registry a ON a.id=k.app_id AND a.status='active'"
)
save(p, s)


# 4) Harden the dedicated registration controller: maintenance/settings/IP
# parity, safe public errors, locked referral authorization, and correct success
# state consumed by /register/success.
p, s = load('teamdark-panel/public/register-relaxed.php')
s = replace_once(
    s,
    "function regTakeFlash(): string\n{\n    $f = $_SESSION['flash'] ?? null;\n    unset($_SESSION['flash']);\n    if (!is_array($f)) return '';\n    return '<div data-flash role=\"status\" class=\"alert '.(($f[0] ?? '') === 'ok' ? 'ok' : '').'\">'.View::e((string)($f[1] ?? '')).'</div>';\n}\n",
    "function regTakeFlash(): string\n{\n    $f = $_SESSION['flash'] ?? null;\n    unset($_SESSION['flash']);\n    if (!is_array($f)) return '';\n    return '<div data-flash role=\"status\" class=\"alert '.(($f[0] ?? '') === 'ok' ? 'ok' : '').'\">'.View::e((string)($f[1] ?? '')).'</div>';\n}\n\nfunction regSafeMessage(Throwable $e, string $fallback = 'Registration unavailable.'): string\n{\n    if ($e instanceof PDOException) {\n        error_log('TeamDark registration database failure at '.basename($e->getFile()).':'.$e->getLine());\n        return $fallback;\n    }\n    if ($e instanceof RuntimeException) {\n        $message = trim($e->getMessage());\n        return $message === '' ? $fallback : substr($message, 0, 300);\n    }\n    return $fallback;\n}\n",
    'registration safe message helper',
    'function regSafeMessage'
)
s = replace_once(
    s,
    "    if ($method === 'GET') {\n",
    "    if (PanelControl::blocked(null)) {\n        http_response_code(503);\n        header('Retry-After: 300');\n        View::page('Maintenance', '<section class=\"auth\"><div class=\"card\"><div class=\"eyebrow\">PANEL OFFLINE</div><h1>We will be back.</h1><p class=\"muted\">'.View::e((string)PanelControl::settings()['message']).'</p></div></section>');\n        exit;\n    }\n\n    if ($method === 'GET') {\n",
    'registration maintenance guard',
    "if (PanelControl::blocked(null))"
)
s = replace_once(
    s,
    "            } catch (Throwable $e) {\n                $flash .= '<div class=\"alert\">'.View::e($e->getMessage()).'</div>';\n            }",
    "            } catch (Throwable $e) {\n                $flash .= '<div class=\"alert\">'.View::e(regSafeMessage($e, 'Referral validation is temporarily unavailable.')).'</div>';\n            }",
    'registration GET safe error'
)
s = replace_once(
    s,
    "    Security::verifyCsrf($_POST['csrf'] ?? null);\n    Security::rateLimit('register-ip', 15, 3600);",
    "    Security::verifyCsrf($_POST['csrf'] ?? null);\n    if (!(bool)(PanelControl::settings()['registration_open'] ?? true)) {\n        regFlash('err', 'New registrations are paused by the owner.');\n        regRedirect('/register');\n    }\n    if (Security::ownerIpPolicyBlocked(null)) {\n        http_response_code(403);\n        regFlash('err', 'Registration is not available from this network.');\n        regRedirect('/register');\n    }\n    Security::rateLimit('register-ip', 15, 3600);",
    'registration owner controls parity',
    "New registrations are paused by the owner."
)
s = replace_once(
    s,
    "    $password = (string)($_POST['password'] ?? '');\n\n    try {",
    "    $password = (string)($_POST['password'] ?? '');\n    $pdo = null;\n\n    try {",
    'registration PDO initialization',
    '$pdo = null;'
)
old_lock = r'''        $invite = ReferralManager::validateForRegistration($ref);
        $pdo = Database::pdo();
        $pdo->beginTransaction();

        $lock = $pdo->prepare(
            "SELECT i.*,u.role creator_role
             FROM referral_invites i
             JOIN users u ON u.id=i.created_by
             WHERE i.id=? FOR UPDATE"
        );
        $lock->execute([(int)$invite['id']]);
        $invite = $lock->fetch();

        if (!$invite || $invite['status'] !== 'pending' || ($invite['expires_at'] && strtotime((string)$invite['expires_at']) <= time())) {
            throw new RuntimeException('This referral is invalid, used, revoked or expired.');
        }
        if (!ReferralManager::creatorCanIssueRole((string)$invite['creator_role'], (string)$invite['role'])) {
            throw new RuntimeException('This referral is no longer authorized.');
        }
'''
new_lock = r'''        $pdo = Database::pdo();
        $pdo->beginTransaction();
        $invite = ReferralManager::lockForRegistration($pdo, $ref);
'''
s = replace_once(
    s,
    old_lock,
    new_lock,
    'registration locked referral validation',
    'ReferralManager::lockForRegistration($pdo, $ref)'
)
s = replace_once(
    s,
    "    } catch (Throwable $e) {\n        if ($pdo->inTransaction()) $pdo->rollBack();",
    "    } catch (Throwable $e) {\n        if ($pdo instanceof \\PDO && $pdo->inTransaction()) $pdo->rollBack();",
    'registration guarded rollback',
    '$pdo instanceof \\PDO'
)
s = replace_once(
    s,
    "        'referral'=>$ref,\n        'created_at'=>time(),\n",
    "        'referral'=>$ref,\n        'signup_bonus'=>$signup,\n        'created_at'=>date('Y-m-d H:i:s'),\n        'created_ts'=>time(),\n",
    'registration success state contract',
    "'created_ts'=>time()"
)
s = replace_once(
    s,
    "    View::page('Registration unavailable', '<section class=\"auth\"><div class=\"card\"><h1>Registration unavailable</h1><div class=\"alert\">'.View::e($e->getMessage()).'</div><a class=\"btn\" href=\"/login\">Go back</a></div></section>');",
    "    View::page('Registration unavailable', '<section class=\"auth\"><div class=\"card\"><h1>Registration unavailable</h1><div class=\"alert\">'.View::e(regSafeMessage($e)).'</div><a class=\"btn\" href=\"/login\">Go back</a></div></section>');",
    'registration outer safe error'
)
save(p, s)


# 5) Keep the fallback index registration path on the same locked authorization
# contract so alternative web-server routing cannot weaken app isolation.
p, s = load('teamdark-panel/public/index.php')
index_old = r'''        try {
            $q = $pdo->prepare(
                "SELECT i.id,i.role,i.created_by,i.expires_at,
                        u.role creator_role,u.status creator_status
                 FROM referral_invites i
                 JOIN users u ON u.id=i.created_by
                 WHERE i.code=?
                   AND i.status='pending'
                   AND (i.expires_at IS NULL OR i.expires_at>NOW())
                 LIMIT 1
                 FOR UPDATE"
            );
            $q->execute([$ref]);
            $invite = $q->fetch();

            if (
                !$invite
                || $invite['creator_status'] !== 'active'
                || !ReferralManager::creatorCanIssueRole(
                    (string)$invite['creator_role'],
                    (string)$invite['role']
                )
            ) {
                throw new RuntimeException('Invalid or already used referral code.');
            }

            if (!in_array($invite['role'], ['admin','reseller','user'], true)) {
                throw new RuntimeException('Invalid referral role.');
            }
'''
index_new = r'''        try {
            $invite = ReferralManager::lockForRegistration($pdo, $ref);

            if (!in_array($invite['role'], ['admin','reseller','user'], true)) {
                throw new RuntimeException('Invalid referral role.');
            }
'''
s = replace_once(
    s,
    index_old,
    index_new,
    'index fallback registration lock',
    "$invite = ReferralManager::lockForRegistration($pdo, $ref);"
)
# JSON license list must expose namespace metadata now that keys are multi-app.
s = replace_once(
    s,
    "                'game'=>$row['game'],\n                'owner'=>$row['owner_name'],",
    "                'game'=>$row['game'],\n                'app_id'=>(int)($row['app_id_resolved'] ?? $row['app_id'] ?? AppRegistry::OFFICIAL_ID),\n                'app_name'=>(string)($row['app_name'] ?? 'Official'),\n                'owner'=>$row['owner_name'],",
    'license API app metadata',
    "'app_name'=>(string)($row['app_name'] ?? 'Official')"
)
save(p, s)


# 6) Critical migration fix: backfill old accounts/referrals only when each
# mapping table is first introduced. Re-running schema.sql must never recreate
# Owner-revoked Official access or add Official to custom-only referrals.
p, s = load('teamdark-panel/database/schema.sql')
s = replace_once(
    s,
    "CREATE TABLE IF NOT EXISTS user_app_access (",
    "SET @td_user_app_access_existed := (\n  SELECT COUNT(*) FROM information_schema.TABLES\n  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='user_app_access'\n);\n\nCREATE TABLE IF NOT EXISTS user_app_access (",
    'schema user access preexistence guard',
    '@td_user_app_access_existed'
)
s = replace_once(
    s,
    "INSERT IGNORE INTO user_app_access(user_id,app_id,granted_by,source)\nSELECT id,1,NULL,'migration' FROM users;",
    "INSERT IGNORE INTO user_app_access(user_id,app_id,granted_by,source)\nSELECT id,1,NULL,'migration' FROM users\nWHERE @td_user_app_access_existed = 0;",
    'schema user access one-time backfill'
)
s = replace_once(
    s,
    "CREATE TABLE IF NOT EXISTS referral_app_access (",
    "SET @td_referral_app_access_existed := (\n  SELECT COUNT(*) FROM information_schema.TABLES\n  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='referral_app_access'\n);\n\nCREATE TABLE IF NOT EXISTS referral_app_access (",
    'schema referral access preexistence guard',
    '@td_referral_app_access_existed'
)
s = replace_once(
    s,
    "INSERT IGNORE INTO referral_app_access(referral_id,app_id)\nSELECT id,1 FROM referral_invites;",
    "INSERT IGNORE INTO referral_app_access(referral_id,app_id)\nSELECT id,1 FROM referral_invites\nWHERE @td_referral_app_access_existed = 0;",
    'schema referral access one-time backfill'
)
save(p, s)


# 7) Native owner-controls fixture creates a legacy-style referral manually;
# make its Official mapping explicit now that runtime validation is fail-closed.
p, s = load('teamdark-panel/tests/owner_controls_integration.php')
s = replace_once(
    s,
    "    )->execute([$registrationRef, $owner['id']]);\n\n    $registrationPage = request($registerClient,'/register?ref='.$registrationRef);",
    "    )->execute([$registrationRef, $owner['id']]);\n    $registrationInviteId = (int)$pdo->lastInsertId();\n    $pdo->prepare('INSERT INTO referral_app_access(referral_id,app_id) VALUES(?,1)')\n        ->execute([$registrationInviteId]);\n\n    $registrationPage = request($registerClient,'/register?ref='.$registrationRef);",
    'owner controls explicit referral app fixture',
    '$registrationInviteId = (int)$pdo->lastInsertId();'
)
save(p, s)


# 8) Expand the service-level contract to cover disable/re-enable isolation and
# prevent regression to runtime Official fallback.
p, s = load('teamdark-panel/tests/multi_app_contract.php')
extra_tests = r'''
$ownerBetaKey = KeyManager::create(
    $owner,
    'Owner App Beta disable test',
    86400,
    false,
    1,
    false,
    'TD-MULTI-APP-BETA-OWNER-KEY',
    (int)$appB['id']
);
$isolationInvite = ReferralManager::create($owner, 'user', [(int)$appB['id']]);
AppRegistry::setEnabled($owner, (int)$appB['id'], false);

$disabledDirect = LoaderAuthService::authenticate(
    'PUBG',
    $ownerBetaKey['key'],
    'MULTI-APP-DISABLED-BETA',
    '127.0.0.1',
    (int)$appB['id']
);
multiCheck(
    ($disabledDirect['status'] ?? true) === false
    && ($disabledDirect['reason'] ?? '') === 'Invalid Key',
    'disabled app is rejected even through direct auth service calls'
);

$statusQ = $pdo->prepare('SELECT status FROM referral_invites WHERE id=?');
$statusQ->execute([(int)$isolationInvite['id']]);
multiCheck($statusQ->fetchColumn() === 'revoked', 'disabling the only app atomically revokes its pending referral');

$failClosed = false;
try {
    ReferralManager::validateForRegistration((string)$isolationInvite['code']);
} catch (RuntimeException $e) {
    $failClosed = str_contains($e->getMessage(), 'invalid')
        || str_contains($e->getMessage(), 'no active application API');
}
multiCheck($failClosed, 'referral without active app never falls back to Official');

AppRegistry::setEnabled($owner, (int)$appB['id'], true);
multiCheck(
    (int)(AppRegistry::resolveEndpoint((string)$rotated['endpoint_token'])['id'] ?? 0) === (int)$appB['id'],
    're-enabled app restores its existing custom Connect endpoint'
);
$statusQ->execute([(int)$isolationInvite['id']]);
multiCheck($statusQ->fetchColumn() === 'revoked', 're-enabling app does not resurrect revoked referrals');
'''
s = replace_once(
    s,
    "\necho \"Multi-app API contract OK\\n\";",
    extra_tests + "\necho \"Multi-app API contract OK\\n\";",
    'multi-app disable/fail-closed tests',
    'disabled app is rejected even through direct auth service calls'
)
save(p, s)

print('TeamDark multi-app audit hardening patch applied.')
