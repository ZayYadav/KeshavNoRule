<?php
declare(strict_types=1);

use TeamDark\Panel\{AppRegistry,Auth,Config,Crypto,Database,KeyManager,LoaderAuthService,PanelControl,ReferralManager,Security};

$root = dirname(__DIR__);
foreach (['Config','Database','PanelControl','Security','Crypto','Auth','AppRegistry','KeyManager','ReferralManager','LoaderAuthService'] as $file) {
    require_once $root.'/app/'.$file.'.php';
}
Config::load($root);

function multiCheck(bool $ok, string $label): void
{
    if (!$ok) throw new RuntimeException($label);
    echo "PASS: {$label}\n";
}

$pdo = Database::pdo();
$ownerQ = $pdo->query("SELECT * FROM users WHERE role='owner' ORDER BY id ASC LIMIT 1");
$owner = $ownerQ->fetch();
multiCheck((bool)$owner, 'owner fixture exists');

$official = AppRegistry::official();
multiCheck((int)$official['id'] === AppRegistry::OFFICIAL_ID, 'official app API exists');
multiCheck(str_ends_with(AppRegistry::endpointUrl($official), '/connect'), 'official endpoint remains /connect');

$appA = AppRegistry::createApp($owner, 'Contract App Alpha', 'Multi-app contract A', 'alpha');
$appB = AppRegistry::createApp($owner, 'Contract App Beta', 'Multi-app contract B', 'beta');
multiCheck((int)$appA['id'] !== (int)$appB['id'], 'custom app APIs are isolated records');
multiCheck(str_starts_with((string)$appA['endpoint_token'], 'alpha-'), 'owner-selected endpoint prefix is preserved with random suffix');
multiCheck(str_starts_with((string)$appB['endpoint_token'], 'beta-'), 'second owner-selected endpoint prefix is preserved with random suffix');
multiCheck(AppRegistry::resolveEndpoint((string)$appA['endpoint_token'])['id'] === $appA['id'], 'custom endpoint resolves app A');
multiCheck(AppRegistry::resolveEndpoint((string)$appB['endpoint_token'])['id'] === $appB['id'], 'custom endpoint resolves app B');

$password = Security::passwordHash('MultiApp@Contract123');
$pdo->prepare(
    "INSERT INTO users(name,username,password_hash,role,balance,referral_code,status)
     VALUES('Multi App User','multi-app-contract-user',?,'user',1000,'TDMULTIAPPCONTRACT','active')"
)->execute([$password]);
$userId = (int)$pdo->lastInsertId();
$userQ = $pdo->prepare('SELECT * FROM users WHERE id=?');
$userQ->execute([$userId]);
$user = $userQ->fetch();
multiCheck((bool)$user, 'multi-app user fixture created');

AppRegistry::replaceUserAccess($owner, $userId, [(int)$appA['id'], (int)$appB['id']]);
$userApps = AppRegistry::userApps($userId);
multiCheck(count($userApps) === 2, 'owner can allot multiple app APIs to one user');
$activeUserApps = AppRegistry::activeForUser($user);
multiCheck(count($activeUserApps) === 2, 'assigned user can list only their active App APIs');
foreach ($activeUserApps as $assignedApp) {
    multiCheck(str_contains(AppRegistry::endpointUrl($assignedApp), '/connect/'), 'assigned custom App API exposes its Connect endpoint');
}

$key = KeyManager::create(
    $user,
    'App Alpha key',
    86400,
    false,
    1,
    false,
    'TD-MULTI-APP-ALPHA-KEY',
    (int)$appA['id']
);
multiCheck((int)$key['app_id'] === (int)$appA['id'], 'generated key is bound to selected app');

$okA = LoaderAuthService::authenticate(
    'PUBG',
    $key['key'],
    'MULTI-APP-SERIAL-A',
    '127.0.0.1',
    (int)$appA['id']
);
multiCheck(($okA['status'] ?? false) === true, 'app A key authenticates on app A');

$wrongApp = LoaderAuthService::authenticate(
    'PUBG',
    $key['key'],
    'MULTI-APP-SERIAL-A',
    '127.0.0.1',
    (int)$appB['id']
);
multiCheck(($wrongApp['status'] ?? true) === false && ($wrongApp['reason'] ?? '') === 'Invalid Key', 'app A key is rejected on app B');

AppRegistry::replaceUserAccess($owner, $userId, [(int)$appB['id']]);
$revoked = LoaderAuthService::authenticate(
    'PUBG',
    $key['key'],
    'MULTI-APP-SERIAL-A',
    '127.0.0.1',
    (int)$appA['id']
);
multiCheck(($revoked['status'] ?? true) === false && ($revoked['reason'] ?? '') === 'Application Access Revoked', 'removing user app access suspends that app keys');

