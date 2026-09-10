from pathlib import Path


def replace_once(path: str, old: str, new: str, label: str) -> None:
    p = Path(path)
    text = p.read_text(encoding='utf-8')
    count = text.count(old)
    if count != 1:
        raise SystemExit(f'{label}: expected 1 marker, found {count}')
    p.write_text(text.replace(old, new, 1), encoding='utf-8')


# -----------------------------------------------------------------------------
# Database compatibility: add referral balance column without requiring a fresh
# install. Existing rows stay at zero and reruns are idempotent.
# -----------------------------------------------------------------------------
replace_once(
    'teamdark-panel/app/Database.php',
    """            if (!self::columnExists($pdo, 'referral_invites', 'expires_at')) {
                $pdo->exec(
                    'ALTER TABLE referral_invites ADD COLUMN expires_at DATETIME NULL AFTER status'
                );
                $pdo->exec(
                    \"UPDATE referral_invites
                     SET expires_at=DATE_ADD(created_at,INTERVAL 7 DAY)
                     WHERE expires_at IS NULL AND status='pending'\"
                );
            }

            $pdo->exec(
""",
    """            if (!self::columnExists($pdo, 'referral_invites', 'expires_at')) {
                $pdo->exec(
                    'ALTER TABLE referral_invites ADD COLUMN expires_at DATETIME NULL AFTER status'
                );
                $pdo->exec(
                    \"UPDATE referral_invites
                     SET expires_at=DATE_ADD(created_at,INTERVAL 7 DAY)
                     WHERE expires_at IS NULL AND status='pending'\"
                );
            }

            if (!self::columnExists($pdo, 'referral_invites', 'grant_balance')) {
                $pdo->exec(
                    'ALTER TABLE referral_invites ADD COLUMN grant_balance BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER role'
                );
            }

            $pdo->exec(
""",
    'Database referral grant compatibility',
)


# -----------------------------------------------------------------------------
# Canonical schema: referral balance is a first-class invite property.
# -----------------------------------------------------------------------------
replace_once(
    'teamdark-panel/database/schema.sql',
    """  created_by BIGINT UNSIGNED NOT NULL,
  role ENUM('admin','reseller','user') NOT NULL DEFAULT 'user',
  status ENUM('pending','used','revoked') NOT NULL DEFAULT 'pending',
""",
    """  created_by BIGINT UNSIGNED NOT NULL,
  role ENUM('admin','reseller','user') NOT NULL DEFAULT 'user',
  grant_balance BIGINT UNSIGNED NOT NULL DEFAULT 0,
  status ENUM('pending','used','revoked') NOT NULL DEFAULT 'pending',
""",
    'schema referral grant column',
)
replace_once(
    'teamdark-panel/database/schema.sql',
    """DEALLOCATE PREPARE td_stmt;

UPDATE referral_invites
SET expires_at=DATE_ADD(created_at,INTERVAL 7 DAY)
WHERE expires_at IS NULL AND status='pending';
""",
    """DEALLOCATE PREPARE td_stmt;

SET @td_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='referral_invites' AND COLUMN_NAME='grant_balance'
);
SET @td_sql := IF(@td_exists=0, 'ALTER TABLE referral_invites ADD COLUMN grant_balance BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER role', 'SELECT 1');
PREPARE td_stmt FROM @td_sql;
EXECUTE td_stmt;
DEALLOCATE PREPARE td_stmt;

UPDATE referral_invites
SET expires_at=DATE_ADD(created_at,INTERVAL 7 DAY)
WHERE expires_at IS NULL AND status='pending';
""",
    'schema referral grant migration',
)


