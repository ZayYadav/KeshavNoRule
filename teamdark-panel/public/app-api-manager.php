<?php
declare(strict_types=1);

use TeamDark\Panel\{AppRegistry,Auth,Config,Database,PanelControl,Security,View};

$root = dirname(__DIR__);
foreach (['Config','Database','PanelControl','Security','Auth','View','AppRegistry'] as $file) {
    require_once $root.'/app/'.$file.'.php';
}

Config::load($root);
Security::enforceHttpsWeb();
Security::headers();
Security::startSession();

function appApiRedirect(string $path): never
{
    header('Location: '.$path, true, 303);
    exit;
}

function appApiFlash(string $type, string $message, ?string $copy = null): void
{
    $_SESSION['flash'] = [$type, $message, $copy, 'Copy endpoint'];
}

function appApiTakeFlash(): string
{
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    if (!is_array($f)) return '';
    $copy = isset($f[2]) && is_string($f[2]) ? $f[2] : '';
    return '<div data-flash role="status" class="alert '.(($f[0] ?? '') === 'ok' ? 'ok' : '').'"'
        .($copy !== '' ? ' data-flash-copy="'.View::e($copy).'" data-flash-copy-label="Copy endpoint"' : '').'>'
        .View::e((string)($f[1] ?? '')).'</div>';
}

function appApiSafe(Throwable $e): string
{
    if ($e instanceof PDOException) {
        error_log('TeamDark App API database failure at '.basename($e->getFile()).':'.$e->getLine());
        return 'Application API action failed.';
    }
    $m = trim($e->getMessage());
    return $m !== '' ? substr($m, 0, 300) : 'Application API action failed.';
}

