<?php
declare(strict_types=1);

use TeamDark\Panel\{Auth,Config,Database,PanelControl,Security,UploadManager,View};

$root = dirname(__DIR__);
foreach (['Config','Database','Security','PanelControl','Auth','View','UploadManager'] as $file) {
    require $root.'/app/'.$file.'.php';
}

Config::load($root);
Security::enforceHttpsWeb();
Security::headers();
Security::startSession();

function vaultRedirect(string $path): never
{
    header('Location: '.$path, true, 303);
    exit;
}

function vaultFlash(string $type, string $message): void
{
    $_SESSION['flash'] = [$type, $message];
}

function vaultTakeFlash(): string
{
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    if (!is_array($f)) return '';
    return '<div data-flash role="status" class="alert '.(($f[0] ?? '') === 'ok' ? 'ok' : '').'">'.View::e((string)($f[1] ?? '')).'</div>';
}

function vaultBytes(int $bytes): string
{
    if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2).' GB';
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 2).' MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 1).' KB';
    return $bytes.' B';
}

function vaultSafeMessage(Throwable $e): string
{
    if ($e instanceof PDOException) {
        error_log('TeamDark vault database failure at '.basename($e->getFile()).':'.$e->getLine());
        return 'File action failed. Please try again.';
    }

    $message = trim($e->getMessage());
    return $message !== '' ? substr($message, 0, 300) : 'File action failed.';
}

function vaultBaseUrl(): string
{
    $base = rtrim((string)Config::get('app_url', ''), '/');
    if ($base !== '') return $base;

    $host = preg_replace(
        '/[^A-Za-z0-9.\-:\[\]]/',
        '',
        (string)($_SERVER['HTTP_HOST'] ?? '')
    ) ?: '';

    if ($host === '') return '';
    return (Security::isHttpsRequest() ? 'https://' : 'http://').$host;
}

function vaultPagedRows(?int $userId, int $requestedPage, int $pageSize = 100): array
{
    $pageSize = min(200, max(20, $pageSize));
    $pdo = Database::pdo();

    if ($userId === null) {
        $total = (int)$pdo->query('SELECT COUNT(*) FROM user_uploads')->fetchColumn();
    } else {
        $countQ = $pdo->prepare('SELECT COUNT(*) FROM user_uploads WHERE user_id=?');
        $countQ->execute([$userId]);
        $total = (int)$countQ->fetchColumn();
    }

    $pages = max(1, (int)ceil($total / $pageSize));
    $page = min(max(1, $requestedPage), $pages);
    $offset = ($page - 1) * $pageSize;

    $sql = 'SELECT f.*,u.username,u.name,u.role
            FROM user_uploads f
            JOIN users u ON u.id=f.user_id';
    $params = [];

    if ($userId !== null) {
        $sql .= ' WHERE f.user_id=?';
        $params[] = $userId;
    }

    $sql .= ' ORDER BY f.updated_at DESC,f.id DESC LIMIT '.$pageSize.' OFFSET '.$offset;
    $q = $pdo->prepare($sql);
    $q->execute($params);

    return [
        'rows'=>$q->fetchAll() ?: [],
        'total'=>$total,
        'page'=>$page,
        'pages'=>$pages,
    ];
}

function vaultPager(array $page, string $param, string $anchor): string
{
    if ((int)$page['pages'] <= 1) return '';

    $html = '<div class="vault-pager">';
    if ((int)$page['page'] > 1) {
        $html .= '<a class="ghost compact" href="/files?'.$param.'='.((int)$page['page'] - 1).'#'.$anchor.'">← Previous</a>';
    }
    $html .= '<span>Page '.(int)$page['page'].' of '.(int)$page['pages'].'</span>';
    if ((int)$page['page'] < (int)$page['pages']) {
        $html .= '<a class="ghost compact" href="/files?'.$param.'='.((int)$page['page'] + 1).'#'.$anchor.'">Next →</a>';
    }
    return $html.'</div>';
}

