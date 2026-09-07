लिए cron हर 1-5 minute चलाना बेहतर है:

| php /home/CPANEL_USER/path/to/teamdark-panel/bin/expire-keys.php |
|------------------------------------------------------------------|

cPanel: Cron Jobs -\> command paste करें -\> Every 5 Minutes चुन सकते हैं।

## HTTPS

- APP_URL में https:// ही रखें.

- Certificate expired/invalid होने पर Telegram webhook fail हो सकता है.

- HTTP -\> HTTPS redirect enabled रखें, लेकिन webhook endpoint पर redirect
  chain से बचें; direct HTTPS URL सही route पर resolve होना चाहिए.

## Cloudflare / cache

- /connect -\> Cache bypass / no-store.

- /telegram/webhook -\> Cache bypass / no-store.

- इन dynamic endpoints को “Cache Everything” rule में include न करें.

- अगर WAF Telegram webhook को block करे तो पहले Cloudflare Security Events
  देखकर exact rule identify करें; पूरे domain की security बंद न करें.

# 18. Security hardening

- .env permission 600 या hosting-supported restrictive permission.

- Database user को केवल panel database की जरूरत के permissions दें; root
  credentials app में न रखें.

- APP_KEY, bot token, webhook secret और native secret GitHub में commit न
  करें.

- PHP display_errors=Off production में; errors server log में रखें.

- Panel और PHP/MySQL packages patched रखें.

- HTTPS force करें.

- Owner Telegram Chat ID verify करें.

- Webhook secret कम-से-कम 24 random bytes का रखें.

- Bot token leak हो तो BotFather से तुरंत revoke/regenerate करके .env update
  और webhook re-run करें.

- Referral और link codes short-lived/one-time semantics के अनुसार ही use
  करें.

- Backups encrypted/private रखें; database backup में keys/users metadata हो
  सकता है.

## Already implemented protections

- CSRF verification on panel POST actions.

- Prepared statements for DB queries.

- Rate limiting on sensitive routes.

- Webhook secret header verification.

- Telegram update replay de-duplication.

- Private chat validation.

- Audit logs for important actions.

- Key encryption at rest plus hashed lookup.

- Atomic first-use/device transactions.

# 19. Testing और health checks

## Local/server syntax checks

| find teamdark-panel -type f -name '\*.php' -print0 \| xargs -0 -n1 php -l |
|---------------------------------------------------------------------------|

## Database quick checks

<table>
<colgroup>
<col style="width: 100%" />
</colgroup>
<thead>
<tr class="header">
<th>mysql -u teamdark_user -p teamdark_panel -e "SHOW TABLES;"<br />
mysql -u teamdark_user -p teamdark_panel -e "SHOW COLUMNS FROM users
LIKE 'telegram_chat_id';"<br />
mysql -u teamdark_user -p teamdark_panel -e "SHOW TABLES LIKE
'telegram_users';"</th>
</tr>
</thead>
<tbody>
</tbody>
</table>

## Webhook health

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

## GitHub Actions checks

- TeamDark Panel PHP Lint - PASS होना चाहिए.

- TeamDark Native Auth Contract - PASS होना चाहिए.

- 19-case native auth integration suite - PASS.

- Telegram guest-key/link contract - PASS.

- Single SQL install/upgrade contract - PASS.

<table>
<colgroup>
<col style="width: 100%" />
</colgroup>
<thead>
<tr class="header">
<th><strong>Current branch status<br />
</strong>TeamDarkLoader final configuration commit
ea4bddec260d9d5d89d0116881b38afcd641b7aa पर PHP lint और full contract
workflow pass किए गए थे। Deploy के बाद अपने live server पर भी health checks
जरूर करें।</th>
</tr>
</thead>
<tbody>
</tbody>
</table>

# 20. Troubleshooting