# -----------------------------------------------------------------------------
# ReferralManager: balance grant validation, admin quota reservation, atomic
# redemption ledger, and visibility in invite listings.
# -----------------------------------------------------------------------------
replace_once(
    'teamdark-panel/app/ReferralManager.php',
    """final class ReferralManager
{
    public static function allowedRoles(array $actor): array
""",
    """final class ReferralManager
{
    public const MAX_REFERRAL_BALANCE = 1000000000;

    public static function allowedRoles(array $actor): array
""",
    'ReferralManager max grant constant',
)
replace_once(
    'teamdark-panel/app/ReferralManager.php',
    """    public static function create(array $actor, string $role, mixed $appIds = []): array
    {
        $allowed = self::allowedRoles($actor);
        if (!in_array($role, $allowed, true)) {
            throw new RuntimeException('You cannot create a referral for this role.');
        }

        $selectedApps = AppRegistry::validateReferralApps($actor, $appIds);
        $pdo = Database::pdo();
""",
    """    public static function create(array $actor, string $role, mixed $appIds = [], mixed $grantBalance = 0): array
    {
        $allowed = self::allowedRoles($actor);
        if (!in_array($role, $allowed, true)) {
            throw new RuntimeException('You cannot create a referral for this role.');
        }

        $selectedApps = AppRegistry::validateReferralApps($actor, $appIds);
        $grantBalance = self::normalizeGrantBalance($grantBalance);
        $pdo = Database::pdo();
""",
    'ReferralManager create signature',
)
replace_once(
    'teamdark-panel/app/ReferralManager.php',
    """            $pdo->beginTransaction();
            try {
                $pdo->prepare(
                    \"INSERT INTO referral_invites(code,created_by,role,status,expires_at)
                     VALUES(?,?,?,'pending',DATE_ADD(NOW(),INTERVAL 7 DAY))\"
                )->execute([$code, $actor['id'], $role]);

                $id = (int)$pdo->lastInsertId();
""",
    """            $pdo->beginTransaction();
            try {
                // Serialize referral balance reservations per creator so parallel
                // requests cannot bypass the Admin balance policy.
                $actorLock = $pdo->prepare(
                    'SELECT id,role,status FROM users WHERE id=? LIMIT 1 FOR UPDATE'
                );
                $actorLock->execute([(int)$actor['id']]);
                $freshActor = $actorLock->fetch();
                if (!$freshActor || $freshActor['status'] !== 'active') {
                    throw new RuntimeException('Referral creator account is unavailable.');
                }
                if (!in_array($role, self::allowedRoles($freshActor), true)) {
                    throw new RuntimeException('You cannot create a referral for this role.');
                }
                self::assertGrantBalanceAllowed($pdo, $freshActor, $grantBalance);

                $pdo->prepare(
                    \"INSERT INTO referral_invites(code,created_by,role,grant_balance,status,expires_at)
                     VALUES(?,?,?,?,'pending',DATE_ADD(NOW(),INTERVAL 7 DAY))\"
                )->execute([$code, $actor['id'], $role, $grantBalance]);

                $id = (int)$pdo->lastInsertId();
""",
    'ReferralManager transactional grant reservation',
)
replace_once(
    'teamdark-panel/app/ReferralManager.php',
    """                        'invite_id'=>$id,
                        'role'=>$role,
                        'app_ids'=>$selectedApps,
""",
    """                        'invite_id'=>$id,
                        'role'=>$role,
                        'app_ids'=>$selectedApps,
                        'grant_balance'=>$grantBalance,
""",
    'ReferralManager audit grant',
)
replace_once(
    'teamdark-panel/app/ReferralManager.php',
    """                    'role'=>$role,
                    'app_ids'=>$selectedApps,
                ];
""",
    """                    'role'=>$role,
                    'app_ids'=>$selectedApps,
                    'grant_balance'=>$grantBalance,
                ];
""",
    'ReferralManager return grant',
)
replace_once(
    'teamdark-panel/app/ReferralManager.php',
    """    public static function grantInviteAppsToUser(\\PDO $pdo, int $inviteId, int $userId, int $grantedBy): array
    {
        return AppRegistry::grantReferralToUser($pdo, $inviteId, $userId, $grantedBy);
    }
""",
    """    private static function normalizeGrantBalance(mixed $raw): int
    {
        if ($raw === null || $raw === '') return 0;
        if (is_int($raw)) {
            $value = $raw;
        } elseif (is_string($raw) && preg_match('/^\\d{1,10}$/', trim($raw))) {
            $value = (int)trim($raw);
        } else {
            throw new RuntimeException('Referral balance must be a whole number from 0 to 1,000,000,000.');
        }

        if ($value < 0 || $value > self::MAX_REFERRAL_BALANCE) {
            throw new RuntimeException('Referral balance must be between 0 and 1,000,000,000 credits.');
        }
        return $value;
    }

    private static function assertGrantBalanceAllowed(\\PDO $pdo, array $actor, int $grantBalance): void
    {
        if ($grantBalance <= 0 || ($actor['role'] ?? '') === 'owner') return;
        if (($actor['role'] ?? '') !== 'admin') {
            throw new RuntimeException('This account cannot attach balance to referrals.');
        }
        if (!(bool)Config::get('admin_balance_adjustments_enabled', false)) {
            throw new RuntimeException('Referral balance grants are disabled for Admin accounts by Owner policy.');
        }

        $limit = (int)Config::get('admin_balance_daily_limit', 10000);
        if ($limit <= 0) {
            throw new RuntimeException('Admin balance grants are currently disabled by Owner policy.');
        }

        $spentQ = $pdo->prepare(
            \"SELECT COALESCE(SUM(amount),0)
             FROM balance_ledger
             WHERE actor_user_id=?
               AND amount>0
               AND reason IN ('Manual balance adjustment','Referral balance grant')
               AND created_at>=CURDATE()\"
        );
        $spentQ->execute([(int)$actor['id']]);
        $spentToday = (int)$spentQ->fetchColumn();

        $pendingQ = $pdo->prepare(
            \"SELECT COALESCE(SUM(grant_balance),0)
             FROM referral_invites
             WHERE created_by=? AND status='pending'\"
        );
        $pendingQ->execute([(int)$actor['id']]);
        $reserved = (int)$pendingQ->fetchColumn();

        if (($spentToday + $reserved + $grantBalance) > $limit) {
            throw new RuntimeException('Admin referral balance limit reached. Pending referral grants also count toward the limit.');
        }
    }

    public static function grantInviteAppsToUser(\\PDO $pdo, int $inviteId, int $userId, int $grantedBy): array
    {
        return AppRegistry::grantReferralToUser($pdo, $inviteId, $userId, $grantedBy);
    }

    public static function grantInviteBalanceToUser(\\PDO $pdo, array $invite, int $userId): int
    {
        $grant = (int)($invite['grant_balance'] ?? 0);
        if ($grant <= 0) return 0;
        if ($grant > self::MAX_REFERRAL_BALANCE) {
            throw new RuntimeException('Referral balance grant is invalid.');
        }

        $q = $pdo->prepare(\"UPDATE users SET balance=balance+? WHERE id=? AND role<>'owner'\");
        $q->execute([$grant, $userId]);
        if ($q->rowCount() !== 1) {
            throw new RuntimeException('Could not apply referral balance to the new account.');
        }

        $pdo->prepare(
            'INSERT INTO balance_ledger(user_id,actor_user_id,amount,reason) VALUES(?,?,?,?)'
        )->execute([
            $userId,
            (int)$invite['created_by'],
            $grant,
            'Referral balance grant',
        ]);
        return $grant;
    }
""",
    'ReferralManager grant helpers',
)
replace_once(
    'teamdark-panel/app/ReferralManager.php',
    """        $sql = \"SELECT i.id,i.code,i.role,i.status,i.created_at,i.used_at,
""",
    """        $sql = \"SELECT i.id,i.code,i.role,i.grant_balance,i.status,i.created_at,i.used_at,
""",
    'ReferralManager visible balance',
)


