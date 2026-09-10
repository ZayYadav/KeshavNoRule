from pathlib import Path

# 1) Only one sidebar item should be active at a time.
p = Path('teamdark-panel/app/View.php')
s = p.read_text()
old = """        $hrefPath = (string)(parse_url($href, PHP_URL_PATH) ?: $href);
        $active = $path === $hrefPath
            || ($hrefPath === '/keys' && str_starts_with($path, '/keys'))
            || ($hrefPath === '/users' && str_starts_with($path, '/users'));
"""
new = """        $hrefPath = (string)(parse_url($href, PHP_URL_PATH) ?: $href);
        $qualified = str_contains($href, '?') || str_contains($href, '#');
        $active = !$qualified && $path === $hrefPath;
"""
if old not in s:
    raise SystemExit('View navLink marker not found')
p.write_text(s.replace(old, new, 1))

# 2) Reuse the existing asynchronous Telegram broadcast queue on the dedicated Panel Alert page.
p = Path('teamdark-panel/app/OwnerSystem.php')
s = p.read_text()
start = s.index('    private static function alerts(array $actor, string $flash): void\n')
end = s.index('    private static function updateCenter(array $actor, string $flash): void\n', start)
method = r'''    private static function alerts(array $actor, string $flash): void
    {
        $s = self::currentSettings();
        $pdo = Database::pdo();
        $panelUsers = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE status='active'")->fetchColumn();
        $linked = (int)$pdo->query('SELECT COUNT(*) FROM telegram_users WHERE linked_user_id IS NOT NULL')->fetchColumn();
        $guests = (int)$pdo->query('SELECT COUNT(*) FROM telegram_users WHERE linked_user_id IS NULL')->fetchColumn();

        $broadcastRows = '';
        foreach (BroadcastService::recent(12) as $job) {
            $total = (int)$job['total_recipients'];
            $sent = (int)$job['sent_count'];
            $failed = (int)$job['failed_count'];
            $done = $sent + $failed;
            $percent = $total > 0 ? min(100, (int)floor(($done / $total) * 100)) : 100;

            $audience = [];
            if ((int)$job['panel_enabled'] === 1) $audience[] = 'Panel';
            if ((int)$job['target_linked'] === 1) $audience[] = 'Linked TG';
            if ((int)$job['target_guests'] === 1) $audience[] = 'Guest TG';

            $statusClass = match ((string)$job['status']) {
                'completed' => 'status-active',
                'partial' => 'status-warning',
                'sending' => 'status-live',
                default => 'status-disabled',
            };

            $broadcastRows .= '<article class="broadcast-job"'
                .' data-broadcast-job="'.(int)$job['id'].'"'
                .' data-broadcast-status="'.View::e((string)$job['status']).'">'
                .'<div class="broadcast-job-head"><div><span class="eyebrow">BROADCAST #'.(int)$job['id'].'</span>'
                .'<strong>'.View::e(implode(' • ', $audience) ?: 'No audience').'</strong></div>'
                .'<span class="status-chip '.$statusClass.'" data-broadcast-status-text>'.View::e(strtoupper((string)$job['status'])).'</span></div>'
                .'<p>'.View::e((string)$job['message']).'</p>'
                .'<div class="broadcast-progress"><i style="width:'.$percent.'%" data-broadcast-bar></i></div>'
                .'<div class="broadcast-meta">'
                .'<span><b data-broadcast-sent>'.$sent.'</b> sent</span>'
                .'<span><b data-broadcast-failed>'.$failed.'</b> failed</span>'
                .'<span><b data-broadcast-total>'.$total.'</b> Telegram</span>'
                .'<span>'.View::e((string)$job['created_at']).'</span>'
                .'</div></article>';
        }

        if ($broadcastRows === '') {
            $broadcastRows = '<div class="empty-state"><div class="empty-orb">TD</div><h3>No broadcasts yet</h3><p class="muted">Publish a panel or Telegram alert to start delivery history.</p></div>';
        }

        $current = trim((string)$s['announcement']);
        $body = self::hero(
            'SYSTEM / ALERTS',
            'Panel Alert',
            'Publish a panel banner and optionally queue the same message for linked or guest Telegram audiences.',
            $current !== '' ? self::badge(true, 'ALERT LIVE', '') : self::badge(false, '', 'NO ALERT')
        )
            .$flash
            .($current !== ''
                ? '<div class="card system-live-alert"><div><span class="eyebrow">LIVE PANEL ALERT</span><strong>'.View::e($current).'</strong><small>'.View::e((string)$s['announcement_published_at']).'</small></div><form method="post" action="/owner/announcements/clear" data-confirm="Clear live panel alert?">'.View::csrf().'<button class="ghost danger">Clear</button></form></div>'
                : '')
            .'<form method="post" action="/owner/announcements/create" class="card system-form stack" data-confirm="Publish this announcement?">'.View::csrf()
            .'<div class="toolbar"><div><span class="eyebrow">NEW ALERT</span><h3>Broadcast message</h3></div><span class="tag" data-char-count>0 / 1000</span></div>'
            .'<div class="field"><label for="broadcast-message">Announcement</label><textarea id="broadcast-message" name="announcement" maxlength="1000" rows="6" required></textarea></div>'
            .'<label class="checkline"><input type="checkbox" data-audience-all> Select all channels</label>'
            .'<div class="system-choice-grid">'
            .'<label><input type="checkbox" name="audience_panel" value="1" checked data-audience-option><span><b>Panel</b><small>'.$panelUsers.' active users</small></span></label>'
            .'<label><input type="checkbox" name="audience_linked" value="1" data-audience-option><span><b>Linked Telegram</b><small>'.$linked.' chats</small></span></label>'
            .'<label><input type="checkbox" name="audience_guests" value="1" data-audience-option><span><b>Guest Telegram</b><small>'.$guests.' chats</small></span></label>'
            .'</div><button class="primary wide">Publish Alert</button></form>'
            .'<div class="card history-card" data-broadcast-queue data-broadcast-csrf="'.View::e(Security::csrfToken()).'">'
            .'<div class="toolbar"><div><span class="eyebrow">DELIVERY ENGINE</span><h3>Recent delivery history</h3></div><span class="queue-live">Queued Telegram deliveries continue automatically while this page is open.</span></div>'
            .'<div class="broadcast-list">'.$broadcastRows.'</div></div>';

        View::page('Panel Alert', $body, $actor);
    }

'''
p.write_text(s[:start] + method + s[end:])
