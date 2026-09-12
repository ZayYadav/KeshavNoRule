**TEAM DARK**

**Panel + Telegram Bot + Native Loader**

**पूर्ण कॉन्फ़िगरेशन और डिप्लॉयमेंट गाइड**

Repository: ZayYadav/KeshavNoRule  
Branch: TeamDarkLoader  
Panel folder: teamdark-panel  
Target domain: https://teamdarkloader.parallaxserver.online

<table>
<colgroup>
<col style="width: 100%" />
</colgroup>
<thead>
<tr class="header">
<th><strong>इस PDF का उद्देश्य<br />
</strong>यह गाइड fresh install और existing server upgrade दोनों के लिए है।
इसमें single SQL, .env, Owner account, Native Loader secret, Telegram
BotFather, webhook, Chat ID linking, TG guest keys, referral flow,
security, cron, Cloudflare और troubleshooting सभी steps शामिल हैं।</th>
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
<th><strong>महत्वपूर्ण सुरक्षा नियम<br />
</strong>Bot token, APP_KEY, TEAMDARK_AUTH_SECRET, database password और
webhook secret कभी GitHub, screenshot, Telegram message या public log में
paste न करें। इस PDF में भी real secret values जानबूझकर नहीं दी गई हैं।</th>
</tr>
</thead>
<tbody>
</tbody>
</table>

# विषय-सूची

- 1\. सिस्टम क्या करता है

- 2\. Server requirements

- 3\. Backup और upload structure

- 4\. Database - केवल एक schema.sql

- 5\. .env की पूरी configuration

- 6\. APP_KEY और Native Loader secret

- 7\. पहला Owner बनाना

- 8\. Telegram BotFather setup

- 9\. Owner Chat ID निकालना

- 10\. Telegram webhook activate करना

- 11\. Panel user का Telegram linking

- 12\. Bot में registered user flow

- 13\. Unregistered TG user - 2H/7-day rule

- 14\. Bot Owner powers

- 15\. Referral system

- 16\. Key lifecycle और devices

- 17\. Cron, HTTPS और Cloudflare

- 18\. Security hardening

- 19\. Testing और health checks

- 20\. Troubleshooting

- 21\. Final deployment checklist

- 22\. Quick command sheet

<table>
<colgroup>
<col style="width: 100%" />
</colgroup>
<thead>
<tr class="header">
<th><strong>Recommended order<br />
</strong>पहले backup -&gt; files upload -&gt; schema.sql -&gt; .env -&gt;
owner -&gt; Native secret -&gt; Telegram token/Chat ID -&gt; webhook
-&gt; tests. बीच में webhook पहले set कर देने पर Chat ID निकालने में confusion
हो सकता है, इसलिए नीचे दिए क्रम को follow करें।</th>
</tr>
</thead>
<tbody>
</tbody>
</table>

# 1. सिस्टम क्या करता है

- **Panel roles:** Owner / Admin / Reseller / User.

- **Keys:** Custom key या auto format Team-Dark-XXXXXXXXX; first valid
  Loader login पर timer start.

- **Key controls:** Copy, Block/Unblock, device Reset, Delete; owner को
  global control.

- **Referral:** Owner Admin/Reseller/User invite बना सकता है; Admin केवल
  User invite; User/Reseller invite नहीं बना सकते.

- **Telegram linking:** Panel में Chat ID डालने के बाद 15-minute one-time
  TDLINK code से exact Telegram account verify होता है.

- **Registered bot users:** Account details, recent keys, 1/7/30-day key
  generation normal panel balance rules के साथ.

- **Unregistered TG users:** हर 7 दिन में केवल 1 free 2-hour key, 1 device.

- **Owner-only TG Users page:** Unregistered Telegram name, Chat ID,
  username, first/last seen, eligibility और guest keys.

- **Auto migration:** TG guest बाद में panel account link करे तो उसके पुराने
  TG guest keys panel user ownership में चले जाते हैं.

## Architecture overview

| **Component**      | **Route/File**               | **काम**                                            |
|--------------------|------------------------------|----------------------------------------------------|
| Web Panel          | public/index.php             | Login, Dashboard, Keys, Users, Referrals, TG Users |
| Native Loader Auth | POST /connect                | game + user_key + serial validate करता है           |
| Telegram Webhook   | POST /telegram/webhook       | Telegram updates और inline buttons handle करता है   |
| Single DB SQL      | database/schema.sql          | Fresh install + existing DB upgrade                |
| Webhook Setup      | bin/set_telegram_webhook.php | Telegram setWebhook API call                       |
| Owner Creation     | bin/create-owner.php         | पहला Owner CLI से बनाता है                           |

