<?php
declare(strict_types=1);

use TeamDark\Panel\{Announcements,Database};

// Included by the isolated owner-controls suite; reuse its authenticated test clients.
if (!isset($ownerClient,$userClient,$ownerCsrf,$userCsrf,$user,$owner,$pdo)) throw new RuntimeException('Run owner_controls_integration.php first.');
require_once dirname(__DIR__).'/app/Auth.php';
require_once dirname(__DIR__).'/app/View.php';

function announcementPayload(array $overrides = []): array {
    global $ownerCsrf;
    return array_replace(['csrf'=>$ownerCsrf,'id'=>'0','version'=>'0','title'=>'Test notice','body'=>'A test message','kind'=>'info','audience'=>'all','state'=>'published','pinned'=>'0','dismissible'=>'1','starts_at'=>'','ends_at'=>''],$overrides);
}
function saveAnnouncement(array $values = []): array {
    global $ownerClient,$pdo;
    $result = request($ownerClient,'/owner/announcements/save',announcementPayload($values));
    check($result['status']===303,'announcement save accepted');
    $id = (int)($values['id'] ?? 0) ?: (int)$pdo->lastInsertId();
    // HTTP writes use a separate connection, so select the fixture's latest row.
    if (!(int)($values['id'] ?? 0)) $id=(int)$pdo->query('SELECT MAX(id) FROM announcements')->fetchColumn();
    $q=$pdo->prepare('SELECT * FROM announcements WHERE id=?');$q->execute([$id]);return $q->fetch();
}
function visibleIds(array $actor, bool $banners=true): array {
    return array_map('intval',array_column(Announcements::visible($actor,$banners),'id'));
}

$baseline=(int)$pdo->query('SELECT COUNT(*) FROM announcements')->fetchColumn();
check(request($ownerClient,'/owner/announcements')['status']===200,'owner announcement center renders');
check(request($userClient,'/owner/announcements')['status']!==200,'announcement management owner-only');
check(request(client(),'/announcements')['status']!==200,'announcement inbox requires authentication');
request($userClient,'/owner/announcements/save',announcementPayload(['csrf'=>$userCsrf]));
request($ownerClient,'/owner/announcements/save',announcementPayload(['csrf'=>'invalid']));
check((int)$pdo->query('SELECT COUNT(*) FROM announcements')->fetchColumn()===$baseline,'authorization and CSRF prevent notice creation');

