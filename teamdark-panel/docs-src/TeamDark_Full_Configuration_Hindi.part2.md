screenshot या public log में paste न करें। केवल server .env में रखें।</th>
</tr>
</thead>
<tbody>
</tbody>
</table>

### Safe search command

| grep -n 'auth.\*oxorany' ../app/src/main/jni/main.cpp |
|-------------------------------------------------------|

## Native token logic

| md5("PUBG-" + user_key + "-" + serial + "-" + TEAMDARK_AUTH_SECRET) |
|---------------------------------------------------------------------|

Server और Loader secret mismatch होने पर valid key भी login नहीं करेगी।

# 7. पहला Owner बनाना

Fresh install में schema import के बाद CLI से Owner बनाएं:

| php bin/create-owner.php teamdarkowner 'StrongPassword12+ChangeMe' |
|--------------------------------------------------------------------|

- Username: 3-32 chars; lowercase letters, numbers, dot, underscore,
  hyphen.

- Password कम-से-कम 12 characters रखें; practical use में 16+ और unique
  password बेहतर है.

- Owner logical unlimited balance use करता है और normal key generation पर
  credit cost 0 है.

- Command generated referral code भी print करता है; नए referral system में
  panel की Users & Referrals screen से one-time invite बनाना preferred है.

<table>
<colgroup>
<col style="width: 100%" />
</colgroup>
<thead>
<tr class="header">
<th><strong>Existing Owner<br />
</strong>अगर database में पहले से active Owner है तो नया Owner blindly create
न करें। पहले users table या panel login से verify करें।</th>
</tr>
</thead>
<tbody>
</tbody>
</table>

# 8. Telegram BotFather setup

**1.** Telegram में official @BotFather खोलें.

**2.** /newbot भेजें.

**3.** Bot का display name चुनें, जैसे TEAM DARK PANEL BOT.

**4.** Unique username चुनें जो bot पर end हो, जैसे TeamDarkPanelBot.

**5.** BotFather जो token देता है उसे server .env के TELEGRAM_BOT_TOKEN में
रखें.

**6.** Optional: /setdescription, /setabouttext और /setuserpic से
branding करें.

**7.** Bot token किसी को forward न करें.

<table>
<colgroup>
<col style="width: 100%" />
</colgroup>
<thead>
<tr class="header">
<th><strong>Bot privacy<br />
</strong>यह implementation private chat को accept करता है। Owner controls
केवल TELEGRAM_OWNER_CHAT_ID match होने पर मिलते हैं।</th>
</tr>
</thead>
<tbody>
</tbody>
</table>

# 9. Owner Chat ID निकालना

## Method A - webhook set करने से पहले getUpdates

**1.** Bot को Telegram में खोलकर Start दबाएं या /start भेजें.

**2.** Server terminal में token को .env से पढ़कर getUpdates call करें.

**3.** JSON में message.from.id या message.chat.id का positive integer
आपका private Chat ID है.

**4.** उसी value को TELEGRAM_OWNER_CHAT_ID में रखें.

<table>
<colgroup>
<col style="width: 100%" />
</colgroup>
<thead>
<tr class="header">
<th>cd /path/to/teamdark-panel<br />
TOKEN="$(php -r '$e=parse_ini_file(".env"); echo
$e["TELEGRAM_BOT_TOKEN"];')"<br />
curl -s "https://api.telegram.org/bot${TOKEN}/getUpdates"</th>
</tr>
</thead>
<tbody>
</tbody>
</table>

<table>
<colgroup>
<col style="width: 100%" />
</colgroup>
<thead>
<tr class="header">
<th><strong>अगर webhook पहले से set है<br />
</strong>Telegram getUpdates outgoing webhook active होने पर काम नहीं
करता। ऐसे में web panel के Owner account से TG Users page देखें: bot start करने
वाला unregistered TG user वहाँ Chat ID सहित दिखेगा। अपनी ID पहचानकर .env
update करें।</th>
</tr>
</thead>
<tbody>
</tbody>
</table>

# 10. Telegram webhook activate करना

## Prerequisites

- APP_URL सही HTTPS domain हो.

- TELEGRAM_BOT_TOKEN valid हो.

- TELEGRAM_WEBHOOK_SECRET 16-256 chars और केवल A-Z, a-z, 0-9, \_ या - हो.