# -----------------------------------------------------------------------------
# Production registration controller: redeem API access and referral balance in
# the same database transaction.
# -----------------------------------------------------------------------------
replace_once(
    'teamdark-panel/public/register-relaxed.php',
    """        $body = '<section class=\"auth\"><div class=\"card\"><div class=\"eyebrow\">SECURE REGISTRATION</div><h1>Create account</h1>'
            .$flash
            .'<p class=\"muted\">Invite verified for a '.View::e((string)$invite['role']).' account.</p>'
""",
    """        $referralGrantPreview = (int)($invite['grant_balance'] ?? 0);
        $body = '<section class=\"auth\"><div class=\"card\"><div class=\"eyebrow\">SECURE REGISTRATION</div><h1>Create account</h1>'
            .$flash
            .'<p class=\"muted\">Invite verified for a '.View::e((string)$invite['role']).' account.'
            .($referralGrantPreview > 0 ? ' Includes '.number_format($referralGrantPreview).' starting credits.' : '').'</p>'
""",
    'registration grant preview',
)
replace_once(
    'teamdark-panel/public/register-relaxed.php',
    """        $uid = (int)$pdo->lastInsertId();
        $grantedAppIds = ReferralManager::grantInviteAppsToUser(
            $pdo,
            (int)$invite['id'],
            $uid,
            (int)$invite['created_by']
        );
        $pdo->prepare(\"UPDATE referral_invites SET status='used',used_by=?,used_at=NOW() WHERE id=? AND status='pending'\")
""",
    """        $uid = (int)$pdo->lastInsertId();
        $grantedAppIds = ReferralManager::grantInviteAppsToUser(
            $pdo,
            (int)$invite['id'],
            $uid,
            (int)$invite['created_by']
        );
        $referralGrant = ReferralManager::grantInviteBalanceToUser($pdo, $invite, $uid);
        $pdo->prepare(\"UPDATE referral_invites SET status='used',used_by=?,used_at=NOW() WHERE id=? AND status='pending'\")
""",
    'registration apply grant',
)
replace_once(
    'teamdark-panel/public/register-relaxed.php',
    """            'invite_id'=>(int)$invite['id'],
            'app_ids'=>$grantedAppIds,
        ]);
""",
    """            'invite_id'=>(int)$invite['id'],
            'app_ids'=>$grantedAppIds,
            'referral_balance'=>$referralGrant,
        ]);
""",
    'registration audit grant',
)
replace_once(
    'teamdark-panel/public/register-relaxed.php',
    """        'referral'=>$ref,
        'signup_bonus'=>$signup,
        'created_at'=>date('Y-m-d H:i:s'),
""",
    """        'referral'=>$ref,
        'signup_bonus'=>$signup,
        'referral_balance'=>$referralGrant,
        'starting_balance'=>$signup + $referralGrant,
        'created_at'=>date('Y-m-d H:i:s'),
""",
    'registration success grant state',
)