AppRegistry::replaceUserAccess($owner, $userId, [(int)$appA['id'], (int)$appB['id']]);
$missingSelectionRejected = false;
try {
    ReferralManager::create($owner, 'user', []);
} catch (RuntimeException $e) {
    $missingSelectionRejected = str_contains($e->getMessage(), 'Select at least one application API');
}
multiCheck($missingSelectionRejected, 'referral API selection is explicit and never silently falls back to Official');

$invite = ReferralManager::create($owner, 'user', [(int)$appA['id'], (int)$appB['id']], 250);
multiCheck((int)$invite['grant_balance'] === 250, 'referral stores a one-time starting balance grant');
$countQ = $pdo->prepare('SELECT COUNT(*) FROM referral_app_access WHERE referral_id=?');
$countQ->execute([(int)$invite['id']]);
multiCheck((int)$countQ->fetchColumn() === 2, 'one referral can carry multiple app APIs');
$validatedInvite = ReferralManager::validateForRegistration((string)$invite['code']);
multiCheck(count($validatedInvite['app_apis'] ?? []) === 2, 'validated referral exposes exactly its allotted App API details');
foreach ($validatedInvite['app_apis'] as $referralApp) {
    multiCheck(str_contains((string)$referralApp['endpoint'], '/connect/'), 'validated referral exposes the allotted Connect endpoint');
}

$pdo->prepare(
    "INSERT INTO users(name,username,password_hash,role,balance,referral_code,referred_by,created_by,status)
     VALUES('Referral Multi User','multi-app-referral-user',?,'user',0,'TDMULTIAPPREFUSER',?,?,'active')"
)->execute([$password, (int)$owner['id'], (int)$owner['id']]);
$referredId = (int)$pdo->lastInsertId();
$granted = AppRegistry::grantReferralToUser($pdo, (int)$invite['id'], $referredId, (int)$owner['id']);
$expectedGranted = array_map('intval', $validatedInvite['app_ids']);
$actualGranted = array_map('intval', $granted);
sort($expectedGranted, SORT_NUMERIC);
sort($actualGranted, SORT_NUMERIC);
multiCheck($actualGranted === $expectedGranted, 'registration grants exactly the App APIs selected on the referral');
$balanceGrant = ReferralManager::grantInviteBalanceToUser($pdo, $invite, $referredId);
multiCheck($balanceGrant === 250, 'referral starting balance is redeemed exactly once by registration flow');
$balanceQ = $pdo->prepare('SELECT balance FROM users WHERE id=?');
$balanceQ->execute([$referredId]);
multiCheck((int)$balanceQ->fetchColumn() === 250, 'referred account receives its referral balance');
$ledgerQ = $pdo->prepare("SELECT COUNT(*) FROM balance_ledger WHERE user_id=? AND actor_user_id=? AND amount=250 AND reason='Referral balance grant'");
$ledgerQ->execute([$referredId, (int)$owner['id']]);
multiCheck((int)$ledgerQ->fetchColumn() === 1, 'referral balance grant is auditable in the balance ledger');

$grantCountQ = $pdo->prepare('SELECT COUNT(*) FROM user_app_access WHERE user_id=?');
$grantCountQ->execute([$referredId]);
multiCheck((int)$grantCountQ->fetchColumn() === 2, 'registered account has both allotted app APIs');
$referredQ = $pdo->prepare('SELECT * FROM users WHERE id=? LIMIT 1');
$referredQ->execute([$referredId]);
$referredUser = $referredQ->fetch();
$visibleToReferredUser = AppRegistry::activeForUser($referredUser ?: []);
$visibleIds = array_map(static fn(array $app): int => (int)$app['id'], $visibleToReferredUser);
sort($visibleIds, SORT_NUMERIC);
multiCheck($visibleIds === $expectedGranted, 'My App APIs exposes only the referral-allotted App APIs to the registered user');
foreach ($visibleToReferredUser as $visibleApp) {
    multiCheck(str_contains(AppRegistry::endpointUrl($visibleApp), '/connect/'), 'registered user can see each allotted Connect endpoint');
}

$oldToken = (string)$appB['endpoint_token'];
$rotated = AppRegistry::rotateEndpoint($owner, (int)$appB['id'], 'rotated');
multiCheck((string)$rotated['endpoint_token'] !== $oldToken, 'owner can rotate custom Connect URL');
multiCheck(str_starts_with((string)$rotated['endpoint_token'], 'rotated-'), 'owner can choose the prefix when rotating a Connect URL');
multiCheck(AppRegistry::resolveEndpoint($oldToken) === null, 'rotated old Connect URL stops resolving');
multiCheck((int)(AppRegistry::resolveEndpoint((string)$rotated['endpoint_token'])['id'] ?? 0) === (int)$appB['id'], 'rotated new Connect URL resolves correctly');

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

echo "Multi-app API contract OK\n";
