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

function vaultFileCard(array $row, array $viewer, bool $ownerView = false): string
{
    $id = (int)$row['id'];
    $ext = strtoupper((string)$row['extension']);
    $owner = trim((string)($row['name'] ?? '')) ?: (string)($row['username'] ?? 'user');
    $download = '/files/download?id='.$id;
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
        .'<a class="primary compact" href="'.$download.'">Download</a>'
        .'<button type="button" class="ghost compact" data-copy="'.View::e($download).'">Copy link</button>'
        .'<a class="ghost compact" href="/key-edit" style="display:none" aria-hidden="true"></a>'
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

    $mine = UploadManager::listOwn($user);
    $limitText = ($user['role'] ?? '') === 'owner' ? 'Unlimited files' : count($mine).' / '.UploadManager::USER_FILE_LIMIT.' files used';
    $canAdd = ($user['role'] ?? '') === 'owner' || count($mine) < UploadManager::USER_FILE_LIMIT;

    $cards = '';
    foreach ($mine as $row) $cards .= vaultFileCard($row, $user, false);
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

    $ownerSection = '';
    if (($user['role'] ?? '') === 'owner') {
        $all = UploadManager::listAll($user);
        $allCards = '';
        foreach ($all as $row) $allCards .= vaultFileCard($row, $user, true);
        if ($allCards === '') {
            $allCards = '<div class="empty-state"><div class="empty-orb">TD</div><h3>No uploaded files yet</h3><p class="muted">User uploads will appear here.</p></div>';
        }

        $ownerSection = '<section class="premium-section vault-owner-section">'
            .'<div class="section-heading"><div><span class="eyebrow">OWNER VIEW</span><h2>All user uploads</h2><p>Every account remains isolated. Owner can review, download, replace or delete any stored file.</p></div><span class="tag">'.count($all).' total</span></div>'
            .'<div class="vault-grid">'.$allCards.'</div></section>';
    }

    $body = '<link rel="stylesheet" href="/assets/vault.css?v=20260910-1">'
        .'<section class="hero vault-hero"><div><span class="eyebrow">PRIVATE STORAGE</span><h1>Binary Vault</h1><p class="muted">Private .so / .zip storage with isolated per-user slots and protected downloads.</p></div><span class="vault-quota">'.View::e($limitText).'</span></section>'
        .vaultTakeFlash()
        .'<section class="premium-section"><div class="section-heading"><div><span class="eyebrow">YOUR STORAGE</span><h2>My files</h2><p>Non-owner accounts can keep 2 files at a time. Replacements and deletes do not consume extra slots.</p></div></div>'
        .$uploadForm
        .'<div class="vault-grid">'.$cards.'</div></section>'
        .$ownerSection;

    Security::audit((int)$user['id'], 'page_viewed', ['path'=>'/files']);
    View::page('Binary Vault', $body, $user);
} catch (Throwable $e) {
    error_log('TeamDark vault error: '.get_class($e).' at '.basename($e->getFile()).':'.$e->getLine().' '.$e->getMessage());
    if (isset($method) && $method === 'POST') {
        Security::startSession();
        vaultFlash('err', substr($e->getMessage() ?: 'File action failed.', 0, 300));
        vaultRedirect('/files');
    }
    http_response_code(400);
    try { $u = Auth::user(); } catch (Throwable) { $u = null; }
    View::page('File vault unavailable', '<section class="auth"><div class="card"><h1>File vault unavailable</h1><div class="alert">'.View::e(substr($e->getMessage() ?: 'Request failed.', 0, 300)).'</div><a class="btn" href="/dashboard">Go back</a></div></section>', $u);
}