| **Problem**                          | **Check / Fix**                                                                             |
|--------------------------------------|---------------------------------------------------------------------------------------------|
| Panel 500 error                      | .env values, PHP error_log, DB credentials, schema.sql import.                              |
| Valid key -\> Invalid Key            | DB में key मौजूद? user_key whitespace? correct DB/environment?                                 |
| Token mismatch / login fail          | TEAMDARK_AUTH_SECRET main.cpp से exact match.                                                |
| Webhook setup failed                 | APP_URL HTTPS, curl extension, bot token, webhook secret format.                            |
| Bot reply नहीं दे रहा                  | getWebhookInfo last_error_message, /telegram/webhook route, server PHP log.                 |
| Owner menu नहीं दिख रहा               | TELEGRAM_OWNER_CHAT_ID exact private Chat ID verify करें.                                     |
| User link code invalid               | 15-minute expiry, same Chat ID, code exact uppercase/characters.                            |
| Guest को दूसरी 2H key नहीं मिल रही     | Expected: 7-day cooldown check करें; next eligible time TG Users page में.                      |
| TG guest panel user में migrate नहीं हुआ | Same TG Chat ID से /link code verify हुआ या नहीं; telegram_users.linked_user_id check.         |
| getUpdates empty/error               | Outgoing webhook active होने पर getUpdates नहीं चलता; getWebhookInfo या TG Users page use करें. |
| Cloudflare issue                     | Cache bypass + Security Events; dynamic routes cache न करें.                                  |
| SQL duplicate/index error            | Backup restore point रखें; exact error देखें; पुराने migrate SQL mix न करें.                         |

# 21. Final deployment checklist

**☐** Backup DB और files लिया.

**☐** TeamDarkLoader branch की latest teamdark-panel files upload कीं.

**☐** केवल database/schema.sql import किया.

**☐** .env create/update किया और chmod restrictive रखा.

**☐** APP_KEY generated.

**☐** DB credentials test किए.

**☐** TEAMDARK_AUTH_SECRET exact main.cpp से लिया.

**☐** Owner account verify/create किया.

**☐** BotFather bot बनाया और token .env में रखा.

**☐** Webhook secret generated.

**☐** Owner private Chat ID verify करके .env में रखा.

**☐** php bin/set_telegram_webhook.php run किया.

**☐** getWebhookInfo healthy है.

**☐** Owner bot inline menu खुल रहा है.

**☐** Panel Dashboard Telegram link test successful.

**☐** Unregistered TG user 2H key test किया.

**☐** Second guest key 7-day rule से blocked है.

**☐** Referral -\> registration flow test किया.

**☐** Linked TG guest -\> panel ownership migration test किया.

**☐** /connect valid key test किया.

**☐** Device reset/block/unblock/delete test किया.

**☐** Cron expiry configured.

**☐** Cloudflare dynamic routes bypass cache.

**☐** PHP lint और logs clean.

# 22. Quick command sheet

### APP key

| php -r "echo base64_encode(random_bytes(32)), PHP_EOL;" |
|---------------------------------------------------------|

### Webhook secret

| php -r "echo bin2hex(random_bytes(24)), PHP_EOL;" |
|---------------------------------------------------|

### Import single SQL

| mysql -u teamdark_user -p teamdark_panel \< database/schema.sql |
|-----------------------------------------------------------------|

### Create owner

| php bin/create-owner.php teamdarkowner 'StrongPassword12+ChangeMe' |
|--------------------------------------------------------------------|

### Set webhook

| php bin/set_telegram_webhook.php |
|----------------------------------|

### Get webhook info

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

### PHP lint

| find . -type f -name '\*.php' -print0 \| xargs -0 -n1 php -l |
|--------------------------------------------------------------|

### Native /connect smoke test

<table>
<colgroup>
<col style="width: 100%" />
</colgroup>
<thead>
<tr class="header">
<th>curl -X POST "https://teamdarkloader.parallaxserver.online/connect"
\<br />
-H "Content-Type: application/x-www-form-urlencoded" \<br />
--data-urlencode "game=PUBG" \<br />
--data-urlencode "user_key=YOUR_TEST_KEY" \<br />
--data-urlencode "serial=TEST_DEVICE_UUID"</th>
</tr>
</thead>
<tbody>
</tbody>
</table>

# Reference notes

- Repository:
  https://github.com/ZayYadav/KeshavNoRule/tree/TeamDarkLoader/teamdark-panel

- Telegram Bot API: https://core.telegram.org/bots/api

- Telegram webhook guide: https://core.telegram.org/bots/webhooks

<table>
<colgroup>
<col style="width: 100%" />
</colgroup>
<thead>
<tr class="header">
<th><strong>Last note<br />
</strong>Live server पर configuration के बाद real token/secret values को
किसी support screenshot में unmasked न भेजें। Debugging के लिए error message,
route, status code और sanitized logs पर्याप्त होते हैं।</th>
</tr>
</thead>
<tbody>
</tbody>
</table>