try {
    $user = Auth::requireLogin();
    Auth::requireRole($user, 'owner');

    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $path = rawurldecode((string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/owner/apps'), PHP_URL_PATH) ?: '/owner/apps'));

    if ($method === 'POST') {
        if (!Auth::recentlyAuthenticated(300)) {
            Auth::logout();
            Security::startSession();
            appApiFlash('err', 'Fresh sign-in required for this privileged action.');
            appApiRedirect('/login');
        }
        Security::verifyCsrf($_POST['csrf'] ?? null);
        Security::rateLimit('owner-app-api-'.$user['id'], 180, 3600);

        try {
            if ($path === '/owner/apps/create') {
                $app = AppRegistry::createApp(
                    $user,
                    trim((string)($_POST['name'] ?? '')),
                    trim((string)($_POST['notes'] ?? ''))
                );
                $url = AppRegistry::endpointUrl($app);
                appApiFlash('ok', 'Application API created: '.$app['name'], $url);
                appApiRedirect('/owner/apps');
            }

            if ($path === '/owner/apps/toggle') {
                AppRegistry::setEnabled(
                    $user,
                    (int)($_POST['app_id'] ?? 0),
                    (string)($_POST['enabled'] ?? '') === '1'
                );
                appApiFlash('ok', 'Application API status updated.');
                appApiRedirect('/owner/apps');
            }

            if ($path === '/owner/apps/rotate') {
                $app = AppRegistry::rotateEndpoint($user, (int)($_POST['app_id'] ?? 0));
                appApiFlash('ok', 'Connect URL rotated. Old custom URL stops resolving immediately.', AppRegistry::endpointUrl($app));
                appApiRedirect('/owner/apps');
            }

            if ($path === '/owner/apps/user-access') {
                $targetId = (int)($_POST['user_id'] ?? 0);
                $ids = AppRegistry::replaceUserAccess($user, $targetId, $_POST['app_ids'] ?? []);
                appApiFlash('ok', 'Application access updated: '.count($ids).' API(s) assigned.');
                appApiRedirect('/owner/apps?user_id='.$targetId);
            }

            throw new RuntimeException('Unknown App API action.');
        } catch (Throwable $e) {
            appApiFlash('err', appApiSafe($e));
            $returnId = max(0, (int)($_POST['user_id'] ?? 0));
            appApiRedirect('/owner/apps'.($returnId ? '?user_id='.$returnId : ''));
        }
    }

    if ($method !== 'GET' || $path !== '/owner/apps') {
        http_response_code(404);
        View::page('Not found', '<section class="auth"><div class="card"><h1>404</h1><p class="muted">App API route not found.</p></div></section>', $user);
        exit;
    }

    $apps = AppRegistry::allApps(false);
    $pdo = Database::pdo();
    $users = $pdo->query(
        "SELECT id,name,username,role,status
         FROM users
         WHERE role<>'owner'
         ORDER BY role='admin' DESC,username ASC
         LIMIT 1000"
    )->fetchAll() ?: [];

    $selectedUserId = max(0, (int)($_GET['user_id'] ?? 0));
    $selectedUser = null;
    foreach ($users as $candidate) {
        if ((int)$candidate['id'] === $selectedUserId) {
            $selectedUser = $candidate;
            break;
        }
    }

    $selectedIds = [];
    if ($selectedUser) {
        foreach (AppRegistry::userApps((int)$selectedUser['id']) as $app) {
            $selectedIds[(int)$app['id']] = true;
        }
    }

    $activeCount = 0;
    $customCount = 0;
    $totalAssigned = 0;
    $appCards = '';
    $accessChecks = '';

    foreach ($apps as $app) {
        $isOfficial = (int)$app['is_official'] === 1;
        $isActive = $app['status'] === 'active';
        if ($isActive) $activeCount++;
        if (!$isOfficial) $customCount++;
        $totalAssigned += (int)$app['user_count'];
        $endpoint = AppRegistry::endpointUrl($app);

        $actions = $isOfficial
            ? '<span class="tag">Permanent official route</span>'
            : '<form method="post" action="/owner/apps/toggle" class="inline" data-confirm="'.($isActive ? 'Disable this App API? Its endpoint will stop accepting keys.' : 'Enable this App API?').'">'
                .View::csrf().'<input type="hidden" name="app_id" value="'.(int)$app['id'].'"><input type="hidden" name="enabled" value="'.($isActive ? '0' : '1').'">'
                .'<button class="ghost compact '.($isActive ? 'warning' : '').'">'.($isActive ? 'Disable' : 'Enable').'</button></form>'
                .'<form method="post" action="/owner/apps/rotate" class="inline" data-confirm="Rotate this Connect URL? The old URL will stop working immediately.">'
                .View::csrf().'<input type="hidden" name="app_id" value="'.(int)$app['id'].'"><button class="ghost compact danger">Rotate URL</button></form>';

        $appCards .= '<article class="card system-module-card">'
            .'<div class="toolbar"><div><span class="eyebrow">'.($isOfficial ? 'OFFICIAL APP API' : 'REGISTERED APP API').'</span><h3>'.View::e((string)$app['name']).'</h3></div>'
            .'<span class="status-chip status-'.($isActive ? 'active' : 'disabled').'">'.strtoupper(View::e((string)$app['status'])).'</span></div>'
            .'<p class="muted">'.View::e((string)($app['notes'] ?: 'Dedicated license namespace for one application/client.')).'</p>'
            .'<div class="system-preview"><span>CONNECT URL</span><code>'.View::e($endpoint).'</code><button type="button" class="ghost compact" data-copy="'.View::e($endpoint).'">Copy URL</button></div>'
            .'<div class="system-stats"><div><span>USERS</span><b>'.(int)$app['user_count'].'</b></div><div><span>PENDING REFERRALS</span><b>'.(int)$app['pending_referrals'].'</b></div><div><span>APP ID</span><b>#'.(int)$app['id'].'</b></div></div>'
            .'<div class="inline">'.$actions.'</div></article>';

        if ($isActive) {
            $accessChecks .= '<label class="system-choice-card"><input type="checkbox" name="app_ids[]" value="'.(int)$app['id'].'"'.(isset($selectedIds[(int)$app['id']]) ? ' checked' : '').'>'
                .'<span><b>'.View::e((string)$app['name']).'</b><small>'.View::e($endpoint).'</small></span></label>';
        }
    }

    $userRows = '';
    foreach ($users as $row) {
        $userApps = AppRegistry::userApps((int)$row['id']);
        $names = $userApps ? implode(', ', array_map(static fn(array $a): string => (string)$a['name'], $userApps)) : 'No App API';
        $userRows .= '<tr><td><strong>'.View::e((string)($row['name'] ?: $row['username'])).'</strong><br><span class="muted">@'.View::e((string)$row['username']).'</span></td>'
            .'<td><span class="tag">'.View::e((string)$row['role']).'</span></td><td>'.View::e($names).'</td><td><span class="status-chip status-'.View::e((string)$row['status']).'">'.strtoupper(View::e((string)$row['status'])).'</span></td>'
            .'<td><a class="ghost compact" href="/owner/apps?user_id='.(int)$row['id'].'">Manage APIs</a></td></tr>';
    }
    if ($userRows === '') $userRows = '<tr><td colspan="5">No manageable accounts.</td></tr>';

    $userAccess = $selectedUser
        ? '<section class="card system-form"><div class="toolbar"><div><span class="eyebrow">DIRECT ALLOTMENT</span><h3>'.View::e((string)($selectedUser['name'] ?: $selectedUser['username'])).' • @'.View::e((string)$selectedUser['username']).'</h3></div><a class="ghost compact" href="/owner/apps">Close</a></div>'
            .'<p class="muted">Assign one or many application APIs. Removing access prevents new keys for that app and existing keys owned by this account stop authenticating on that app until access is restored.</p>'
            .'<form method="post" action="/owner/apps/user-access" class="stack" data-confirm="Replace this user’s App API access with the selected set?">'.View::csrf().'<input type="hidden" name="user_id" value="'.(int)$selectedUser['id'].'">'
            .'<div class="system-choice-grid">'.$accessChecks.'</div><button class="primary wide">Save App Access</button></form></section>'
        : '<section class="card system-form"><span class="eyebrow">DIRECT ALLOTMENT</span><h3>Select a user</h3><p class="muted">Use “Manage APIs” below to give any account one or multiple registered application APIs.</p></section>';

    $body = '<section class="hero keys-hero"><div><span class="eyebrow">MULTI-APP CONTROL PLANE</span><h1>App APIs</h1>'
        .'<p class="muted">One backend, separate Connect URLs and isolated key namespaces. A key generated for one App API is rejected by every other App API endpoint.</p></div><span class="tag">OWNER ONLY</span></section>'
        .appApiTakeFlash()
        .'<div class="system-stats"><div><span>TOTAL APIs</span><b>'.count($apps).'</b></div><div><span>ACTIVE</span><b>'.$activeCount.'</b></div><div><span>CUSTOM</span><b>'.$customCount.'</b></div><div><span>ASSIGNMENTS</span><b>'.$totalAssigned.'</b></div></div>'
        .'<section class="card system-form"><span class="eyebrow">REGISTER NEW APP</span><h3>Create another Connect namespace</h3>'
        .'<p class="muted">The Official app always keeps /connect. Every new app receives a random stable /connect/&lt;channel&gt; URL.</p>'
        .'<form method="post" action="/owner/apps/create" class="stack">'.View::csrf()
        .'<div class="form-row"><div class="field"><label>App / API name</label><input name="name" maxlength="80" required placeholder="Example: TeamDark Lite"></div><div class="field"><label>Notes</label><input name="notes" maxlength="240" placeholder="Optional client/package note"></div></div>'
        .'<button class="primary">Create App API</button></form></section>'
        .'<section class="system-module-grid">'.$appCards.'</section>'
        .$userAccess
        .'<section class="card history-card"><div class="toolbar"><div><span class="eyebrow">ACCOUNT ACCESS</span><h3>Users and allotted APIs</h3></div><span class="tag">'.count($users).' accounts</span></div>'
        .'<div class="table-wrap"><table><thead><tr><th>User</th><th>Role</th><th>App APIs</th><th>Status</th><th>Control</th></tr></thead><tbody>'.$userRows.'</tbody></table></div></section>'
        .'<section class="card"><span class="eyebrow">HOW ISOLATION WORKS</span><h3>App-bound keys</h3><p class="muted">Referral → account App access → key generation App selection → key.app_id binding → Connect URL resolves the expected app_id. The existing POST fields remain game, user_key and serial, so the Official loader contract stays backward-compatible.</p></section>';

    Security::audit((int)$user['id'], 'page_viewed', ['path'=>'/owner/apps']);
    View::page('App APIs', $body, $user);
} catch (Throwable $e) {
    error_log('TeamDark App API manager error: '.get_class($e).' at '.basename($e->getFile()).':'.$e->getLine());
    try { $u = Auth::user(); } catch (Throwable) { $u = null; }
    http_response_code(400);
    View::page('App APIs unavailable', '<section class="auth"><div class="card"><h1>App APIs unavailable</h1><div class="alert">'.View::e(appApiSafe($e)).'</div><a class="btn" href="/dashboard">Go back</a></div></section>', $u);
}