function vaultFileCard(array $row, bool $ownerView = false): string
{
    $id = (int)$row['id'];
    $ext = strtoupper((string)$row['extension']);
    $owner = trim((string)($row['name'] ?? '')) ?: (string)($row['username'] ?? 'user');
    $downloadPath = '/files/download?id='.$id;
    $base = vaultBaseUrl();
    $downloadUrl = $base !== '' ? $base.$downloadPath : $downloadPath;
    $ownerLine = $ownerView
        ? '<div class="vault-owner"><span class="mini-avatar">'.View::e(strtoupper(substr($owner, 0, 1))).'</span><span><strong>'.View::e($owner).'</strong><small>@'.View::e((string)$row['username']).' • User #'.(int)$row['user_id'].'</small></span></div>'
        : '';

    return '<article class="vault-file-card">'
        .'<div class="vault-file-top"><span class="vault-ext vault-ext-'.strtolower($ext).'">'.$ext.'</span>'
        .'<div class="vault-file-name"><strong>'.View::e((string)$row['original_name']).'</strong><small>Version '.(int)$row['version'].' • '.View::e(vaultBytes((int)$row['size_bytes'])).'</small></div></div>'
        .$ownerLine
        .'<div class="vault-file-meta"><span><b>SHA-256</b><code>'.View::e(substr((string)$row['sha256'], 0, 16)).'…</code></span>'
        .'<span><b>Updated</b><strong>'.View::e((string)$row['updated_at']).'</strong></span></div>'
        .'<div class="vault-file-actions">'
        .'<a class="primary compact" href="'.View::e($downloadPath).'">Download</a>'
        .'<button type="button" class="ghost compact" data-copy="'.View::e($downloadUrl).'">Copy link</button>'
        .'</div>'
        .'<form method="post" action="/files/replace" enctype="multipart/form-data" class="vault-replace stack" data-busy="Replacing private file…">'
        .View::csrf()
        .'<input type="hidden" name="file_id" value="'.$id.'">'
        .'<div class="field"><label>Replace this slot</label><input type="file" name="file" accept=".so,.zip" required></div>'
        .'<button class="ghost wide" type="submit">Upload new version</button></form>'
        .'<form method="post" action="/files/delete" class="vault-delete" data-confirm="Delete this private file permanently?">'
        .View::csrf().'<input type="hidden" name="file_id" value="'.$id.'">'
        .'<button class="ghost danger wide" type="submit">Delete file</button></form>'
        .'</article>';
}