# 2. Server requirements

- PHP 8.2 या नया.

- MySQL 8.x recommended.

- PHP extensions: PDO MySQL, OpenSSL, JSON, cURL.

- HTTPS certificate valid होना चाहिए.

- Apache/LiteSpeed rewrite support; cPanel hosting भी supported.

- PHP CLI access Owner creation और webhook setup के लिए बहुत उपयोगी है.

<table>
<colgroup>
<col style="width: 100%" />
</colgroup>
<thead>
<tr class="header">
<th>php -v<br />
php -m | grep -E "pdo_mysql|openssl|json|curl"</th>
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
<th><strong>Telegram requirement<br />
</strong>Telegram webhook URL HTTPS होना चाहिए। setWebhook में
secret_token देने पर Telegram हर webhook request में
X-Telegram-Bot-Api-Secret-Token header भेजता है; panel इसी header को
verify करता है।</th>
</tr>
</thead>
<tbody>
</tbody>
</table>

# 3. Backup और upload structure

Existing live panel upgrade करने से पहले database और current files दोनों का
backup लें।

## SSH backup examples

<table>
<colgroup>
<col style="width: 100%" />
</colgroup>
<thead>
<tr class="header">
<th>mysqldump -u teamdark_user -p teamdark_panel &gt;
teamdark_backup_$(date +%F_%H%M).sql<br />
cp -a teamdark-panel teamdark-panel-backup-$(date +%F_%H%M)</th>
</tr>
</thead>
<tbody>
</tbody>
</table>

## cPanel में

**1.** phpMyAdmin -\> database select -\> Export -\> Quick -\> SQL -\>
Go.

**2.** File Manager में teamdark-panel folder को Compress करके ZIP backup
रखें.

**3.** नई files upload/replace करने के बाद .env को overwrite न करें जब तक आप
जानबूझकर नई .env नहीं बना रहे हों.

## Recommended document root

- Best: domain/subdomain Document Root को teamdark-panel/public पर रखें.

- अगर shared hosting में document root को public पर नहीं कर सकते, root
  .htaccess fallback routes already handle करता है.

- Public routes: /connect और /telegram/webhook को cache नहीं होना चाहिए.

# 4. Database - केवल एक schema.sql

<table>
<colgroup>
<col style="width: 100%" />
</colgroup>
<thead>
<tr class="header">
<th><strong>Single SQL rule<br />
</strong>अब केवल teamdark-panel/database/schema.sql use करना है। Fresh
install और existing DB upgrade दोनों में यही file run करनी है। पुराने अलग
migrate_*.sql files use न करें।</th>
</tr>
</thead>
<tbody>
</tbody>
</table>

## Fresh database बनाना - SSH

<table>
<colgroup>
<col style="width: 100%" />
</colgroup>
<thead>
<tr class="header">
<th>mysql -u root -p<br />
CREATE DATABASE teamdark_panel CHARACTER SET utf8mb4 COLLATE
utf8mb4_unicode_ci;<br />
CREATE USER 'teamdark_user'@'localhost' IDENTIFIED BY
'VERY_STRONG_DB_PASSWORD';<br />
GRANT ALL PRIVILEGES ON teamdark_panel.* TO
'teamdark_user'@'localhost';<br />
FLUSH PRIVILEGES;<br />
EXIT;</th>
</tr>
</thead>
<tbody>
</tbody>
</table>

## Schema import - SSH

<table>
<colgroup>
<col style="width: 100%" />
</colgroup>
<thead>
<tr class="header">
<th>cd /path/to/teamdark-panel<br />
mysql -u teamdark_user -p teamdark_panel &lt; database/schema.sql</th>
</tr>
</thead>
<tbody>
</tbody>
</table>

## Schema import - phpMyAdmin

**1.** cPanel -\> phpMyAdmin -\> teamdark_panel database select करें.

**2.** Import tab खोलें.

**3.** database/schema.sql चुनें.

**4.** Format SQL रहने दें और Import/Go करें.

**5.** कोई red SQL error आए तो screenshot + exact error line save करें;
आधा schema run हुआ मानकर blindly दुबारा tables delete न करें.

## मुख्य tables

