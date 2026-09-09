<?php
declare(strict_types=1);

namespace TeamDark\Panel;

final class OwnerConsole
{
    private static function rows(string $sql, array $params = []): array
    {
        $q = Database::pdo()->prepare($sql);
        $q->execute($params);
        return $q->fetchAll();
    }

    private static function table(array $headers, array $rows): string
    {
        $html = '<div class="table-wrap"><table><thead><tr>';
        foreach ($headers as $header) $html .= '<th scope="col">'.View::e($header).'</th>';
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($row as $cell) $html .= '<td>'.$cell.'</td>';
            $html .= '</tr>';
        }
        if (!$rows) $html .= '<tr><td colspan="'.count($headers).'">No records match these filters.</td></tr>';
        return $html.'</tbody></table></div>';
    }

    private static function pager(string $path, array $query, int $page, bool $more, string $key = 'page'): string
    {
        $html = '<div class="history-pager"><span>Page '.$page.'</span>';
        if ($page > 1) $html .= '<a class="ghost" href="'.$path.'?'.View::e(http_build_query(array_replace($query, [$key=>$page-1]))).'">Previous</a>';
        if ($more) $html .= '<a class="ghost" href="'.$path.'?'.View::e(http_build_query(array_replace($query, [$key=>$page+1]))).'">Next</a>';
        return $html.'</div>';
    }

    private static function pageNumber(array $query, string $key = 'page'): int
    {
        return max(1, min(1000000, (int)($query[$key] ?? 1)));
    }

    private static function dateValue(mixed $raw): string
    {
        if (!is_string($raw)) return '';
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
        return $date && $date->format('Y-m-d') === $raw ? $raw : '';
    }

    public static function activity(array $actor, array $query): void
    {
        Auth::requireRole($actor, 'owner');
        $page = self::pageNumber($query);
        $offset = ($page-1)*50;
        $uid = max(0, (int)($query['user_id'] ?? 0));
        $search = substr(trim((string)($query['q'] ?? '')), 0, 100);
        $action = substr(trim((string)($query['action'] ?? '')), 0, 100);
        $from = self::dateValue($query['from'] ?? '');
        $to = self::dateValue($query['to'] ?? '');
        $where = ['1=1']; $params = [];
        if ($uid) {
            $where[] = '(a.user_id=? OR CAST(JSON_UNQUOTE(JSON_EXTRACT(a.meta_json,\'$.target_id\')) AS UNSIGNED)=? OR JSON_CONTAINS(a.meta_json,?,\'$.target_ids\'))';
            array_push($params, $uid, $uid, (string)$uid);
        }
        if ($search !== '') {
            $where[] = '(u.username LIKE ? OR u.name LIKE ? OR a.ip_address LIKE ?)';
            array_push($params, '%'.$search.'%', '%'.$search.'%', '%'.$search.'%');
        }
        if ($action !== '') { $where[] = 'a.action=?'; $params[] = $action; }
        if ($from !== '') { $where[] = 'a.created_at>=?'; $params[] = $from.' 00:00:00'; }
        if ($to !== '') { $where[] = 'a.created_at<=?'; $params[] = $to.' 23:59:59'; }
        $records = self::rows('SELECT a.*,u.username,u.name FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id WHERE '.implode(' AND ', $where).' ORDER BY a.id DESC LIMIT 51 OFFSET '.$offset, $params);
        $more = count($records) > 50;
        $records = array_slice($records, 0, 50);
        $rows = [];
        foreach ($records as $record) {
            $meta = json_decode((string)$record['meta_json'], true) ?: [];
            $detail = '<details><summary>View details</summary><pre class="audit-detail">'.View::e(json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)).'</pre><p class="muted">'.View::e($record['user_agent']).'</p></details>';
            $rows[] = [View::e($record['id']), View::e($record['created_at']),
                View::e(str_replace('_', ' ', $record['action'])),
                $record['user_id'] ? '<a class="history-link" href="/owner/users?user_id='.(int)$record['user_id'].'">'.View::e($record['username'] ?: 'User #'.$record['user_id']).'</a>' : 'System / guest',
                View::e($record['ip_address']), $detail];
        }
        $body = '<section class="hero"><div><div class="eyebrow">OWNER AUDIT TRAIL</div><h1>All activity</h1><p class="muted">Sign-ins, page visits, key operations, balance changes and owner actions.</p></div><a class="ghost" href="/owner/users">User insights</a></section>';
        $body .= '<div class="card history-card"><form method="get" action="/activity" class="history-filters">'
            .'<div class="field"><label for="history-q">Username, name or IP</label><input id="history-q" name="q" value="'.View::e($search).'"></div>'
            .'<div class="field"><label for="history-id">User ID (actor or target)</label><input id="history-id" name="user_id" type="number" min="1" value="'.($uid ?: '').'"></div>'
            .'<div class="field"><label for="history-action">Exact action</label><input id="history-action" name="action" placeholder="license_created" value="'.View::e($action).'"></div>'
            .'<div class="field"><label for="history-from">From</label><input id="history-from" type="date" name="from" value="'.View::e($from).'"></div>'
            .'<div class="field"><label for="history-to">Through</label><input id="history-to" type="date" name="to" value="'.View::e($to).'"></div>'
            .'<button class="primary">Apply filters</button><a class="ghost" href="/activity">Clear</a></form>';
        $body .= self::table(['Event','Time','Action','User','IP address','Details'], $rows);
        $body .= self::pager('/activity', ['user_id'=>$uid,'q'=>$search,'action'=>$action,'from'=>$from,'to'=>$to], $page, $more).'</div>';
        $body .= '<p class="hint">All retained events are available through pagination. Events that were never recorded cannot be reconstructed. Passwords, tokens and license secrets are excluded.</p>';
        View::page('All activity', $body, $actor);
    }

    public static function users(array $actor, array $query): void
    {
        Auth::requireRole($actor, 'owner');
        $page = self::pageNumber($query);
        $uid = max(0, (int)($query['user_id'] ?? 0));
        $search = substr(trim((string)($query['q'] ?? '')), 0, 100);
        $filter = $uid ? 'u.id=?' : '(u.username LIKE ? OR u.name LIKE ?)';
        $params = $uid ? [$uid] : ['%'.$search.'%', '%'.$search.'%'];
        $records = self::rows('SELECT u.id,u.name,u.username,u.role,u.status,u.last_login_at,
            (SELECT COUNT(*) FROM license_keys k WHERE k.owner_user_id=u.id) owned_keys,
            (SELECT COUNT(*) FROM license_keys k WHERE k.owner_user_id=u.id AND k.status=\'active\') active_keys,
            (SELECT COUNT(*) FROM audit_logs a WHERE a.user_id=u.id) events,
            (SELECT COALESCE(SUM(-b.amount),0) FROM balance_ledger b WHERE b.user_id=u.id AND b.amount<0) debits,
            COALESCE(g.generated_count,0) generated_count
            FROM users u LEFT JOIN (
                SELECT creator,COUNT(*) generated_count FROM (
                    SELECT created_by creator,id license_id FROM license_keys WHERE key_source<>\'telegram_guest\'
                    UNION SELECT user_id creator,CAST(JSON_UNQUOTE(JSON_EXTRACT(meta_json,\'$.license_id\')) AS UNSIGNED) license_id
                    FROM audit_logs WHERE action=\'license_created\' AND JSON_EXTRACT(meta_json,\'$.license_id\') IS NOT NULL
                ) sources GROUP BY creator
            ) g ON g.creator=u.id WHERE '.$filter.' ORDER BY u.id DESC LIMIT 51 OFFSET '.(($page-1)*50), $params);
        $more = count($records) > 50; $records = array_slice($records, 0, 50);
        $rows = [];
        foreach ($records as $record) {
            $rows[] = ['<a class="history-link" href="/owner/users?user_id='.(int)$record['id'].'">'.View::e($record['name'] ?: $record['username']).'<br>@'.View::e($record['username']).'</a>',
                View::e($record['role'].' / '.$record['status']), View::e($record['generated_count']), View::e($record['owned_keys']), View::e($record['active_keys']), View::e($record['debits']), View::e($record['events']), View::e($record['last_login_at'] ?: 'Never'),
                '<a class="ghost compact" href="/activity?user_id='.(int)$record['id'].'">All history</a>'];
        }
        $body = '<section class="hero"><div><div class="eyebrow">USER INTELLIGENCE</div><h1>User insights</h1><p class="muted">See who created keys, used credits and performed each action.</p></div><a class="ghost" href="/users">Manage accounts</a></section>'
            .'<div class="card history-card"><form method="get" action="/owner/users" class="toolbar"><div class="field"><label for="insights-q">Find a user</label><input id="insights-q" name="q" value="'.View::e($search).'" placeholder="Name or username"></div><button class="primary">Search users</button><a class="ghost" href="/owner/users">All users</a></form>'
            .self::table(['User','Access','Keys created¹','Keys owned','Active keys','Credits debited','Events','Last sign-in','History'], $rows)
            .self::pager('/owner/users', ['q'=>$search,'user_id'=>$uid], $page, $more).'</div>'
            .'<p class="hint">¹ Created keys combine current records and retained generation logs, including logged keys deleted later. Telegram guest keys are attributed separately in the activity log. Credit debits include all negative ledger entries.</p>';
        if ($uid && $records) {
            $keyPage = self::pageNumber($query, 'key_page');
            $ledgerPage = self::pageNumber($query, 'ledger_page');
            $keys = self::rows('SELECT id,label,status,created_by,owner_user_id,created_at,activated_at,expires_at,last_used_at FROM license_keys WHERE created_by=? OR owner_user_id=? ORDER BY id DESC LIMIT 51 OFFSET '.(($keyPage-1)*50), [$uid,$uid]);
            $keyRows = [];
            foreach (array_slice($keys,0,50) as $key) $keyRows[] = array_map([View::class,'e'], [$key['id'],$key['label'],$key['status'],'#'.$key['created_by'].' → #'.$key['owner_user_id'],$key['created_at'],$key['activated_at'] ?: 'Unused',$key['last_used_at'] ?: 'Never']);
            $body .= '<div class="card history-card"><h3>User keys</h3>'.self::table(['Key ID','Label','Status','Creator → owner','Created','Activated','Last used'], $keyRows).self::pager('/owner/users', ['user_id'=>$uid,'ledger_page'=>$ledgerPage], $keyPage, count($keys)>50, 'key_page').'</div>';
            $ledger = self::rows('SELECT b.*,u.username actor FROM balance_ledger b LEFT JOIN users u ON u.id=b.actor_user_id WHERE b.user_id=? ORDER BY b.id DESC LIMIT 51 OFFSET '.(($ledgerPage-1)*50), [$uid]);
            $ledgerRows = [];
            foreach (array_slice($ledger,0,50) as $entry) $ledgerRows[] = array_map([View::class,'e'], [$entry['id'],$entry['created_at'],$entry['amount'],$entry['reason'],$entry['actor'] ?: 'System']);
            $body .= '<div class="card history-card"><h3>Balance history</h3>'.self::table(['Entry','Time','Credit change','Reason','Actor'], $ledgerRows).self::pager('/owner/users', ['user_id'=>$uid,'key_page'=>$keyPage], $ledgerPage, count($ledger)>50, 'ledger_page').'</div>';
        }
        View::page('User insights', $body, $actor);
    }

    public static function settings(array $actor, string $flash): void
    {
        Auth::requireRole($actor, 'owner');
        $settings = PanelControl::settings();
        $tgMutationMaster = (bool)Config::get(
            'telegram_owner_mutations_enabled',
            false
        );
        $tgMutationUnlocked =
            $tgMutationMaster
            && PanelControl::telegramOwnerMutationsUnlocked();
        $tgMutationUntil = (string)(
            $settings['telegram_owner_mutation_until'] ?? ''
        );

        $pdo = Database::pdo();

        $panelUsers = (int)$pdo->query(
            "SELECT COUNT(*) FROM users WHERE status='active'"
        )->fetchColumn();
        $linkedTelegram = (int)$pdo->query(
            'SELECT COUNT(*) FROM telegram_users WHERE linked_user_id IS NOT NULL'
        )->fetchColumn();
        $guestTelegram = (int)$pdo->query(
            'SELECT COUNT(*) FROM telegram_users WHERE linked_user_id IS NULL'
        )->fetchColumn();

        $broadcastRows = '';
        foreach (BroadcastService::recent(12) as $job) {
            $total = (int)$job['total_recipients'];
            $sent = (int)$job['sent_count'];
            $failed = (int)$job['failed_count'];
            $done = $sent + $failed;
            $percent = $total > 0
                ? min(100, (int)floor(($done / $total) * 100))
                : 100;

            $audience = [];
            if ((int)$job['panel_enabled'] === 1) $audience[] = 'Panel';
            if ((int)$job['target_linked'] === 1) $audience[] = 'Linked TG';
            if ((int)$job['target_guests'] === 1) $audience[] = 'Free TG';

            $statusClass = match ($job['status']) {
                'completed' => 'status-active',
                'partial' => 'status-warning',
                'sending' => 'status-live',
                default => 'status-disabled',
            };

            $broadcastRows .= '<article class="broadcast-job"'
                .' data-broadcast-job="'.(int)$job['id'].'"'
                .' data-broadcast-status="'.View::e($job['status']).'">'
                .'<div class="broadcast-job-head"><div>'
                .'<span class="eyebrow">BROADCAST #'.(int)$job['id'].'</span>'
                .'<strong>'.View::e(implode(' • ', $audience) ?: 'No audience').'</strong>'
                .'</div><span class="status-chip '.$statusClass.'" data-broadcast-status-text>'
                .View::e(strtoupper((string)$job['status'])).'</span></div>'
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
            $broadcastRows = '<div class="empty-state premium-empty"><span>📣</span><strong>No broadcasts yet</strong><p>Your announcement delivery history will appear here.</p></div>';
        }

        $activeAnnouncement = trim((string)($settings['announcement'] ?? ''));
        $activePanel = $activeAnnouncement !== ''
            ? '<div class="live-announcement-preview">'
                .'<div class="announcement-signal"><span></span></div>'
                .'<div><span class="eyebrow">LIVE PANEL ANNOUNCEMENT</span>'
                .'<h3>'.View::e($activeAnnouncement).'</h3>'
                .'<small>Published '.View::e((string)($settings['announcement_published_at'] ?: 'recently')).'</small></div>'
                .'<form method="post" action="/owner/announcements/clear" data-confirm="Clear the live panel announcement?" data-busy="Clearing announcement…">'
                .View::csrf().'<button class="ghost danger" type="submit">Clear panel banner</button></form>'
                .'</div>'
            : '<div class="live-announcement-preview is-empty"><div class="announcement-signal"><span></span></div>'
                .'<div><span class="eyebrow">PANEL ANNOUNCEMENT</span><h3>No live panel announcement</h3><small>Publish one below when needed.</small></div></div>';

        $body = '<section class="hero premium-hero"><div><div class="eyebrow">OWNER OPERATIONS</div>'
            .'<h1>Command Center</h1><p class="muted">Server controls, secure broadcasts and audience delivery from one place.</p></div>'
            .'<span class="status-chip status-'.($settings['panel_online'] ? 'active' : 'disabled').'">Panel '.($settings['panel_online'] ? 'ON' : 'OFF').'</span></section>'
            .$flash;

        if (!$settings['installed']) {
            $body .= '<div class="alert">Import database/schema.sql to enable server controls and broadcasts.</div>';
        } else {
            $body .= '<section id="announcements" class="premium-section">'
                .'<div class="section-heading"><div><span class="eyebrow">BROADCAST CENTER</span><h2>Official announcements</h2>'
                .'<p>Publish to the web panel, linked Telegram accounts, free Telegram users, or all audiences together.</p></div>'
                .'<div class="audience-stats">'
                .'<span><b>'.$panelUsers.'</b> Panel</span>'
                .'<span><b>'.$linkedTelegram.'</b> Linked TG</span>'
                .'<span><b>'.$guestTelegram.'</b> Free TG</span>'
                .'</div></div>'
                .$activePanel
                .'<div class="announcement-layout">'
                .'<form method="post" action="/owner/announcements/create" class="card broadcast-composer"'
                .' data-confirm="Publish this official announcement to the selected audiences?"'
                .' data-busy="Creating secure broadcast…">'
                .View::csrf()
                .'<div class="composer-top"><div><span class="eyebrow">NEW BROADCAST</span><h3>Write once. Deliver everywhere.</h3></div>'
                .'<span class="premium-badge">OWNER ONLY</span></div>'
                .'<div class="field"><label for="broadcast-message">Announcement</label>'
                .'<textarea id="broadcast-message" name="announcement" maxlength="1000" rows="7" required'
                .' placeholder="Write the official Team Dark announcement…"></textarea>'
                .'<div class="field-foot"><span>Plain text is safely escaped before Telegram delivery.</span><span data-char-count>0 / 1000</span></div></div>'
                .'<div class="audience-picker-head"><span>Audience</span><label class="audience-all"><input type="checkbox" data-audience-all checked><b>All audiences</b></label></div>'
                .'<div class="audience-picker">'
                .'<label class="audience-card"><input type="checkbox" data-audience-option name="audience_panel" value="1" checked>'
                .'<span class="audience-icon">◈</span><span><strong>Panel users</strong><small>'.$panelUsers.' active accounts • premium banner</small></span></label>'
                .'<label class="audience-card"><input type="checkbox" data-audience-option name="audience_linked" value="1" checked>'
                .'<span class="audience-icon">✈</span><span><strong>Linked Telegram</strong><small>'.$linkedTelegram.' verified panel-linked chats</small></span></label>'
                .'<label class="audience-card"><input type="checkbox" data-audience-option name="audience_guests" value="1" checked>'
                .'<span class="audience-icon">✦</span><span><strong>Free Telegram users</strong><small>'.$guestTelegram.' guest/free bot chats</small></span></label>'
                .'</div>'
                .'<button class="primary premium-primary wide" type="submit">Publish announcement</button>'
                .'<p class="hint">Telegram recipients are snapshotted into a durable queue. Reloading this page does not duplicate already-sent messages.</p>'
                .'</form>'
                .'<div class="card broadcast-history" data-broadcast-queue data-broadcast-csrf="'.View::e(Security::csrfToken()).'">'
                .'<div class="toolbar"><div><span class="eyebrow">DELIVERY HISTORY</span><h3>Recent broadcasts</h3></div>'
                .'<span class="queue-live"><i></i> Auto delivery</span></div>'
                .'<div class="broadcast-list">'.$broadcastRows.'</div>'
                .'</div></div></section>';

            $tgMutationCard = '<section class="premium-section">'
                .'<div class="section-heading"><div><span class="eyebrow">TELEGRAM SECURITY</span>'
                .'<h2>Owner mutation approval</h2><p>Telegram Owner controls are read-only unless a fresh web-authenticated approval window is active.</p></div></div>'
                .'<div class="card history-card"><div class="toolbar"><div><h3>High-risk bot actions</h3>'
                .'<span class="muted">'
                .(!$tgMutationMaster
                    ? 'Disabled by server environment.'
                    : ($tgMutationUnlocked
                        ? 'Approved until '.View::e($tgMutationUntil)
                        : 'Server switch enabled, but web approval is locked.'))
                .'</span></div><span class="status-chip status-'
                .($tgMutationUnlocked ? 'active' : 'disabled').'">'
                .($tgMutationUnlocked ? 'UNLOCKED 5M' : 'LOCKED').'</span></div>';

            if (!$tgMutationMaster) {
                $tgMutationCard .= '<div class="alert">TELEGRAM_OWNER_MUTATIONS_ENABLED is OFF. This is the safest production setting.</div>';
            } elseif ($tgMutationUnlocked) {
                $tgMutationCard .= '<form method="post" action="/owner/telegram-mutations/lock"'
                    .' data-confirm="Lock Telegram Owner mutations now?">'
                    .View::csrf()
                    .'<button class="ghost warning" type="submit">Lock now</button></form>';
            } else {
                $tgMutationCard .= '<form method="post" action="/owner/telegram-mutations/unlock"'
                    .' data-confirm="Allow high-risk Telegram Owner mutations for only 5 minutes?"'
                    .' data-busy="Opening secure Telegram window…">'
                    .View::csrf()
                    .'<button class="primary" type="submit">Approve for 5 minutes</button></form>';
            }

            $tgMutationCard .= '<p class="hint">After 5 minutes it automatically locks again. Reading stats remains available while locked.</p></div></section>';

            $body .= $tgMutationCard;

            $body .= '<section class="premium-section"><div class="section-heading"><div><span class="eyebrow">SERVER POLICY</span>'
                .'<h2>Availability controls</h2><p>Infrastructure-safe controls for panel access and generation.</p></div></div>'
                .'<form method="post" action="/owner/settings" class="card history-card stack premium-settings"'
                .' data-confirm="Save the site experience and server controls?">'
                .View::csrf().'<input type="hidden" name="revision" value="'.$settings['revision'].'">'
                .'<input type="hidden" name="splash_present" value="1">'
                .'<div class="splash-control-block" data-splash-control>'
                .'<div class="splash-control-head"><div><span class="eyebrow">SITE EXPERIENCE</span><h3>Opening splash</h3>'
                .'<p>Show a cinematic Team Dark intro when someone opens the website. It appears once per browser session/version.</p></div>'
                .'<span class="premium-badge">'.((bool)$settings['splash_enabled'] ? 'LIVE' : 'OFF').'</span></div>'
                .'<div class="splash-mini-preview" data-splash-preview>'
                .'<div class="splash-mini-orbit"><i></i><i></i></div>'
                .'<div class="splash-mini-logo">TD</div>'
                .'<div><span>SECURE ACCESS INITIALIZING</span><strong data-splash-preview-title>'.View::e((string)$settings['splash_title']).'</strong>'
                .'<small data-splash-preview-subtitle>'.View::e((string)$settings['splash_subtitle']).'</small></div>'
                .'<div class="splash-mini-progress"><i></i></div></div>'
                .'<label class="setting-row premium-setting" for="splash_enabled"><span><strong>Splash screen ON / OFF</strong>'
                .'<small>Applies to guest, login, registration and authenticated panel web pages. APIs and Loader /connect are never affected.</small></span>'
                .'<input id="splash_enabled" class="toggle-input" type="checkbox" name="splash_enabled" value="1" data-splash-enabled'
                .((bool)$settings['splash_enabled'] ? ' checked' : '').'></label>'
                .'<div class="splash-fields">'
                .'<div class="field"><label for="splash-title">Splash title</label>'
                .'<input id="splash-title" name="splash_title" maxlength="60" required data-splash-title value="'.View::e((string)$settings['splash_title']).'"></div>'
                .'<div class="field"><label for="splash-subtitle">Splash subtitle</label>'
                .'<input id="splash-subtitle" name="splash_subtitle" maxlength="160" data-splash-subtitle value="'.View::e((string)$settings['splash_subtitle']).'"></div>'
                .'<div class="field"><label for="splash-duration">Display time</label>'
                .'<select id="splash-duration" name="splash_duration_ms" data-splash-duration>';
            
            foreach ([1400=>'Fast • 1.4s',2000=>'Quick • 2.0s',2400=>'Balanced • 2.4s',3200=>'Cinematic • 3.2s',4200=>'Showcase • 4.2s'] as $ms=>$label) {
                $body .= '<option value="'.$ms.'"'.((int)$settings['splash_duration_ms'] === $ms ? ' selected' : '').'>'.View::e($label).'</option>';
            }

            $body .= '</select></div></div>'
                .'<div class="splash-note"><span>◈</span><div><strong>Session-aware</strong><small>Visitors do not see it again on every internal page. Changing splash settings publishes a new splash version.</small></div></div>'
                .'</div>';

            foreach ([
                'panel_online'=>['Panel ON / OFF','OFF blocks non-owner web panel, panel-account API access and Telegram bot operations. Owner access stays available.'],
                'registration_open'=>['New registrations','Allow new accounts to redeem referral invites.'],
                'generation_open'=>['User key generation','Allow non-owner and Telegram guest key generation. Owners can still generate keys.'],
            ] as $key=>$copy) {
                $body .= '<label class="setting-row premium-setting" for="'.$key.'"><span><strong>'.View::e($copy[0]).'</strong>'
                    .'<small>'.View::e($copy[1]).'</small></span><input id="'.$key.'" class="toggle-input" type="checkbox" name="'.$key.'" value="1"'
                    .($settings[$key] ? ' checked' : '').'></label>';
            }

            $body .= '<div class="field"><label for="maintenance-message">Maintenance message</label>'
                .'<input id="maintenance-message" name="message" maxlength="500" value="'.View::e($settings['message']).'"></div>'
                .'<button class="primary">Save server controls</button>'
                .'<p class="hint">These settings do not change the native Loader /connect contract.</p></form></section>';
        }

        View::page('Owner Command Center', $body, $actor);
    }
}