# -----------------------------------------------------------------------------
# Main panel UI + legacy registration path.
# -----------------------------------------------------------------------------
replace_once(
    'teamdark-panel/app/View.php',
    """            .self::navLink('/files', 'File Manager', 'spark', $path)
            .'</div>';
""",
    """            .self::navLink('/files', 'File Manager', 'spark', $path)
            .self::navLink('/my-apps', 'My App APIs', 'spark', $path)
            .'</div>';
""",
    'View my app APIs nav',
)

replace_once(
    'teamdark-panel/public/index.php',
    """            .'<div><span>Signup balance</span><strong>'.View::e((string)$state['signup_bonus']).' credits</strong></div>'
            .'<div><span>Created</span><strong>'.View::e((string)$state['created_at']).'</strong></div>'
""",
    """            .'<div><span>Signup bonus</span><strong>'.View::e(number_format((int)($state['signup_bonus'] ?? 0))).' credits</strong></div>'
            .'<div><span>Referral balance</span><strong>'.View::e(number_format((int)($state['referral_balance'] ?? 0))).' credits</strong></div>'
            .'<div><span>Starting balance</span><strong>'.View::e(number_format((int)($state['starting_balance'] ?? (($state['signup_bonus'] ?? 0) + ($state['referral_balance'] ?? 0))))).' credits</strong></div>'
            .'<div><span>Created</span><strong>'.View::e((string)$state['created_at']).'</strong></div>'
""",
    'registration success UI grant',
)
replace_once(
    'teamdark-panel/public/index.php',
    """            $uid = (int)$pdo->lastInsertId();
            $grantedAppIds = ReferralManager::grantInviteAppsToUser(
                $pdo,
                (int)$invite['id'],
                $uid,
                (int)$invite['created_by']
            );

            $pdo->prepare(
""",
    """            $uid = (int)$pdo->lastInsertId();
            $grantedAppIds = ReferralManager::grantInviteAppsToUser(
                $pdo,
                (int)$invite['id'],
                $uid,
                (int)$invite['created_by']
            );
            $referralGrant = ReferralManager::grantInviteBalanceToUser($pdo, $invite, $uid);

            $pdo->prepare(
""",
    'legacy registration apply grant',
)
replace_once(
    'teamdark-panel/public/index.php',
    """                'invite_id'=>(int)$invite['id'],
            'app_ids'=>$grantedAppIds,
            ]);
""",
    """                'invite_id'=>(int)$invite['id'],
                'app_ids'=>$grantedAppIds,
                'referral_balance'=>$referralGrant,
            ]);
""",
    'legacy registration audit grant',
)
replace_once(
    'teamdark-panel/public/index.php',
    """            'referral'=>$ref,
            'signup_bonus'=>$signup,
            'created_at'=>date('Y-m-d H:i:s'),
""",
    """            'referral'=>$ref,
            'signup_bonus'=>$signup,
            'referral_balance'=>$referralGrant,
            'starting_balance'=>$signup + $referralGrant,
            'created_at'=>date('Y-m-d H:i:s'),
""",
    'legacy registration success grant state',
)

