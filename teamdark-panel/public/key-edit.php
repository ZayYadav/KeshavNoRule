<?php
declare(strict_types=1);

use TeamDark\Panel\{Auth,Config,Crypto,Database,KeyEditor,KeyManager,PanelControl,Security,View};

$root = dirname(__DIR__);
foreach (['Config','Database','Security','Crypto','PanelControl','Auth','View','KeyManager','KeyEditor'] as $file) {
    require $root.'/app/'.$file.'.php';
}

Config::load($root);
Security::enforceHttpsWeb();
Security::headers();
Security::startSession();

function keyEditRedirect(string $path): never
{
    header('Location: '.$path, true, 303);
    exit;
}

function keyEditFlash(string $type, string $message): void
{
    $_SESSION['flash'] = [$type, $message];
}

function keyEditTakeFlash(): string
{
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    if (!is_array($f)) return '';
    return '<div data-flash role="status" class="alert '.(($f[0] ?? '') === 'ok' ? 'ok' : '').'">'.View::e((string)($f[1] ?? '')).'</div>';
}

function keyEditSafeMessage(Throwable $e): string
{
    if ($e instanceof PDOException) {
        error_log('TeamDark key editor database failure at '.basename($e->getFile()).':'.$e->getLine());
        return 'Key update failed. Please try again.';
    }

    $message = trim($e->getMessage());
    return $message !== '' ? substr($message, 0, 300) : 'Key update failed.';
}

try {
    $user = Auth::requireLogin();
    if (PanelControl::blocked($user)) keyEditRedirect('/');
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'POST') {
        Security::verifyCsrf($_POST['csrf'] ?? null);
        $keyId = (int)($_POST['key_id'] ?? 0);

        $cost = KeyEditor::save(
            $user,
            $keyId,
            (string)($_POST['key_value'] ?? ''),
            trim((string)($_POST['label'] ?? '')),
            (int)($_POST['duration_days'] ?? 30),
            isset($_POST['unlimited_expiry']) && $_POST['unlimited_expiry'] === '1',
            (int)($_POST['max_devices'] ?? 1),
            isset($_POST['unlimited_devices']) && $_POST['unlimited_devices'] === '1'
        );

        keyEditFlash(
            'ok',
            'Key details updated.'.($cost > 0 ? ' Upgrade cost: '.$cost.' credit(s).' : '')
        );
        keyEditRedirect('/keys');
    }

    if ($method !== 'GET') {
        http_response_code(405);
        exit;
    }

    $keyId = (int)($_GET['id'] ?? 0);
    $row = KeyEditor::get($user, $keyId);
    $days = max(1, (int)ceil(max(86400, (int)$row['duration_seconds']) / 86400));
    $owner = trim((string)($row['owner_display_name'] ?? '')) ?: (string)$row['owner_name'];
    $isOwner = (($user['role'] ?? '') === 'owner');
    $unlimitedControls = $isOwner
        ? '<label class="checkline"><input type="checkbox" name="unlimited_expiry" value="1" '.((int)$row['unlimited_expiry'] === 1 ? 'checked' : '').'> Unlimited validity</label>'
            .'<label class="checkline"><input type="checkbox" name="unlimited_devices" value="1" '.((int)$row['unlimited_devices'] === 1 ? 'checked' : '').'> Unlimited devices</label>'
        : '<p class="hint">Unlimited validity and unlimited devices are Owner-only. Existing Owner-granted unlimited entitlements are read-only here.</p>';

    $body = '<link rel="stylesheet" href="/assets/vault.css?v=20260910-2">'
        .'<section class="hero"><div><span class="eyebrow">LICENSE EDITOR</span><h1>Edit key</h1>'
        .'<p class="muted">Owner: '.View::e($owner).' • Status: '.View::e(strtoupper((string)$row['status'])).'</p></div>'
        .'<a class="ghost" href="/keys">← Back to keys</a></section>'
        .keyEditTakeFlash()
        .'<div class="card spotlight key-editor-card">'
        .'<form method="post" action="/key-edit" class="stack" data-busy="Updating license…">'
        .View::csrf().'<input type="hidden" name="key_id" value="'.$keyId.'">'
        .'<div class="field"><label>Key value</label><input name="key_value" value="'.View::e((string)$row['plain_key']).'" minlength="5" maxlength="80" required autocomplete="off"><p class="hint">5–80 characters. Letters, numbers, spaces and symbols are allowed.</p></div>'
        .'<div class="field"><label>Label <span class="optional">optional</span></label><input name="label" value="'.View::e((string)$row['label']).'" maxlength="100"></div>'
        .'<div class="form-row">'
        .'<div class="field"><label>Validity in days</label><input type="number" name="duration_days" min="1" max="36500" value="'.$days.'" required></div>'
        .'<div class="field"><label>Maximum devices</label><input type="number" name="max_devices" min="1" max="1000000" value="'.max(1, (int)$row['max_devices']).'" required></div>'
        .'</div>'
        .$unlimitedControls
        .'<p class="hint">For non-owner accounts, increasing finite validity deducts only the extra credit difference. Downgrades do not refund credits. Owner edits cost 0.</p>'
        .'<div class="security-detail"><span>Activated</span><strong>'.View::e((string)($row['activated_at'] ?: 'Not yet')).'</strong></div>'
        .'<div class="security-detail"><span>Current expiry</span><strong>'.View::e((int)$row['unlimited_expiry'] === 1 ? 'Unlimited' : (string)($row['expires_at'] ?: 'Starts on first use')).'</strong></div>'
        .'<button class="primary wide" type="submit">Save all key changes</button>'
        .'</form></div>';

    View::page('Edit Key', $body, $user);
} catch (Throwable $e) {
    error_log('TeamDark key edit error: '.get_class($e).' at '.basename($e->getFile()).':'.$e->getLine());
    Security::startSession();
    keyEditFlash('err', keyEditSafeMessage($e));
    keyEditRedirect('/keys');
}