$draft=saveAnnouncement(['state'=>'draft','title'=>'Private draft']);
check(!in_array((int)$draft['id'],visibleIds($user),true),'draft remains private');
$future=saveAnnouncement(['starts_at'=>gmdate('Y-m-d\TH:i',time()+3600)]);
$expired=saveAnnouncement(['ends_at'=>gmdate('Y-m-d\TH:i',time()-3600)]);
check(!in_array((int)$future['id'],visibleIds($user),true) && !in_array((int)$expired['id'],visibleIds($user),true),'future and expired notices hidden');
$bad=request($ownerClient,'/owner/announcements/save',announcementPayload(['title'=>'Retain this draft','starts_at'=>'2030-02-01T10:00','ends_at'=>'2030-02-01T09:00']));
check($bad['status']===422 && str_contains($bad['body'],'Retain this draft'),'invalid schedule rejected without losing draft');
$bad=request($ownerClient,'/owner/announcements/save',announcementPayload(['starts_at'=>'2030-02-30T12:00']));
check($bad['status']===422,'invalid calendar date rejected');
$target=saveAnnouncement(['title'=>'User notice','audience'=>'user','body'=>'<script>alert(1)</script>']);
check(in_array((int)$target['id'],visibleIds($user),true) && !in_array((int)$target['id'],visibleIds($owner),true),'role targeting enforced server-side');
$page=request($userClient,'/announcements');
check(str_contains($page['body'],'&lt;script&gt;alert(1)&lt;/script&gt;') && !str_contains($page['body'],'<script>alert(1)</script>'),'announcement body renders as safe plain text');
$dashboard=request($userClient,'/dashboard');
check(str_contains($dashboard['body'],'User notice'),'active targeted banner appears on dashboard');
request($ownerClient,'/announcements/dismiss',['csrf'=>$ownerCsrf,'id'=>$target['id'],'version'=>$target['version']]);
check((int)$pdo->query('SELECT COUNT(*) FROM announcement_dismissals')->fetchColumn()===0,'cannot dismiss another audience notice');
request($userClient,'/announcements/dismiss',['csrf'=>'invalid','id'=>$target['id'],'version'=>$target['version']]);
check(in_array((int)$target['id'],visibleIds($user),true),'invalid CSRF cannot dismiss');
request($userClient,'/announcements/dismiss',['csrf'=>$userCsrf,'id'=>$target['id'],'version'=>$target['version']]);
check(!in_array((int)$target['id'],visibleIds($user),true) && in_array((int)$target['id'],visibleIds($user,false),true),'dismissal hides banner but retains inbox notice');
request($userClient,'/announcements/dismiss',['csrf'=>$userCsrf,'id'=>$target['id'],'version'=>$target['version'],'restore'=>'1']);
check(in_array((int)$target['id'],visibleIds($user),true),'user can restore dismissed banner');
request($userClient,'/announcements/dismiss',['csrf'=>$userCsrf,'id'=>$target['id'],'version'=>$target['version']]);
$updated=saveAnnouncement(['id'=>$target['id'],'version'=>$target['version'],'audience'=>'user','title'=>'Updated notice']);
check((int)$updated['version']===(int)$target['version']+1 && in_array((int)$target['id'],visibleIds($user),true),'edited version resurfaces after dismissal');
$stale=request($ownerClient,'/owner/announcements/save',announcementPayload(['id'=>$target['id'],'version'=>$target['version'],'title'=>'Stale overwrite']));
check($stale['status']===422,'stale edit rejected');
$locked=saveAnnouncement(['kind'=>'critical','pinned'=>'1','dismissible'=>'0','title'=>'Required notice']);
request($userClient,'/announcements/dismiss',['csrf'=>$userCsrf,'id'=>$locked['id'],'version'=>$locked['version']]);
check(in_array((int)$locked['id'],visibleIds($user),true),'non-dismissible notice cannot be dismissed');
check(visibleIds($user)[0]===(int)$locked['id'],'pinned notice ranks first');
request($userClient,'/owner/announcements/archive',['csrf'=>$userCsrf,'id'=>$updated['id'],'version'=>$updated['version']]);
check(in_array((int)$updated['id'],visibleIds($user),true),'non-owner cannot archive');
request($ownerClient,'/owner/announcements/archive',['csrf'=>$ownerCsrf,'id'=>$updated['id'],'version'=>$target['version']]);
check(in_array((int)$updated['id'],visibleIds($user),true),'stale archive rejected');
request($ownerClient,'/owner/announcements/archive',['csrf'=>$ownerCsrf,'id'=>$updated['id'],'version'=>$updated['version']]);
check(!in_array((int)$updated['id'],visibleIds($user),true),'archived notice hidden');
$q=$pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE action='announcement_archived' AND JSON_EXTRACT(meta_json,'$.announcement_id')=?");$q->execute([$updated['id']]);
check((int)$q->fetchColumn()===1,'archive audit retained');
for($i=0;$i<26;$i++) Announcements::save($owner,announcementPayload(['title'=>'Paging '.$i]));
check(count(Announcements::visible($user,true))===5,'dashboard banner count bounded');
check(request($ownerClient,'/owner/announcements?page=2')['status']===200,'owner pagination renders');
$inbox=request($userClient,'/announcements?page=2');
check($inbox['status']===200 && str_contains($inbox['body'],'Paging'),'inbox pagination exposes older active notices');

echo "Announcement integration complete.\n";