| **Table**            | **Purpose**                                        |
|----------------------|----------------------------------------------------|
| users                | Panel accounts + role + balance + telegram_chat_id |
| referral_invites     | One-time registration referrals                    |
| telegram_users       | TG name/chat id/username/guest limit/link state    |
| license_keys         | Panel और Telegram guest keys                       |
| license_devices      | Serial/device binding + reset state                |
| telegram_link_tokens | 15-minute TDLINK verification tokens               |
| telegram_update_ids  | Webhook replay/update de-duplication               |
| balance_ledger       | Credit changes history                             |
| audit_logs           | Security/audit events                              |
| rate_limits          | Server-side rate limit buckets                     |

# 5. .env की पूरी configuration

Server पर .env बनाएं:

<table>
<colgroup>
<col style="width: 100%" />
</colgroup>
<thead>
<tr class="header">
<th>cd /path/to/teamdark-panel<br />
cp .env.example .env<br />
chmod 600 .env</th>
</tr>
</thead>
<tbody>
</tbody>
</table>

## Recommended .env template

<table>
<colgroup>
<col style="width: 100%" />
</colgroup>
<thead>
<tr class="header">
<th>APP_NAME="TeamDark Panel"<br />
APP_URL="https://teamdarkloader.parallaxserver.online"<br />
APP_KEY="PASTE_GENERATED_BASE64_KEY"<br />
<br />
DB_HOST="127.0.0.1"<br />
DB_PORT="3306"<br />
DB_NAME="teamdark_panel"<br />
DB_USER="teamdark_user"<br />
DB_PASS="YOUR_STRONG_DB_PASSWORD"<br />
<br />
SESSION_NAME="TDSESSID"<br />
REFERRER_BONUS="5"<br />
SIGNUP_BONUS="2"<br />
KEY_COST="1"<br />
UNLIMITED_KEY_COST="100"<br />
<br />
TEAMDARK_AUTH_SECRET="EXACT_NATIVE_SECRET_FROM_MAIN_CPP"<br />
API_TOKEN_TTL="86400"<br />
<br />
TELEGRAM_BOT_TOKEN="BOTFATHER_TOKEN"<br />
TELEGRAM_WEBHOOK_SECRET="RANDOM_SECRET_16_TO_256_CHARS"<br />
TELEGRAM_OWNER_CHAT_ID="YOUR_PRIVATE_TELEGRAM_CHAT_ID"</th>
</tr>
</thead>
<tbody>
</tbody>
</table>

## हर variable का मतलब

| **Variable**            | **क्या भरना है / असर**                                        |
|-------------------------|-------------------------------------------------------------|
| APP_NAME                | Panel display name.                                         |
| APP_URL                 | Exact HTTPS base URL; trailing slash न रखें.                  |
| APP_KEY                 | 32 random bytes का base64 value; encryption-related secret. |
| DB\_\*                  | MySQL connection settings.                                  |
| SESSION_NAME            | PHP session cookie name.                                    |
| REFERRER_BONUS          | Referral successful होने पर referrer credit.                 |
| SIGNUP_BONUS            | Referral से बने नए account का opening bonus.                  |
| KEY_COST                | Non-owner timed key का प्रति started 24h credit cost.        |
| UNLIMITED_KEY_COST      | Non-owner unlimited validity key fixed cost.                |
| TEAMDARK_AUTH_SECRET    | Loader main.cpp के current native auth secret से exact match. |
| API_TOKEN_TTL           | Legacy/API bearer token lifetime seconds.                   |
| TELEGRAM_BOT_TOKEN      | BotFather से मिला bot token.                                 |
| TELEGRAM_WEBHOOK_SECRET | Webhook request header verification secret.                 |
| TELEGRAM_OWNER_CHAT_ID  | जिस private Chat ID को bot Owner powers देगा.                |

# 6. APP_KEY और Native Loader secret

## APP_KEY generate करें

| php -r "echo base64_encode(random_bytes(32)), PHP_EOL;" |
|---------------------------------------------------------|

Output को APP_KEY में paste करें। इसे rotate करने से पहले migration plan होना
चाहिए, क्योंकि encrypted data इससे depend कर सकता है।

## Telegram webhook secret generate करें

| php -r "echo bin2hex(random_bytes(24)), PHP_EOL;" |
|---------------------------------------------------|

## TEAMDARK_AUTH_SECRET कहाँ से लेना है

Repository branch TeamDarkLoader में app/src/main/jni/main.cpp खोलें।
Current native auth line में auth += oxorany("...") के अंदर जो exact secret
है, वही server .env में TEAMDARK_AUTH_SECRET होना चाहिए।

<table>
<colgroup>
<col style="width: 100%" />
</colgroup>
<thead>
<tr class="header">
<th><strong>Secret leak से बचें<br />
</strong>Secret value को PDF, GitHub issue, Telegram chat, browser
