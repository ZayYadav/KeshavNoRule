<?php
declare(strict_types=1);

namespace TeamDark\Panel;

final class Announcements
{
    public const KINDS = ['info'=>'Information','success'=>'Good news','warning'=>'Warning','critical'=>'Critical'];
    public const AUDIENCES = ['all'=>'Everyone','owner'=>'Owners','admin'=>'Admins','reseller'=>'Resellers','user'=>'Users'];
    public const STATES = ['draft'=>'Draft','published'=>'Published','archived'=>'Archived'];

    public static function installed(): bool
    {
        try {
            Database::pdo()->query('SELECT a.id FROM announcements a LEFT JOIN announcement_dismissals d ON d.announcement_id=a.id LIMIT 1');
            return true;
        } catch (\PDOException $e) {
            if (($e->errorInfo[1] ?? 0) !== 1146) throw $e;
            return false;
        }
    }

    private static function text(array $input, string $key, int $limit, bool $required = true): string
    {
        $raw = $input[$key] ?? '';
        if (!is_string($raw)) throw new \RuntimeException('Invalid '.$key.'.');
        $value = trim($raw);
        $length = preg_match_all('/./us', $value);
        if ($length === false || $length > $limit || ($required && $value === '')) {
            throw new \RuntimeException(ucfirst($key).' must contain 1–'.$limit.' characters.');
        }
        return $value;
    }

