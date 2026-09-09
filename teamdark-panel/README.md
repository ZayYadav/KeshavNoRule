# TeamDark PHP Panel

Standalone PHP 8.2+ / MySQL TeamDark control panel with native Loader authentication and Telegram Bot webhook support.

## Main features
- Owner / Admin / Reseller / User hierarchy.
- One-time referral registration. Owner can create Admin / Reseller / User referrals; Admin can create User referrals.
- Self-owned key generation with optional custom key and automatic `Team-Dark-XXXXXXXXX` format.
- First successful Loader login starts the license timer.
- Per-key block/unblock, device reset and delete controls.
- Telegram account linking with Chat ID + 15-minute one-time verification code.
- Telegram Bot owner menu with inline buttons for stats, panel users, TG guests, keys, referrals, balance changes and user enable/disable.
- Responsive owner command center with searchable user directory, role/status filters and bulk account actions.
- Owner user controls for role and balance changes, enable/disable, Telegram disconnect, API-token revoke and secure password reset.
- Owner-only security activity page for sign-in, key and management audit events.
- Revocable one-time referral invites with protected confirmation flows.
- Registered linked users can view account/keys and generate 1/7/30-day keys from the bot using normal panel balance rules.
- Unregistered Telegram users can generate one free 2-hour, 1-device key every 7 days.
- Owner-only `/telegram-users` page shows unregistered TG name, Chat ID, Telegram username, first/last seen, next free-key time and guest keys.
- When a TG guest later links a panel account, the TG record is linked and previous guest keys automatically move under that panel account.
- Webhook secret verification, update replay protection, per-chat rate limits, CSRF protection, prepared statements and audit logging.

## One SQL file only

### Owner console upgrade

- `/activity`: server-side username/IP, actor-or-target user ID, exact action and date filters; pagination across every retained event, with expandable metadata.
- `/owner/users`: per-user key creation totals (current records plus retained creation logs), current/active keys, credit debits, key details and paginated balance history.
- `/owner/settings`: panel maintenance ON/OFF, separate registration and key-generation switches, maintenance text and a site announcement. Settings are shared in MySQL and protected against stale form overwrites.
- Panel OFF blocks non-owner web sessions, panel-account API access and Telegram bot operations. Owners retain access to restore service. Key generation pause also applies to Telegram guests.
- These controls do not power off the hosting machine or alter native `/connect` and license-validation contracts. Existing Loader validation remains available during panel maintenance.
- Sign-ins, page views, submitted operations, key creation/deletion/device actions, Telegram interactions and owner changes appear in history. Key creation/deletion audit records commit with the key changes. Passwords and access tokens are never included.
- History is limited to retained records: events missing from older versions cannot be reconstructed. Guest generation events identify the Telegram user separately from the owner service account.

After uploading **all changed PHP/assets files**, back up MySQL and re-import `database/schema.sql`. Its new `panel_settings` table enables the controls without resetting existing users, keys, settings or history. Until imported, the owner controls page explains the required upgrade and normal panel access continues. Keep the existing `.env`, `APP_KEY`, database credentials and native auth secret.

The MySQL CI workflow also runs `tests/owner_controls_integration.php`, which verifies authorization, CSRF, maintenance recovery, generation pauses, deleted-key history/counts, pagination, filters and the unchanged Loader response during maintenance. It refuses to run against any database except `teamdark_test`.

Use only:

`database/schema.sql`

It is the single fresh-install + existing-database upgrade SQL. Back up MySQL first, then run this same file whenever deploying this build.

Old separate migration SQL files are intentionally removed.

## Fresh install / upgrade
1. Use PHP 8.2+ with PDO MySQL, OpenSSL, JSON and cURL.
2. Copy `.env.example` to `.env`.
3. Configure database and `APP_KEY`.
4. Import `database/schema.sql`.
5. Create the first owner with `bin/create-owner.php` if this is a fresh database.
6. Configure Telegram values in `.env`.
7. Run `php bin/set_telegram_webhook.php`.
8. Force HTTPS.

## Telegram .env
- `TELEGRAM_BOT_TOKEN`: token received from BotFather.
- `TELEGRAM_WEBHOOK_SECRET`: random webhook secret.
- `TELEGRAM_OWNER_CHAT_ID`: private Telegram Chat ID that receives Owner controls.

The webhook URL is `/telegram/webhook`.

Do not expose the bot token or webhook secret in HTML, JavaScript, screenshots or public logs.

## Secure Telegram linking
A panel user enters a private Telegram Chat ID on Dashboard. The panel creates a 15-minute `TDLINK-XXXXXXXX` code. The same Telegram account must send:

`/link TDLINK-XXXXXXXX`

Only then is the account linked. Merely typing another person's Chat ID cannot link that Telegram account.

## Native Loader endpoint
The current native Loader contract remains `POST /connect` with form fields `game`, `user_key`, and `serial`. The existing token and device-binding contract is unchanged.

## Automated tests
- TeamDark Panel PHP Lint
- TeamDark Native Auth Contract
- Native 19-case Loader integration suite
- Telegram guest-key/link contract
- Single-SQL upgrade contract