try {
    $user = Auth::requireLogin();
    if (PanelControl::blocked($user)) vaultRedirect('/');

    UploadManager::ensureSchema();
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $path = '/'.ltrim((string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/files'), PHP_URL_PATH) ?: '/files'), '/');

    if ($path === '/files/download' && $method === 'GET') {
        UploadManager::download($user, (int)($_GET['id'] ?? 0));
    }

    if ($method === 'POST') {
        Security::verifyCsrf($_POST['csrf'] ?? null);

        if ($path === '/files/upload') {
            UploadManager::upload($user, is_array($_FILES['file'] ?? null) ? $_FILES['file'] : []);
            vaultFlash('ok', 'Private file uploaded.');
            vaultRedirect('/files');
        }

        if ($path === '/files/replace') {
            UploadManager::replace(
                $user,
                (int)($_POST['file_id'] ?? 0),
                is_array($_FILES['file'] ?? null) ? $_FILES['file'] : []
            );
            vaultFlash('ok', 'File replaced with a new version.');
            vaultRedirect('/files');
        }

        if ($path === '/files/delete') {
            UploadManager::delete($user, (int)($_POST['file_id'] ?? 0));
            vaultFlash('ok', 'Private file deleted.');
            vaultRedirect('/files');
        }
    }

    if ($path !== '/files' || $method !== 'GET') {
        http_response_code(404);
        View::page('Not found', '<section class="auth"><div class="card"><h1>404</h1><p class="muted">File route not found.</p><a class="btn" href="/files">Open vault</a></div></section>', $user);
        exit;
    }

    $isOwner = ($user['role'] ?? '') === 'owner';
    $minePage = $isOwner
        ? vaultPagedRows((int)$user['id'], (int)($_GET['my_page'] ?? 1))
        : null;
    $mine = $isOwner ? $minePage['rows'] : UploadManager::listOwn($user);
    $mineTotal = $isOwner ? (int)$minePage['total'] : count($mine);
    $limitText = $isOwner
        ? 'Unlimited files • '.$mineTotal.' stored'
        : $mineTotal.' / '.UploadManager::USER_FILE_LIMIT.' files used';
    $canAdd = $isOwner || $mineTotal < UploadManager::USER_FILE_LIMIT;

    $cards = '';
    foreach ($mine as $row) $cards .= vaultFileCard($row, false);
    if ($cards === '') {
        $cards = '<div class="empty-state vault-empty"><div class="empty-orb">↑</div><h3>Your vault is empty</h3><p class="muted">Upload a private .so or .zip file. Each account uses isolated storage.</p></div>';
    }

    $uploadForm = $canAdd
        ? '<form method="post" action="/files/upload" enctype="multipart/form-data" class="vault-upload-panel stack" data-busy="Uploading private file…">'
            .View::csrf()
            .'<div class="field"><label>Select .so or .zip</label><input type="file" name="file" accept=".so,.zip" required></div>'
            .'<button class="primary wide" type="submit">Upload to private vault</button>'
            .'<p class="hint">The original filename is display-only. Disk storage uses a random private ID, so matching filenames across users never overwrite each other.</p>'
            .'</form>'
        : '<div class="vault-limit-note"><strong>2-file limit reached.</strong><span>Replace either slot as many times as you want, or delete one to upload a different file.</span></div>';

    $minePager = $isOwner ? vaultPager($minePage, 'my_page', 'my-files') : '';

    $ownerSection = '';
    if ($isOwner) {
        $ownerPage = vaultPagedRows(null, (int)($_GET['owner_page'] ?? 1));
        $allCards = '';
        foreach ($ownerPage['rows'] as $row) $allCards .= vaultFileCard($row, true);
        if ($allCards === '') {
            $allCards = '<div class="empty-state"><div class="empty-orb">TD</div><h3>No uploaded files yet</h3><p class="muted">User uploads will appear here.</p></div>';
        }

        $pager = vaultPager($ownerPage, 'owner_page', 'owner-uploads');
        $ownerSection = '<section class="premium-section vault-owner-section" id="owner-uploads">'
            .'<div class="section-heading"><div><span class="eyebrow">OWNER VIEW</span><h2>All user uploads</h2><p>Every account remains isolated. Owner can review, download, replace or delete any stored file.</p></div><span class="tag">'.$ownerPage['total'].' total</span></div>'
            .$pager
            .'<div class="vault-grid">'.$allCards.'</div>'
            .$pager
            .'</section>';
    }

    $body = '<link rel="stylesheet" href="/assets/vault.css?v=20260910-3">'
        .'<section class="hero vault-hero"><div><span class="eyebrow">PRIVATE STORAGE</span><h1>Binary Vault</h1><p class="muted">Private .so / .zip storage with isolated per-user slots and protected downloads.</p></div><span class="vault-quota">'.View::e($limitText).'</span></section>'
        .vaultTakeFlash()
        .'<section class="premium-section" id="my-files"><div class="section-heading"><div><span class="eyebrow">YOUR STORAGE</span><h2>My files</h2><p>Non-owner accounts can keep 2 files at a time. Replacements and deletes do not consume extra slots. Copied download links still require an authorized panel session.</p></div></div>'
        .$uploadForm
        .$minePager
        .'<div class="vault-grid">'.$cards.'</div>'
        .$minePager
        .'</section>'
        .$ownerSection;

    Security::audit((int)$user['id'], 'page_viewed', ['path'=>'/files']);
    View::page('Binary Vault', $body, $user);
} catch (Throwable $e) {
    error_log('TeamDark vault error: '.get_class($e).' at '.basename($e->getFile()).':'.$e->getLine());
    $message = vaultSafeMessage($e);

    if (isset($method) && $method === 'POST') {
        Security::startSession();
        vaultFlash('err', $message);
        vaultRedirect('/files');
    }

    http_response_code(400);
    try { $u = Auth::user(); } catch (Throwable) { $u = null; }
    View::page('File vault unavailable', '<section class="auth"><div class="card"><h1>File vault unavailable</h1><div class="alert">'.View::e($message).'</div><a class="btn" href="/dashboard">Go back</a></div></section>', $u);
}
