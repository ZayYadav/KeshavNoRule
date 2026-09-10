<?php
declare(strict_types=1);

use TeamDark\Panel\{Config,Database,Security,PanelControl,KeyManager,Crypto,Auth};

$root = dirname(__DIR__);
foreach (['Config','Database','Security','Crypto','Auth','KeyManager'] as $file) require $root.'/app/'.$file.'.php';
Config::load($root);
if (Config::get('db_name') !== 'teamdark_test') throw new RuntimeException('Run only against isolated teamdark_test database.');
$pdo = Database::pdo();
$base = getenv('BASE_URL') ?: 'http://127.0.0.1:8080';
$checks = 0;
function check(bool $condition, string $message): void {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
    echo "PASS: $message\n";
}
function client() { $c = curl_init(); curl_setopt($c, CURLOPT_COOKIEFILE, ''); return $c; }
function request($c, string $path, ?array $post = null, array $headers = []): array {
    global $base;
    curl_setopt_array($c, [CURLOPT_URL=>$base.$path,CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>20,CURLOPT_HTTPHEADER=>$headers]);
    if ($post !== null) { curl_setopt($c, CURLOPT_POST, true); curl_setopt($c, CURLOPT_POSTFIELDS, http_build_query($post)); }
    else curl_setopt($c, CURLOPT_HTTPGET, true);
    $body = curl_exec($c);
    if ($body === false) throw new RuntimeException(curl_error($c));
    return ['status'=>(int)curl_getinfo($c,CURLINFO_RESPONSE_CODE),'body'=>$body];
}
function token(string $html): string {
    if (!preg_match('/name="csrf" value="([a-f0-9]+)"/', $html, $match)) throw new RuntimeException('CSRF field missing');
    return $match[1];
}
function login($c, string $username): string {
    $page = request($c,'/login');
    $result = request($c,'/login',['csrf'=>token($page['body']),'username'=>$username,'password'=>'ContractTest@12345']);
    check($result['status']===303,'login '.$username);
    $page = request($c,'/dashboard');
    check($page['status']===200,'dashboard '.$username);
    return token($page['body']);
}
function saveSettings($client, string $csrf, array $changes): void {
    $s = PanelControl::settings();
    $payload = ['csrf'=>$csrf,'revision'=>$s['revision'],'panel_online'=>'1','registration_open'=>'1','generation_open'=>'1','message'=>'Contract maintenance','announcement'=>''];
    $result = request($client,'/owner/settings',array_replace($payload,$changes));
    check($result['status']===303,'settings request accepted');
}

$owner = $pdo->query("SELECT * FROM users WHERE username='contract-owner'")->fetch();
if (!$owner) throw new RuntimeException('Run seed_native_auth.php first.');
$pdo->prepare("INSERT INTO users(name,username,password_hash,role,balance,referral_code,status) VALUES('History Test','history-test',?,'user',100,'TDHISTORYTEST','active')")->execute([Security::passwordHash('ContractTest@12345')]);
$uid = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO user_app_access(user_id,app_id,granted_by,source) VALUES(?,1,?,'test_fixture')")
    ->execute([$uid, (int)$owner['id']]);