# Dedicated user-visible endpoint access page.
replace_once(
    'teamdark-panel/public/index.php',
    """    if ($path === '/dashboard' && $method === 'GET') {
""",
    """    if ($path === '/my-apps' && $method === 'GET') {
        $apps = AppRegistry::activeForUser($user);
        $cards = '';
        foreach ($apps as $app) {
            $endpoint = AppRegistry::endpointUrl($app);
            $isOfficial = (int)($app['is_official'] ?? 0) === 1;
            $notes = trim((string)($app['notes'] ?? ''));
            $cards .= '<div class=\"card half spotlight\">'
                .'<div class=\"toolbar\"><div><div class=\"eyebrow\">'.($isOfficial ? 'OFFICIAL API' : 'ASSIGNED APP API').'</div><h3>'.View::e((string)$app['name']).'</h3></div>'
                .'<span class=\"status-chip status-active\">ACTIVE</span></div>'
                .($notes !== '' ? '<p class=\"muted\">'.View::e($notes).'</p>' : '<p class=\"muted\">This Connect endpoint is assigned to your account.</p>')
                .'<div class=\"verify-box\"><div class=\"eyebrow\">CONNECT ENDPOINT</div><div class=\"key\">'.View::e($endpoint).'</div></div>'
                .'<button type=\"button\" class=\"primary wide\" data-copy=\"'.View::e($endpoint).'\">Copy endpoint</button>'
                .'</div>';
        }
        if ($cards === '') {
            $cards = '<div class=\"card\"><div class=\"empty-state\"><div class=\"empty-orb\">API</div><h3>No App API assigned</h3><p class=\"muted\">Ask the Owner to allot an application API to your account.</p></div></div>';
        }
        $body = '<section class=\"hero keys-hero\"><div><div class=\"eyebrow\">YOUR APPLICATION ACCESS</div><h1>My App APIs</h1><p class=\"muted\">Only keys generated for an assigned App API work on that app\'s Connect endpoint.</p></div></section>'
            .takeFlash()
            .'<div class=\"grid\">'.$cards.'</div>';
        View::page('My App APIs', $body, $user);
        exit;
    }

    if ($path === '/dashboard' && $method === 'GET') {
""",
    'my app APIs route',
)

replace_once(
    'teamdark-panel/public/index.php',
    """    if ($method === 'GET' && (in_array($path, ['/dashboard','/keys','/keys/expired','/keys/extend','/keys/devices','/users','/telegram-users','/activity','/owner/users'], true) || str_starts_with($path, '/owner/'))) {
""",
    """    if ($method === 'GET' && (in_array($path, ['/dashboard','/my-apps','/keys','/keys/expired','/keys/extend','/keys/devices','/users','/telegram-users','/activity','/owner/users'], true) || str_starts_with($path, '/owner/'))) {
""",
    'my app APIs page audit',
)

