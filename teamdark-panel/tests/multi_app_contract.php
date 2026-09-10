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

$appA = AppRegistry::createApp($owner, 'Contract App Alpha', 'Multi-app contract A');
$appB = AppRegistry::createApp($owner, 'Contract App Beta', 'Multi-app contract B');
multiCheck((int)$appA['id'] !== (int)$appB['id'], 'custom app APIs are isolated records');
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
$invite = ReferralManager::create($owner, 'user', [(int)$appA['id'], (int)$appB['id']]);
$countQ = $pdo->prepare('SELECT COUNT(*) FROM referral_app_access WHERE referral_id=?');
$countQ->execute([(int)$invite['id']]);
multiCheck((int)$countQ->fetchColumn() === 2, 'one referral can carry multiple app APIs');

$pdo->prepare(
    "INSERT INTO users(name,username,password_hash,role,balance,referral_code,referred_by,created_by,status)
     VALUES('Referral Multi User','multi-app-referral-user',?,'user',0,'TDMULTIAPPREFUSER',?,?,'active')"
)->execute([$password, (int)$owner['id'], (int)$owner['id']]);
$referredId = (int)$pdo->lastInsertId();
$granted = AppRegistry::grantReferralToUser($pdo, (int)$invite['id'], $referredId, (int)$owner['id']);
multiCheck(count($granted) === 2, 'referral app APIs automatically attach to registered account');

$grantCountQ = $pdo->prepare('SELECT COUNT(*) FROM user_app_access WHERE user_id=?');
$grantCountQ->execute([$referredId]);
multiCheck((int)$grantCountQ->fetchColumn() === 2, 'registered account has both allotted app APIs');

$oldToken = (string)$appB['endpoint_token'];
$rotated = AppRegistry::rotateEndpoint($owner, (int)$appB['id']);
multiCheck((string)$rotated['endpoint_token'] !== $oldToken, 'owner can rotate custom Connect URL');
multiCheck(AppRegistry::resolveEndpoint($oldToken) === null, 'rotated old Connect URL stops resolving');
multiCheck((int)(AppRegistry::resolveEndpoint((string)$rotated['endpoint_token'])['id'] ?? 0) === (int)$appB['id'], 'rotated new Connect URL resolves correctly');

echo "Multi-app API contract OK\n";