$q = $pdo->prepare('SELECT * FROM users WHERE id=?'); $q->execute([$uid]); $user = $q->fetch();
$ownerClient = client(); $userClient = client(); $guest = client();
try {
    $ownerCsrf = login($ownerClient,'contract-owner');
    $userCsrf = login($userClient,'history-test');
    foreach (['/users','/keys','/owner/users','/owner/settings','/activity','/telegram-users'] as $path) {
        $response = request($ownerClient,$path);
        if ($response['status'] !== 200 && preg_match('/class="alert">(.*?)<\/div>/s', $response['body'], $error)) {
            echo 'Route error: '.html_entity_decode(strip_tags($error[1]))."\n";
        }
        check($response['status']===200 && !str_contains($response['body'],'Warning:'),'owner route '.$path);
    }

    $premiumDashboard = request($ownerClient,'/dashboard');
    check(
        $premiumDashboard['status']===200
        && str_contains($premiumDashboard['body'],'data-teamdark-ui="7"')
        && str_contains($premiumDashboard['body'],'class="fx-grid"')
        && str_contains($premiumDashboard['body'],'class="cursor-aura"')
        && str_contains($premiumDashboard['body'],'/assets/app.css?')
        && str_contains($premiumDashboard['body'],'/assets/themes.css?')
        && str_contains($premiumDashboard['body'],'/assets/owner-tools.js?')
        && str_contains($premiumDashboard['body'],'/assets/app.js?'),
        'current premium UI shell and assets render on dashboard'
    );

    $premiumLogin = request(client(),'/login');
    check(
        $premiumLogin['status']===200
        && str_contains($premiumLogin['body'],'data-teamdark-ui="7"')
        && str_contains($premiumLogin['body'],'auth-layout')
        && str_contains($premiumLogin['body'],'/assets/themes.js?')
        && str_contains($premiumLogin['body'],'/assets/owner-tools.js?'),
        'current premium guest authentication shell renders'
    );

    $splashSettings = PanelControl::settings();
    $splashSave = request($ownerClient,'/owner/settings',[
        'csrf'=>$ownerCsrf,
        'revision'=>$splashSettings['revision'],
        'panel_online'=>'1',
        'registration_open'=>'1',
        'generation_open'=>'1',
        'message'=>'Contract maintenance',
        'splash_present'=>'1',
        'splash_enabled'=>'1',
        'splash_title'=>'TEAM DARK CONTRACT',
        'splash_subtitle'=>'Premium opening experience',
        'splash_duration_ms'=>'1400',
    ]);
    check($splashSave['status']===303,'owner can enable global splash');

    $splashGuest = request(client(),'/login');
    check(
        $splashGuest['status']===200
        && str_contains($splashGuest['body'],'data-site-splash')
        && str_contains($splashGuest['body'],'TEAM DARK CONTRACT')
        && str_contains($splashGuest['body'],'data-splash-duration="1400"'),
        'enabled splash renders for guest web pages'
    );

    $activeSplashSettings = PanelControl::settings();
    $splashVersion = (string)$activeSplashSettings['splash_version'];
    $seenSplash = request(
        client(),
        '/login',
        null,
        ['Cookie: TD_SPLASH='.$splashVersion]
    );
    check(
        !str_contains($seenSplash['body'],'data-site-splash'),
        'seen splash version does not replay on internal browsing session'
    );

    $splashSettings = PanelControl::settings();
    $splashOff = request($ownerClient,'/owner/settings',[
        'csrf'=>$ownerCsrf,
        'revision'=>$splashSettings['revision'],
        'panel_online'=>'1',
        'registration_open'=>'1',
        'generation_open'=>'1',
        'message'=>'Contract maintenance',
        'splash_present'=>'1',
        'splash_title'=>'TEAM DARK CONTRACT',
        'splash_subtitle'=>'Premium opening experience',
        'splash_duration_ms'=>'1400',
    ]);
    check($splashOff['status']===303,'owner can disable global splash');
    check(
        !str_contains(request(client(),'/login')['body'],'data-site-splash'),
        'disabled splash is not rendered'
    );

    $pdo->prepare(
        "INSERT INTO telegram_users(
            chat_id,first_name,last_name,username,language_code,linked_user_id,
            first_seen_at,last_seen_at
         ) VALUES(7000001111,'Linked','Broadcast','linked-broadcast','en',?,NOW(),NOW())
         ON DUPLICATE KEY UPDATE linked_user_id=VALUES(linked_user_id),last_seen_at=NOW()"
    )->execute([$uid]);

    $pdo->exec(
        "INSERT INTO telegram_users(
            chat_id,first_name,last_name,username,language_code,linked_user_id,
            first_seen_at,last_seen_at
         ) VALUES(7000002222,'Guest','Broadcast','guest-broadcast','en',NULL,NOW(),NOW())
         ON DUPLICATE KEY UPDATE linked_user_id=NULL,last_seen_at=NOW()"
    );

    $broadcast = request($ownerClient,'/owner/announcements/create',[
        'csrf'=>$ownerCsrf,
        'announcement'=>'Contract broadcast announcement',
        'audience_panel'=>'1',
        'audience_linked'=>'1',
        'audience_guests'=>'1',
    ]);
    check($broadcast['status']===303,'owner announcement publish accepted');

    $broadcastId = (int)$pdo->query(
        "SELECT id FROM announcement_broadcasts ORDER BY id DESC LIMIT 1"
    )->fetchColumn();
    check($broadcastId>0,'announcement broadcast persisted');

    $recipientQ = $pdo->prepare(
        "SELECT COUNT(*) FROM announcement_recipients
         WHERE broadcast_id=? AND chat_id IN (7000001111,7000002222)"
    );
    $recipientQ->execute([$broadcastId]);
    check((int)$recipientQ->fetchColumn()===2,'linked and free Telegram audiences queued');

    check(
        PanelControl::settings()['announcement']==='Contract broadcast announcement',
        'panel audience receives live announcement banner'
    );

    $settingsPage = request($ownerClient,'/owner/alerts');
    check(
        $settingsPage['status']===200
        && str_contains($settingsPage['body'],'DELIVERY ENGINE')
        && str_contains($settingsPage['body'],'Contract broadcast announcement'),
        'premium broadcast center renders live announcement and history'
    );

    $clearAnnouncement = request($ownerClient,'/owner/announcements/clear',[
        'csrf'=>$ownerCsrf,
    ]);
    check($clearAnnouncement['status']===303,'owner can clear panel announcement');
    check(PanelControl::settings()['announcement']==='','panel announcement clear persisted');
    $registerClient = client();
    $registrationRef = 'TDREGREVIEWTEST';
    $pdo->prepare(
        "INSERT INTO referral_invites(code,created_by,role,status)
         VALUES(?,?,'user','pending')"
    )->execute([$registrationRef, $owner['id']]);

    $registrationPage = request($registerClient,'/register?ref='.$registrationRef);
    $registrationPost = request($registerClient,'/register',[
        'csrf'=>token($registrationPage['body']),
        'referral'=>$registrationRef,
        'name'=>'Review Flow User',
        'username'=>'review-flow-user',
        'password'=>'ReviewFlow@12345',
    ]);
    check($registrationPost['status']===303,'referral registration redirects to review');
    $registrationReview = request($registerClient,'/register/success');
    check(
        $registrationReview['status']===200
        && str_contains($registrationReview['body'],'data-registration-countdown="15"')
        && str_contains($registrationReview['body'],'@review-flow-user')
        && str_contains($registrationReview['body'],$registrationRef),
        'registration review shows account details and 15 second countdown'
    );

    $delayBlocked = false;
    try {
        Auth::verifyPasswordCredentials(
            'review-flow-user',
            'ReviewFlow@12345',
            'registration-delay-contract'
        );
    } catch (RuntimeException $e) {
        $delayBlocked = str_contains($e->getMessage(), 'activation delay');
    }
    check($delayBlocked,'registration 15 second delay enforced server-side');

    $legacyApi = request($guest,'/api/v1/license/validate',[
        'key'=>'Team-Dark-ContractValidation9',
        'device_id'=>'CONTRACT-DEVICE',
    ]);
    check($legacyApi['status']===404,'legacy public license API disabled by default');

    check(request($guest,'/assets/app.css')['status']===200,'stylesheet served');
    check(request($userClient,'/owner/settings')['status']!==200,'non-owner cannot read controls');
    check(request($userClient,'/activity')['status']!==200,'non-owner cannot read history');
    $before = PanelControl::settings()['revision'];
    request($userClient,'/owner/settings',['csrf'=>$userCsrf,'revision'=>$before]);
    check(PanelControl::settings()['revision']===$before,'non-owner cannot change controls');
    request($ownerClient,'/owner/settings',['csrf'=>'invalid','revision'=>$before]);
    check(PanelControl::settings()['revision']===$before,'CSRF blocks settings mutation');

    $created = KeyManager::create($user,'history fixture',86400,false,1,false);
    check(
        (bool)preg_match('/^Team-Dark-[A-HJ-NP-Z2-9]{16}$/', $created['key']),
        'generated license uses hardened 16-character token'
    );
    $hashQ = $pdo->prepare('SELECT key_hash FROM license_keys WHERE id=?');
    $hashQ->execute([$created['id']]);
    check(
        hash_equals(
            Crypto::licenseLookupHash($created['key']),
            (string)$hashQ->fetchColumn()
        ),
        'new license stores keyed HMAC lookup hash'
    );
    $newConnect = request($guest,'/connect',[
        'game'=>'PUBG',
        'user_key'=>$created['key'],
        'serial'=>'HMAC-CONTRACT-DEVICE',
    ]);
    $newConnectJson = json_decode($newConnect['body'], true);
    check(
        $newConnect['status']===200
        && ($newConnectJson['status'] ?? false)===true,
        'hardened HMAC license authenticates through unchanged Loader endpoint'
    );
    KeyManager::action($user,$created['id'],'delete');
    $q = $pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE user_id=? AND action IN ('license_created','license_delete')");$q->execute([$uid]);
    check((int)$q->fetchColumn()===2,'creation and deletion both retained');
    $insights = request($ownerClient,'/owner/users?user_id='.$uid);
    check($insights['status']===200 && str_contains($insights['body'],'Balance history'),'user keys and credit ledger render');
    check((bool)preg_match('/user \/ active<\/td>\s*<td>1<\/td>\s*<td>0<\/td>/', $insights['body']),'deleted key counted once in creation history');
    for ($i=0;$i<55;$i++) Security::audit($uid,'history_test',['sequence'=>$i]);
    $history = request($ownerClient,'/activity?user_id='.$uid.'&action=history_test&page=2');
    check($history['status']===200 && str_contains($history['body'],'sequence'),'history pagination retrieves older events');
    $filtered = request($ownerClient,'/activity?user_id='.$uid.'&from=2000-01-01&to=2000-01-02');
    check(str_contains($filtered['body'],'No records match'),'history date filters apply on server');
    $foreign = request($ownerClient,'/activity?user_id='.$uid.'&q=%27%20OR%201%3D1');
    check(str_contains($foreign['body'],'No records match'),'search input remains parameterized');

    saveSettings($ownerClient,$ownerCsrf,['generation_open'=>'0']);
    try { KeyManager::create($user,'blocked',86400,false,1,false); check(false,'generation paused'); }
    catch (RuntimeException $e) { check(str_contains($e->getMessage(),'paused'),'non-owner generation paused'); }
    try { KeyManager::createTelegramGuestKey($owner,1); check(false,'guest generation paused'); }
    catch (RuntimeException $e) { check(str_contains($e->getMessage(),'paused'),'guest generation paused'); }
    $ownerKey = KeyManager::create($owner,'owner bypass',86400,false,1,false);
    check($ownerKey['id']>0,'owner generation remains available');
    $pdo->prepare('INSERT INTO api_tokens(user_id,token_hash,expires_at) VALUES(?,?,DATE_ADD(NOW(),INTERVAL 1 DAY))')->execute([$uid,hash('sha256',str_repeat('x',48))]);
    saveSettings($ownerClient,$ownerCsrf,['panel_online'=>'0']);
    check(request($userClient,'/dashboard')['status']===503,'existing non-owner session blocked while offline');
    check(request($guest,'/register')['status']===503,'guest registration blocked while offline');
    check(request($ownerClient,'/owner/settings')['status']===200,'owner can restore offline panel');
    check(request($userClient,'/api/v1/me',null,['Authorization: Bearer '.str_repeat('x',48)])['status']===503,'panel API access blocked while offline');
    $connect = request($guest,'/connect', ['game'=>'PUBG']);
    $data = json_decode($connect['body'],true);
    check($connect['status']===200 && ($data['status'] ?? null)===false && ($data['reason'] ?? '')==='Missing Parameters','Loader response contract unchanged while panel offline');
    saveSettings($ownerClient,$ownerCsrf,[]);
    check(request($userClient,'/dashboard')['status']===200,'panel restored for existing user session');
    $revision = PanelControl::settings()['revision'];
    request($ownerClient,'/owner/settings',['csrf'=>$ownerCsrf,'revision'=>$revision-1]);
    check(PanelControl::settings()['panel_online']===true,'stale settings form cannot overwrite newer controls');
    saveSettings($ownerClient,$ownerCsrf,['registration_open'=>'0']);
    $reg = request($guest,'/register');
    request($guest,'/register',['csrf'=>token($reg['body']),'referral'=>'anything','name'=>'Blocked user','username'=>'blocked-registration','password'=>'ContractTest@12345']);
    check((int)$pdo->query("SELECT COUNT(*) FROM users WHERE username='blocked-registration'")->fetchColumn()===0,'closed registration creates no account');
    saveSettings($ownerClient,$ownerCsrf,[]);

    $reset = request($ownerClient,'/users/password',[
        'csrf'=>$ownerCsrf,
        'user_id'=>$uid,
        'password'=>'Replacement@12345',
    ]);
    check($reset['status']===303,'owner password reset accepted');
    check(request($userClient,'/dashboard')['status']===302,'password reset revokes existing web session');

    foreach (['/users/status'=>['action'=>'disable'],'/users/role'=>['role'=>'admin'],'/users/password'=>['password'=>'Replacement@12345']] as $path=>$payload) {
        request($ownerClient,$path,['csrf'=>$ownerCsrf,'user_id'=>$owner['id']]+$payload);
    }
    $q = $pdo->prepare('SELECT role,status,password_hash FROM users WHERE id=?');$q->execute([$owner['id']]);$saved = $q->fetch();
    check($saved['role']==='owner' && $saved['status']==='active' && password_verify('ContractTest@12345',$saved['password_hash']),'owner account protected from self-lockout');
    echo "Owner controls integration: $checks checks passed.\n";
} finally {
    $pdo->exec("UPDATE panel_settings SET settings_json=JSON_OBJECT(),revision=revision+1 WHERE id=1");
}