    private static function date(array $input, string $key): ?string
    {
        $value = self::text($input, $key, 16, false);
        if ($value === '') return null;
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, new \DateTimeZone('UTC'));
        if (!$date || $date->format('Y-m-d\TH:i') !== $value) throw new \RuntimeException('Enter a valid UTC date and time.');
        return $date->format('Y-m-d H:i:s');
    }

    public static function save(array $actor, array $input): int
    {
        Auth::requireRole($actor, 'owner');
        $title = self::text($input, 'title', 100);
        $body = self::text($input, 'body', 4000);
        $kind = self::text($input, 'kind', 20);
        $audience = self::text($input, 'audience', 20);
        $state = self::text($input, 'state', 20);
        if (!isset(self::KINDS[$kind], self::AUDIENCES[$audience], self::STATES[$state])) throw new \RuntimeException('Invalid announcement options.');
        $start = self::date($input, 'starts_at'); $end = self::date($input, 'ends_at');
        if ($start !== null && $end !== null && $end <= $start) throw new \RuntimeException('End time must be after start time.');
        $id = max(0, (int)($input['id'] ?? 0));
        $values = [$title,$body,$kind,$audience,$state,($input['pinned'] ?? '')==='1' ? 1 : 0,($input['dismissible'] ?? '')==='1' ? 1 : 0,$start,$end,$actor['id']];
        $pdo = Database::pdo(); $pdo->beginTransaction();
        try {
            if ($id) {
                $q = $pdo->prepare('UPDATE announcements SET title=?,body=?,kind=?,audience=?,state=?,pinned=?,dismissible=?,starts_at=?,ends_at=?,updated_by=?,version=version+1 WHERE id=? AND version=?');
                $q->execute([...$values,$id,(int)($input['version'] ?? -1)]);
                if ($q->rowCount() !== 1) throw new \RuntimeException('Announcement changed or no longer exists. Reload before editing.');
            } else {
                $pdo->prepare('INSERT INTO announcements(title,body,kind,audience,state,pinned,dismissible,starts_at,ends_at,updated_by,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?)')->execute([...$values,$actor['id']]);
                $id = (int)$pdo->lastInsertId();
            }
            Security::audit((int)$actor['id'], 'announcement_saved', ['announcement_id'=>$id,'title'=>$title,'state'=>$state,'audience'=>$audience,'starts_at'=>$start,'ends_at'=>$end]);
            $pdo->commit();
            return $id;
        } catch (\Throwable $e) {
            $pdo->rollBack(); throw $e;
        }
    }

    public static function archive(array $actor, int $id, int $version): void
    {
        Auth::requireRole($actor, 'owner');
        $pdo = Database::pdo(); $pdo->beginTransaction();
        try {
            $q = $pdo->prepare("UPDATE announcements SET state='archived',version=version+1,updated_by=? WHERE id=? AND version=? AND state<>'archived'");
            $q->execute([$actor['id'],$id,$version]);
            if ($q->rowCount() !== 1) throw new \RuntimeException('Announcement changed or already archived. Reload and try again.');
            Security::audit((int)$actor['id'], 'announcement_archived', ['announcement_id'=>$id]);
            $pdo->commit();
        } catch (\Throwable $e) { $pdo->rollBack(); throw $e; }
    }

    private static function visibleWhere(): string
    {
        return "a.state='published' AND (a.audience='all' OR a.audience=?) AND (a.starts_at IS NULL OR a.starts_at<=UTC_TIMESTAMP()) AND (a.ends_at IS NULL OR a.ends_at>UTC_TIMESTAMP())";
    }

    public static function visible(array $user, bool $banners = false, int $page = 1): array
    {
        if (!self::installed()) return [];
        $limit = $banners ? 5 : 21;
        $offset = $banners ? 0 : (max(1,min(1000000,$page))-1)*20;
        $q = Database::pdo()->prepare('SELECT a.*,d.dismissed_at FROM announcements a LEFT JOIN announcement_dismissals d ON d.announcement_id=a.id AND d.user_id=? AND d.version=a.version WHERE '.self::visibleWhere().($banners ? ' AND d.user_id IS NULL' : '').' ORDER BY a.pinned DESC,a.created_at DESC,a.id DESC LIMIT '.$limit.' OFFSET '.$offset);
        $q->execute([$user['id'],$user['role']]);
        return $q->fetchAll();
    }

    public static function dismiss(array $user, int $id, int $version, bool $restore): void
    {
        $pdo = Database::pdo(); $pdo->beginTransaction();
        try {
            $q = $pdo->prepare('SELECT a.id,a.dismissible FROM announcements a WHERE '.self::visibleWhere().' AND a.id=? AND a.version=? FOR UPDATE');
            $q->execute([$user['role'],$id,$version]);
            $row = $q->fetch();
            if (!$row || !(bool)$row['dismissible']) throw new \RuntimeException('This announcement cannot be dismissed. Refresh the page.');
            if ($restore) $pdo->prepare('DELETE FROM announcement_dismissals WHERE announcement_id=? AND user_id=? AND version=?')->execute([$id,$user['id'],$version]);
            else $pdo->prepare('INSERT IGNORE INTO announcement_dismissals(announcement_id,user_id,version) VALUES(?,?,?)')->execute([$id,$user['id'],$version]);
            Security::audit((int)$user['id'], $restore ? 'announcement_restored' : 'announcement_dismissed', ['announcement_id'=>$id,'version'=>$version]);
            $pdo->commit();
        } catch (\Throwable $e) { $pdo->rollBack(); throw $e; }
    }

    public static function card(array $row, bool $banner = false): string
    {
        $html = '<article class="notice notice-'.View::e($row['kind']).'" aria-label="'.View::e(self::KINDS[$row['kind']]).'"><div class="notice-heading"><span class="eyebrow">'.View::e(self::KINDS[$row['kind']]).((int)$row['pinned'] ? ' · PINNED' : '').'</span><h3>'.View::e($row['title']).'</h3></div><p class="notice-body">'.View::e($row['body']).'</p><div class="notice-footer">';
        if ($row['ends_at']) $html .= '<small>Until '.View::e($row['ends_at']).' UTC</small>';
        if ($banner) $html .= '<a class="history-link" href="/announcements">All announcements</a>';
        if ((int)$row['dismissible']) {
            $restore = !empty($row['dismissed_at']);
            $html .= '<form method="post" action="/announcements/dismiss">'.View::csrf().'<input type="hidden" name="id" value="'.(int)$row['id'].'"><input type="hidden" name="version" value="'.(int)$row['version'].'"><input type="hidden" name="restore" value="'.($restore ? '1' : '0').'"><button class="ghost compact" aria-label="'.View::e(($restore ? 'Show banner: ' : 'Dismiss banner: ').$row['title']).'">'.($restore ? 'Show banner again' : 'Dismiss banner').'</button></form>';
        }
        return $html.'</div></article>';
    }

    public static function banners(array $user): string
    {
        return implode('',array_map(static fn(array $row): string => self::card($row,true), self::visible($user,true)));
    }

    public static function inbox(array $user, int $page, string $flash): void
    {
        $page = max(1,min(1000000,$page));
        $rows = self::visible($user,false,$page);
        $html = '<section class="hero"><div><div class="eyebrow">UPDATES</div><h1>Announcements</h1><p class="muted">Current notices for your account. Dismissed banners remain available here.</p></div></section>'.$flash;
        if (!$rows) $html .= '<div class="empty-state"><h3>No announcements on this page</h3></div>';
        foreach (array_slice($rows,0,20) as $row) $html .= self::card($row);
        $html .= '<div class="history-pager">';
        if ($page>1) $html .= '<a class="ghost" href="/announcements?page='.($page-1).'">Previous</a>';
        if (count($rows)>20) $html .= '<a class="ghost" href="/announcements?page='.($page+1).'">Next</a>';
        View::page('Announcements',$html.'</div>',$user);
    }

    private static function select(string $name, string $label, array $options, string $selected): string
    {
        $html = '<div class="field"><label for="notice-'.$name.'">'.$label.'</label><select id="notice-'.$name.'" name="'.$name.'">';
        foreach ($options as $value=>$text) $html .= '<option value="'.$value.'"'.($value===$selected ? ' selected' : '').'>'.$text.'</option>';
        return $html.'</select></div>';
    }

    public static function manager(array $owner, array $query, string $flash, ?array $draft = null): void
    {
        Auth::requireRole($owner, 'owner');
        $html = '<section class="hero"><div><div class="eyebrow">OWNER COMMUNICATIONS</div><h1>Announcement center</h1><p class="muted">Create, schedule and manage notices for the right accounts.</p></div><a class="ghost" href="/owner/announcements">New announcement</a></section>'.$flash;
        if (!self::installed()) {
            View::page('Announcement center',$html.'<div class="alert">Import database/schema.sql to enable the announcement center. Existing notices remain available.</div>',$owner);
            return;
        }
        $id = max(0,(int)($query['edit'] ?? 0));
        $row = ['id'=>0,'version'=>0,'title'=>'','body'=>'','kind'=>'info','audience'=>'all','state'=>'draft','pinned'=>0,'dismissible'=>1,'starts_at'=>'','ends_at'=>''];
        if ($id) {
            $q = Database::pdo()->prepare('SELECT * FROM announcements WHERE id=?'); $q->execute([$id]); $saved=$q->fetch();
            if (!$saved) { http_response_code(404); View::page('Not found',$html.'<div class="alert">Announcement not found.</div>',$owner); return; }
            $row = $saved;
        }
        if ($draft !== null) $row = array_replace($row,$draft);
        $html .= '<div class="notice-editor"><form method="post" action="/owner/announcements/save" class="card stack" data-notice-editor>'.View::csrf().'<input type="hidden" name="id" value="'.(int)$row['id'].'"><input type="hidden" name="version" value="'.(int)$row['version'].'"><h3>'.($id ? 'Edit announcement' : 'Create announcement').'</h3>';
        $html .= '<div class="field"><label for="notice-title">Title</label><input id="notice-title" name="title" maxlength="100" required value="'.View::e($row['title']).'"></div><div class="field"><label for="notice-body">Message</label><textarea id="notice-body" name="body" rows="6" maxlength="4000" required>'.View::e($row['body']).'</textarea></div>';
        $html .= '<div class="form-row">'.self::select('kind','Notice type',self::KINDS,$row['kind']).self::select('audience','Audience',self::AUDIENCES,$row['audience']).'</div>'.self::select('state','Publication state',self::STATES,$row['state']);
        $html .= '<div class="form-row">';
        foreach (['starts_at'=>'Start (UTC, optional)','ends_at'=>'End (UTC, optional)'] as $key=>$label) {
            $value = $row[$key] ? str_replace(' ','T',substr((string)$row[$key],0,16)) : '';
            $html .= '<div class="field"><label for="notice-'.$key.'">'.$label.'</label><input type="datetime-local" id="notice-'.$key.'" name="'.$key.'" value="'.View::e($value).'"></div>';
        }
        $html .= '</div><label class="checkline"><input type="checkbox" name="pinned" value="1"'.($row['pinned'] ? ' checked' : '').'>Pin above other notices</label><label class="checkline"><input type="checkbox" name="dismissible" value="1"'.($row['dismissible'] ? ' checked' : '').'>Allow users to dismiss the banner</label><p class="hint">Published notices appear at the start time (or immediately) and disappear at the end time. Drafts are private. Saving an edit creates a new version and shows the banner again.</p><button class="primary">Save announcement</button></form>';
        $html .= '<aside class="card notice-preview"><div class="eyebrow">LIVE PREVIEW · NOT PUBLISHED</div><article class="notice notice-info" data-notice-preview><h3 data-preview-title>Announcement title</h3><p class="notice-body" data-preview-body>Your message appears here.</p><small data-preview-audience>Everyone</small></article></aside></div>';
        $page = max(1,min(1000000,(int)($query['page'] ?? 1)));
        $q = Database::pdo()->query('SELECT a.*,(SELECT COUNT(*) FROM announcement_dismissals d WHERE d.announcement_id=a.id AND d.version=a.version) dismissed_count FROM announcements a ORDER BY a.id DESC LIMIT 26 OFFSET '.(($page-1)*25)); $records=$q->fetchAll();
        $html .= '<section class="card history-card"><h3>All announcements</h3><p class="muted">Dismissals count only the current version; they do not measure views or reads.</p><div class="table-wrap"><table><thead><tr><th>Title</th><th>Status</th><th>Audience</th><th>Schedule (UTC)</th><th>Dismissals</th><th>Actions</th></tr></thead><tbody>';
        foreach (array_slice($records,0,25) as $record) {
            $state = $record['state'];
            if ($state==='published') {
                if ($record['ends_at'] && $record['ends_at']<=gmdate('Y-m-d H:i:s')) $state='Ended';
                elseif ($record['starts_at'] && $record['starts_at']>gmdate('Y-m-d H:i:s')) $state='Scheduled';
                else $state='Live';
            }
            $html .= '<tr><td>'.View::e($record['title']).'<br><small>Version '.(int)$record['version'].'</small></td><td>'.View::e(ucfirst($state)).'</td><td>'.View::e(self::AUDIENCES[$record['audience']]).'</td><td>'.View::e($record['starts_at'] ?: 'Immediately').'<br>'.View::e($record['ends_at'] ?: 'No expiry').'</td><td>'.(int)$record['dismissed_count'].'</td><td><a class="ghost compact" href="/owner/announcements?edit='.(int)$record['id'].'">Edit</a>';
            if ($record['state']!=='archived') $html .= '<form method="post" action="/owner/announcements/archive" class="inline" data-confirm="Archive this announcement and hide it from users?">'.View::csrf().'<input type="hidden" name="id" value="'.(int)$record['id'].'"><input type="hidden" name="version" value="'.(int)$record['version'].'"><button class="ghost compact">Archive</button></form>';
            $html .= '</td></tr>';
        }
        if (!$records) $html .= '<tr><td colspan="6">No announcements yet. Start with a draft above.</td></tr>';
        $html .= '</tbody></table></div><div class="history-pager">';
        if ($page>1) $html .= '<a class="ghost" href="/owner/announcements?page='.($page-1).'">Previous</a>';
        if (count($records)>25) $html .= '<a class="ghost" href="/owner/announcements?page='.($page+1).'">Next</a>';
        View::page('Announcement center',$html.'</div></section>',$owner);
    }
}