- /telegram/webhook route public HTTPS से reachable हो.

## Webhook set करें

<table>
<colgroup>
<col style="width: 100%" />
</colgroup>
<thead>
<tr class="header">
<th>cd /path/to/teamdark-panel<br />
php bin/set_telegram_webhook.php</th>
</tr>
</thead>
<tbody>
</tbody>
</table>

Expected output:

| Webhook configured: https://teamdarkloader.parallaxserver.online/telegram/webhook |
|-----------------------------------------------------------------------------------|

## Webhook verify करें

<table>
<colgroup>
<col style="width: 100%" />
</colgroup>
<thead>
<tr class="header">
<th>TOKEN="$(php -r '$e=parse_ini_file(".env"); echo
$e["TELEGRAM_BOT_TOKEN"];')"<br />
curl -s "https://api.telegram.org/bot${TOKEN}/getWebhookInfo"</th>
</tr>
</thead>
<tbody>
</tbody>
</table>

- url आपके domain के /telegram/webhook पर होना चाहिए.

- pending_update_count लगातार बढ़ रहा हो तो webhook response/server error
  check करें.

- last_error_message आए तो HTTPS, rewrite, PHP error log और webhook
  secret check करें.

<table>
<colgroup>
<col style="width: 100%" />
</colgroup>
<thead>
<tr class="header">
<th><strong>Official Telegram behavior<br />
</strong>setWebhook HTTPS POST updates भेजता है। secret_token configured
होने पर X-Telegram-Bot-Api-Secret-Token header आता है; TeamDark webhook इसे
hash-safe comparison से verify करता है।</th>
</tr>
</thead>
<tbody>
</tbody>
</table>

# 11. Panel user का Telegram linking

**1.** User panel में login करे.

**2.** Dashboard पर Telegram Chat ID field में अपना private Chat ID डाले.

**3.** Generate verification code दबाए.

**4.** Panel 15-minute TDLINK-XXXXXXXX code दिखाएगा.

**5.** उसी exact Telegram account से bot को /link TDLINK-XXXXXXXX भेजें.

**6.** Bot successful link confirm करेगा और Dashboard पर Telegram
“Verified & linked” दिखेगा.

<table>
<colgroup>
<col style="width: 100%" />
</colgroup>
<thead>
<tr class="header">
<th><strong>Security design<br />
</strong>सिर्फ Chat ID type करने से account link नहीं होता। Same Telegram
account से one-time code verify करना जरूरी है, इसलिए किसी दूसरे व्यक्ति का Chat
ID डालकर उसका bot account hijack नहीं किया जा सकता।</th>
</tr>
</thead>
<tbody>
</tbody>
</table>

## Unlink

Dashboard पर Unlink Telegram दबाने से users.telegram_chat_id clear होता है
और telegram_users linked_user_id हटता है। Guest history record बनी रह
सकती है, लेकिन account link समाप्त हो जाता है।

# 12. Bot में registered user flow

- My Account - name, username, role, balance, Chat ID, key count.

- My Keys - recent keys दिखाता है.

- 1 Day / 7 Days / 30 Days - normal panel pricing/balance rules से key
  generate करता है.

- Generated key user के panel account की self-owned key होती है.

- Key timer first valid Loader login पर start होता है.

<table>
<colgroup>
<col style="width: 100%" />
</colgroup>
<thead>
<tr class="header">
<th><strong>Balance rule<br />
</strong>Bot registered user को panel rules bypass नहीं करने देता।
Non-owner के credits कम हों तो normal key generation fail होगी।</th>
</tr>
</thead>
<tbody>
</tbody>
</table>

# 13. Unregistered TG user - 2H / 7-day rule

- Bot start करते ही telegram_users में Chat ID, first name, last name,
  username, first_seen_at और last_seen_at record होता है.

- Unregistered TG user “Free 2H Key” दबा सकता है.

- Key duration: 7200 seconds (2 hours).

- Device limit: 1.

- Eligibility: एक free key लेने के बाद अगले 7 दिन तक दूसरी free 2H key नहीं.

- 7-day gate database transaction/row lock के साथ enforce होता है, इसलिए
  rapid double-click से दो keys नहीं बननी चाहिए.