# Referral creation UI and table.
replace_once(
    'teamdark-panel/public/index.php',
    """        if ($referralAppChecks === '') {
            $referralAppChecks = '<div class=\"alert\">No active App API is available to allot. Ask Owner to assign one first.</div>';
        }

        $activeCount = 0;
""",
    """        if ($referralAppChecks === '') {
            $referralAppChecks = '<div class=\"alert\">No active App API is available to allot. Ask Owner to assign one first.</div>';
        }
        $referralBalanceEnabled = $user['role'] === 'owner'
            || (bool)Config::get('admin_balance_adjustments_enabled', false);
        $referralBalanceMax = $user['role'] === 'owner'
            ? ReferralManager::MAX_REFERRAL_BALANCE
            : min(ReferralManager::MAX_REFERRAL_BALANCE, (int)Config::get('admin_balance_daily_limit', 10000));
        $referralBalanceField = $referralBalanceEnabled
            ? '<div class=\"field\"><label>Starting balance <span class=\"optional\">optional</span></label><input name=\"grant_balance\" type=\"number\" min=\"0\" max=\"'.$referralBalanceMax.'\" value=\"0\" inputmode=\"numeric\"><p class=\"hint\">Credits are granted once, when this invite is successfully registered. Pending Admin grants reserve quota until used or revoked.</p></div>'
            : '<div class=\"field field-disabled\"><label>Starting balance</label><input type=\"number\" value=\"0\" disabled><p class=\"hint\">Owner has disabled Admin balance grants.</p></div>';

        $activeCount = 0;
""",
    'referral balance UI config',
)
replace_once(
    'teamdark-panel/public/index.php',
    """                .'<td>'.View::e((string)($invite['app_names'] ?? 'Official')).'</td>'
                .'<td>'.View::e($invite['creator_username']).'</td>'
""",
    """                .'<td>'.View::e((string)($invite['app_names'] ?? 'Official')).'</td>'
                .'<td>'.View::e(number_format((int)($invite['grant_balance'] ?? 0))).' credits</td>'
                .'<td>'.View::e($invite['creator_username']).'</td>'
""",
    'referral table balance cell',
)
replace_once(
    'teamdark-panel/public/index.php',
    """            $inviteRows = '<tr><td colspan=\"8\"><div class=\"empty-state\"><div class=\"empty-orb\">＋</div><h3>No invites yet</h3><p class=\"muted\">Create a secure one-time registration invite.</p></div></td></tr>';
""",
    """            $inviteRows = '<tr><td colspan=\"9\"><div class=\"empty-state\"><div class=\"empty-orb\">＋</div><h3>No invites yet</h3><p class=\"muted\">Create a secure one-time registration invite.</p></div></td></tr>';
""",
    'referral empty table colspan',
)
replace_once(
    'teamdark-panel/public/index.php',
    """            .'<div class=\"field\"><label>Application APIs</label><div class=\"system-choice-grid\">'.$referralAppChecks.'</div><p class=\"hint\">Select one or many. The registered account automatically receives exactly these App APIs.</p></div>'
            .'<button class=\"primary wide\">Create referral</button>'
""",
    """            .'<div class=\"field\"><label>Application APIs</label><div class=\"system-choice-grid\">'.$referralAppChecks.'</div><p class=\"hint\">Select one or many. The registered account automatically receives exactly these App APIs.</p></div>'
            .$referralBalanceField
            .'<button class=\"primary wide\">Create referral</button>'
""",
    'referral form balance field',
)
replace_once(
    'teamdark-panel/public/index.php',
    """            .'<thead><tr><th>Referral</th><th>Role</th><th>App APIs</th><th>Created by</th><th>Status</th><th>Registered user</th><th>Created</th><th>Action</th></tr></thead>'
""",
    """            .'<thead><tr><th>Referral</th><th>Role</th><th>App APIs</th><th>Balance grant</th><th>Created by</th><th>Status</th><th>Registered user</th><th>Created</th><th>Action</th></tr></thead>'
""",
    'referral table balance header',
)
replace_once(
    'teamdark-panel/public/index.php',
    """            $invite = ReferralManager::create($user, input('role', 'user'), $_POST['app_ids'] ?? []);
            flash(
                'ok',
                'Referral created: '.$invite['code'].' • App APIs: '.count($invite['app_ids']),
""",
    """            $invite = ReferralManager::create(
                $user,
                input('role', 'user'),
                $_POST['app_ids'] ?? [],
                input('grant_balance', '0')
            );
            flash(
                'ok',
                'Referral created: '.$invite['code'].' • App APIs: '.count($invite['app_ids']).' • Balance: '.number_format((int)$invite['grant_balance']).' credits',
""",
    'referral create route grant',
)

