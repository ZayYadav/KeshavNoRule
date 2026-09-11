<?php
declare(strict_types=1);

use TeamDark\Panel\{Auth,Config,Database,PanelControl,ReferralManager,Security,View};

$root = dirname(__DIR__);
foreach (['Config','Database','PanelControl','Security','Auth','View','ReferralManager'] as $file) {
    require_once $root.'/app/'.$file.'.php';
}

Config::load($root);
Security::enforceHttpsWeb();
Security::headers();
Security::startSession();

function referralLimitRedirect(string $path): never
{
    header('Location: '.$path, true, 303);
    exit;
}

function referralLimitFlash(string $type, string $message): void
{
    $_SESSION['referral_limit_flash'] = [$type, $message];
}

function referralLimitTakeFlash(): string
{
    $flash = $_SESSION['referral_limit_flash'] ?? null;
    unset($_SESSION['referral_limit_flash']);
    if (!is_array($flash)) return '';
    return '<div data-flash role="status" class="alert '.(($flash[0] ?? '') === 'ok' ? 'ok' : '').'">'
        .View::e((string)($flash[1] ?? '')).'</div>';
}

try {
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $path = rawurldecode((string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/owner/referral-limits'), PHP_URL_PATH) ?: '/owner/referral-limits'));

    if (!in_array($path, ['/owner/referral-limits','/owner/referral-limits/'], true)) {
        http_response_code(404);
        exit;
    }

    $actor = Auth::requireLogin();
    Auth::requireRole($actor, 'owner');

    if ($method === 'POST') {
        Security::verifyCsrf($_POST['csrf'] ?? null);
        $inviteId = (int)($_POST['invite_id'] ?? 0);
        $limit = (string)($_POST['max_registrations'] ?? '1');
        try {
            $updated = ReferralManager::setMaxRegistrations($actor, $inviteId, $limit);
            referralLimitFlash(
                'ok',
                'Referral '.$updated['code'].' updated to '.number_format((int)$updated['max_registrations'])
                    .' registrations • '.number_format((int)$updated['used_count']).' used • '
                    .number_format((int)$updated['remaining_registrations']).' remaining.'
            );
        } catch (Throwable $e) {
            $message = $e instanceof PDOException
                ? 'Could not update referral limit.'
                : (trim($e->getMessage()) ?: 'Could not update referral limit.');
            referralLimitFlash('err', substr($message, 0, 300));
        }
        referralLimitRedirect('/owner/referral-limits');
    }

    if ($method !== 'GET' && $method !== 'HEAD') {
        http_response_code(405);
        exit;
    }

    $rows = '';
    foreach (ReferralManager::visible($actor) as $invite) {
        $id = (int)$invite['id'];
        $max = max(1, (int)($invite['max_registrations'] ?? 1));
        $used = max(0, (int)($invite['used_count'] ?? 0));
        $remaining = max(0, $max - $used);
        $status = (string)$invite['status'];
        $statusClass = match ($status) {
            'pending' => 'status-active',
            'used' => 'status-warning',
            default => 'status-disabled',
        };

        $options = '';
        foreach (ReferralManager::REGISTRATION_LIMITS as $choice) {
            $disabled = $choice < $used ? ' disabled' : '';
            $selected = $choice === $max ? ' selected' : '';
            $options .= '<option value="'.$choice.'"'.$selected.$disabled.'>'.number_format($choice).'</option>';
        }

        $control = $status === 'revoked'
            ? '<span class="muted">Revoked</span>'
            : '<form method="post" action="/owner/referral-limits" class="inline" data-confirm="Update this referral registration limit?">'
                .View::csrf()
                .'<input type="hidden" name="invite_id" value="'.$id.'">'
                .'<select name="max_registrations" aria-label="Maximum registrations for '.View::e((string)$invite['code']).'">'.$options.'</select>'
                .'<button class="ghost compact" type="submit">Save limit</button></form>';

        $lastUse = $invite['used_username']
            ? View::e((string)($invite['used_name'] ?: $invite['used_username']).' @'.$invite['used_username'])
            : '<span class="muted">No registrations yet</span>';

        $rows .= '<tr>'
            .'<td><div class="key">'.View::e((string)$invite['code']).'</div><button type="button" class="ghost compact" data-copy="'.View::e((string)$invite['code']).'">Copy</button></td>'
            .'<td>'.View::e(strtoupper((string)$invite['role'])).'<br><span class="muted">@'.View::e((string)$invite['creator_username']).'</span></td>'
            .'<td><strong>'.number_format($used).' / '.number_format($max).'</strong><br><span class="muted">'.number_format($remaining).' remaining</span></td>'
            .'<td><span class="status-chip '.$statusClass.'">'.View::e(strtoupper($status)).'</span><br><span class="muted">Expires '.View::e((string)($invite['expires_at'] ?: 'Never')).'</span></td>'
            .'<td>'.$lastUse.'</td>'
            .'<td>'.$control.'</td>'
            .'</tr>';
    }

    if ($rows === '') {
        $rows = '<tr><td colspan="6"><div class="empty-state"><strong>No referrals yet.</strong><p>Create one from Users & invites.</p></div></td></tr>';
    }

    $body = '<section class="hero"><div><div class="eyebrow">OWNER / REFERRALS</div><h1>Referral registration limits</h1>'
        .'<p class="muted">Control how many accounts each referral can create. Available limits: 1, 2, 10, 100 and 1000.</p></div>'
        .'<a class="ghost" href="/users">Back to Users & invites</a></section>'
        .referralLimitTakeFlash()
        .'<div class="card"><div class="toolbar"><div><h3>All referral limits</h3><p class="muted">Increasing a fully-used referral reactivates it when it has not expired or been revoked. A limit can never be set below registrations already completed.</p></div></div>'
        .'<div class="table-wrap"><table><thead><tr><th>Referral</th><th>Account role</th><th>Usage</th><th>Status</th><th>Latest registration</th><th>Owner control</th></tr></thead><tbody>'
        .$rows.'</tbody></table></div></div>';

    View::page('Referral limits', $body, $actor);
} catch (Throwable $e) {
    error_log('TeamDark referral limit controller error: '.get_class($e).' at '.basename($e->getFile()).':'.$e->getLine());
    http_response_code(500);
    echo 'Referral limits unavailable.';
}