- Owner panel -\> TG Users में guest और उसकी keys दिखती हैं.

## जब वही TG guest बाद में panel account link करे

telegram_users.linked_user_id उस panel user से जुड़ता है,
users.telegram_chat_id update होता है, और key_source=telegram_guest वाली
पुरानी guest keys का owner_user_id नए panel user पर migrate होता है।

# 14. Bot Owner powers

Owner access TELEGRAM_OWNER_CHAT_ID से तय होता है। Bot में inline buttons से
ये controls उपलब्ध हैं:

| **Button/Section**  | **Power**                                                                       |
|---------------------|---------------------------------------------------------------------------------|
| Stats               | Panel users, unregistered TG, linked TG, current keys, pending referrals counts |
| Panel Users         | Recent panel users list और per-user details                                     |
| Balance             | +10, +100, -10 adjustments; ledger में entry                                      |
| Enable/Disable User | Non-owner panel account status toggle                                           |
| TG Users            | Unregistered Telegram users और guest keys                                       |
| Keys                | Latest keys और key detail                                                       |
| Key actions         | Block/Unblock, Reset Devices, Delete                                            |
| Referrals           | Admin / Reseller / User one-time referral create                                |
| 30D Key             | Owner 30-day key generate                                                       |

<table>
<colgroup>
<col style="width: 100%" />
</colgroup>
<thead>
<tr class="header">
<th><strong>Owner ID check<br />
</strong>TELEGRAM_OWNER_CHAT_ID गलत व्यक्ति की ID होने पर वही व्यक्ति bot
Owner controls पाएगा। इसलिए Chat ID को दो बार verify करें और .env
permission tight रखें।</th>
</tr>
</thead>
<tbody>
</tbody>
</table>

# 15. Referral system

| **Role** | **किस referral को बना सकता है** |
|----------|--------------------------------|
| Owner    | Admin, Reseller, User          |
| Admin    | User only                      |
| Reseller | कोई referral नहीं               |
| User     | कोई referral नहीं               |

## Registration flow

**1.** Owner/Admin Users & Referrals page या Owner bot से one-time
referral generate करता है.

**2.** नया व्यक्ति /register पर referral code डालता है.

**3.** वह अपना Name, Username और Password खुद सेट करता है.

**4.** Referral successful होने पर status USED होता है और किस user ने use
किया वह दिखता है.

**5.** अगर नया user पहले TG guest था, registration के बाद उसे Dashboard से
same Chat ID verify करके link करना होगा; तब TG data/account merge होगा.

# 16. Key lifecycle और devices

## Auto key format

| Team-Dark-XXXXXXXXX |
|---------------------|

Custom key भी allowed है; duplicate key reject होती है।

## Lifecycle

| **State** | **मतलब**                                    |
|-----------|---------------------------------------------|
| unused    | Generated है; अभी first Loader login नहीं हुआ. |
| active    | First valid login हो चुका; timer चल रहा है.   |
| disabled  | Block किया गया; login reject.               |
| expired   | Validity खत्म.                               |
| revoked   | Revoked state; login reject.                |

## First-use activation

- Generated key: activated_at=NULL, expires_at=NULL.

- First successful /connect: activated_at=server time, status=active,
  expires_at=activated_at+duration.

- Unlimited validity में expires_at concept UNLIMITED रहता है.

- Same serial दूसरा device slot consume नहीं करता.

- Reset device active=0 करता है; बाद में वही serial फिर bind हो सकता है अगर
  slot available हो.

## Native endpoint test

<table>
<colgroup>
<col style="width: 100%" />
</colgroup>
<thead>
<tr class="header">
<th>curl -X POST "https://teamdarkloader.parallaxserver.online/connect"
\<br />
-H "Accept: application/json" \<br />
-H "Content-Type: application/x-www-form-urlencoded" \<br />
--data-urlencode "game=PUBG" \<br />
--data-urlencode "user_key=YOUR_TEST_KEY" \<br />
--data-urlencode "serial=TEST_DEVICE_UUID"</th>
</tr>
</thead>
<tbody>
</tbody>
</table>

# 17. Cron, HTTPS और Cloudflare

## Expiry cron

Web/API request expiry enforce करते हैं, लेकिन background status cleanup के