# Admin manual balance grants share quota with redeemed + pending referral grants.
replace_once(
    'teamdark-panel/public/index.php',
    """        try {
            $q = $pdo->prepare(
                'SELECT balance FROM users WHERE id=? FOR UPDATE'
            );
""",
    """        try {
            if ($user['role'] === 'admin' && $amount > 0) {
                $actorLock = $pdo->prepare('SELECT id FROM users WHERE id=? LIMIT 1 FOR UPDATE');
                $actorLock->execute([(int)$user['id']]);
            }
            $q = $pdo->prepare(
                'SELECT balance FROM users WHERE id=? FOR UPDATE'
            );
""",
    'manual balance actor quota lock',
)
replace_once(
    'teamdark-panel/public/index.php',
    """                       AND amount>0
                       AND reason='Manual balance adjustment'
                       AND created_at>=CURDATE()\"
                );
                $spentQ->execute([(int)$user['id']]);
                $usedToday = (int)$spentQ->fetchColumn();

                if ($limit <= 0 || ($usedToday + $amount) > $limit) {
""",
    """                       AND amount>0
                       AND reason IN ('Manual balance adjustment','Referral balance grant')
                       AND created_at>=CURDATE()\"
                );
                $spentQ->execute([(int)$user['id']]);
                $usedToday = (int)$spentQ->fetchColumn();
                $pendingQ = $pdo->prepare(
                    \"SELECT COALESCE(SUM(grant_balance),0)
                     FROM referral_invites
                     WHERE created_by=? AND status='pending'\"
                );
                $pendingQ->execute([(int)$user['id']]);
                $reserved = (int)$pendingQ->fetchColumn();

                if ($limit <= 0 || ($usedToday + $reserved + $amount) > $limit) {
""",
    'manual balance quota includes referral reservations',
)


# -----------------------------------------------------------------------------
# Multi-app regression contract: referral grants + endpoint visibility data.
# -----------------------------------------------------------------------------
replace_once(
    'teamdark-panel/tests/multi_app_contract.php',
    """$invite = ReferralManager::create($owner, 'user', [(int)$appA['id'], (int)$appB['id']]);
$countQ = $pdo->prepare('SELECT COUNT(*) FROM referral_app_access WHERE referral_id=?');
""",
    """$invite = ReferralManager::create($owner, 'user', [(int)$appA['id'], (int)$appB['id']], 250);
multiCheck((int)$invite['grant_balance'] === 250, 'referral stores a one-time starting balance grant');
$countQ = $pdo->prepare('SELECT COUNT(*) FROM referral_app_access WHERE referral_id=?');
""",
    'multi app referral grant fixture',
)
replace_once(
    'teamdark-panel/tests/multi_app_contract.php',
    """$granted = AppRegistry::grantReferralToUser($pdo, (int)$invite['id'], $referredId, (int)$owner['id']);
multiCheck(count($granted) === 2, 'referral app APIs automatically attach to registered account');

$grantCountQ = $pdo->prepare('SELECT COUNT(*) FROM user_app_access WHERE user_id=?');
""",
    """$granted = AppRegistry::grantReferralToUser($pdo, (int)$invite['id'], $referredId, (int)$owner['id']);
multiCheck(count($granted) === 2, 'referral app APIs automatically attach to registered account');
$balanceGrant = ReferralManager::grantInviteBalanceToUser($pdo, $invite, $referredId);
multiCheck($balanceGrant === 250, 'referral starting balance is redeemed exactly once by registration flow');
$balanceQ = $pdo->prepare('SELECT balance FROM users WHERE id=?');
$balanceQ->execute([$referredId]);
multiCheck((int)$balanceQ->fetchColumn() === 250, 'referred account receives its referral balance');
$ledgerQ = $pdo->prepare(\"SELECT COUNT(*) FROM balance_ledger WHERE user_id=? AND actor_user_id=? AND amount=250 AND reason='Referral balance grant'\");
$ledgerQ->execute([$referredId, (int)$owner['id']]);
multiCheck((int)$ledgerQ->fetchColumn() === 1, 'referral balance grant is auditable in the balance ledger');

$grantCountQ = $pdo->prepare('SELECT COUNT(*) FROM user_app_access WHERE user_id=?');
""",
    'multi app referral grant redemption checks',
)
replace_once(
    'teamdark-panel/tests/multi_app_contract.php',
    """$userApps = AppRegistry::userApps($userId);
multiCheck(count($userApps) === 2, 'owner can allot multiple app APIs to one user');
""",
    """$userApps = AppRegistry::userApps($userId);
multiCheck(count($userApps) === 2, 'owner can allot multiple app APIs to one user');
$activeUserApps = AppRegistry::activeForUser($user);
multiCheck(count($activeUserApps) === 2, 'assigned user can list only their active App APIs');
foreach ($activeUserApps as $assignedApp) {
    multiCheck(str_contains(AppRegistry::endpointUrl($assignedApp), '/connect/'), 'assigned custom App API exposes its Connect endpoint');
}
""",
    'multi app user endpoint checks',
)

print('TeamDark endpoint access + referral balance patch applied\n')
