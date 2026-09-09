# TeamDark PHP Panel

Standalone PHP 8.2+ / MySQL TeamDark control panel with native Loader authentication and Telegram Bot webhook support.

## Main features
- Owner / Admin / Reseller / User hierarchy.
- One-time referral registration. Owner can create Admin / Reseller / User referrals; Admin can create User referrals.
- Self-owned key generation with optional custom key and automatic high-entropy `Team-Dark-XXXXXXXXXXXXXXXX` format.
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


## Security deployment hardening
- Prefer the web-server document root to point directly at `teamdark-panel/public`. The parent `app`, `database`, `bin`, `tests`, `vendor`, `storage` and `.env` paths must never be web-accessible.
- Keep `.env` outside public access and restrict filesystem permissions to the hosting account. Never commit the real `.env`.
- Use a dedicated MySQL user for this database; do not use the MySQL root account in production. Keep MySQL port 3306 firewalled from the public internet unless a trusted remote DB connection is explicitly required.
- Keep `display_errors=Off` in production. Detailed failures belong in server logs; browser/API responses intentionally use generic errors.
- Web sessions expire after inactivity, rotate their IDs periodically, and require a fresh sign-in after the configured absolute lifetime. Password changes invalidate older PHP web sessions.
- Keep HTTPS enabled. The panel emits HSTS and no-store headers on UI, API, Loader and Telegram webhook responses.
- Generated license keys use high-entropy randomness. New custom keys must be at least 12 characters; generated keys are preferred.
- Rotate `APP_KEY`, database credentials, Telegram secrets, and the native auth secret if the server `.env` is ever exposed. Rotating `APP_KEY` requires a planned key-data migration because it encrypts stored license material.
- The existing native Loader `/connect` request and response contract is unchanged by these server-side protections.


## Optional Telegram 2FA

Telegram two-factor authentication is optional per user and requires an already verified Telegram link.

### Enable
1. Link Telegram from Dashboard using the existing `TDLINK` verification flow.
2. Open the linked Team Dark bot and send `/2fa` or tap **2FA Setup**.
3. The bot generates a one-time `TD2FA-XXXXXXXXXXXX` activation key valid for 10 minutes.
4. Enter that activation key in Dashboard → Telegram 2FA → Activate.
5. Existing panel API bearer tokens are revoked when 2FA becomes active.

### Login
- Username + password are checked first.
- A successful password check does **not** create an authenticated web session when 2FA is enabled.
- The bot sends an 8-digit, single-use code to the linked Telegram account.
- The code expires after 5 minutes and a challenge allows at most 5 incorrect attempts.
- New login challenges invalidate earlier unused challenges.
- OTP hashes are keyed with `APP_KEY`; plaintext OTP values are never stored in MySQL.
- API password login also returns a 2FA challenge for 2FA-enabled users and requires `POST /api/v1/auth/2fa` before a bearer token is issued.

### Disable / recovery
- A user can disable 2FA from Dashboard only after confirming the current password.
- A user cannot unlink Telegram while 2FA is active.
- Owner controls can reset a user's 2FA or disconnect Telegram for account recovery. Both actions revoke active API tokens.

## Registration review countdown

Successful referral registration now redirects to a TeamDark-themed account review screen. It shows safe account details such as User ID, name, username, assigned role, referral used, signup balance and creation time. Password plaintext is never shown. The **OK / Continue to login** action unlocks after a 15-second countdown.

## Deploying this update

Back up MySQL, deploy the changed PHP/assets, then run the existing single upgrade file again:

`database/schema.sql`

The same SQL is backward-compatible and adds `telegram_2fa_enabled`, `telegram_2fa_enabled_at`, `telegram_2fa_activation_tokens`, and `login_2fa_challenges` without resetting existing users or keys. Existing users start with 2FA disabled. The native Loader `/connect` contract is unchanged.


## Server hardening v2

This hardening layer does not change the native TeamDarkLoader `/connect` URL, form fields, token formula, JSON response contract or Loader C++.

- Every web account now has an `auth_version`. Security-sensitive changes increment it so older PHP sessions immediately fail on their next request.
- 2FA enable/disable/reset, Telegram unlink/recovery, password reset, role changes, status changes, bulk access changes and explicit access revocation revoke older web sessions and API tokens.
- Owner destructive web actions require a login authenticated within the previous 5 minutes. A stale owner session is logged out before the action can run.
- Registration now stores `login_not_before=NOW()+15 seconds`; the countdown is enforced by password authentication on the server, not only JavaScript.
- Password guessing is limited by IP plus a tighter per-account bucket.
- `/api/v1/license/activate` and `/api/v1/license/validate` are disabled by default with `LEGACY_LICENSE_API_ENABLED=false`. The Loader `/connect` endpoint remains available.
- `/api/v1/licenses` masks decrypted license keys by default. Plaintext API output requires both Owner role and the explicit server setting `API_REVEAL_LICENSE_KEYS=true`.
- Telegram Owner mutation callbacks are disabled by default. Read-only Owner views remain available. Set `TELEGRAM_OWNER_MUTATIONS_ENABLED=true` only if the increased Telegram account risk is explicitly accepted.
- Forwarded IP headers are ignored unless the direct peer matches `TRUSTED_PROXY_CIDRS`.
- New custom license keys require 16–80 characters with both letters and numbers. Existing keys remain valid.

### Upgrade

Back up the database and run the same `database/schema.sql` once after deploying. It adds `auth_version` and `login_not_before` without deleting existing accounts or keys. Existing authenticated browser sessions created before this upgrade will be asked to sign in again because they do not contain an auth-version value.
