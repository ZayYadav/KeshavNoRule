# TeamDark PHP Panel

Standalone PHP 8.2+ / MySQL control panel. It is intentionally isolated from the Android loader source.

## Features
- Owner / Admin / Reseller / User hierarchy.
- Owner balance is treated as unlimited (∞) in panel logic.
- Owner can assign very large balances to managed users. Database balance uses BIGINT UNSIGNED.
- Referral registration with configurable signup/referrer credit bonuses.
- Balance ledger and server-side role checks.
- License keys are random and AES-256-GCM encrypted at rest.
- First successful key validation starts the license timer.
- Generated keys have configurable days + hours.
- Automatic expiry moves keys into the Expired section.
- Per-key maximum device choices: 10, 20, 30, 50, 100, 500, 1000.
- Device identifiers are never stored raw; a keyed HMAC fingerprint is stored.
- JSON API username/password login returns a short-lived bearer token; API tokens are stored hashed.
- PDO native prepared statements, CSRF protection, strict sessions, rate limiting, audit log, CSP/HSTS/security headers.

## Fresh install
1. Use PHP 8.2+.
2. Set web document root to teamdark-panel/public when possible.
3. Copy .env.example to .env and set database credentials.
4. Generate APP_KEY with: php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"
5. Import database/schema.sql.
6. Create first owner with bin/create-owner.php.
7. Force HTTPS.

## Upgrade an existing database
Before uploading/running the upgraded PHP files, import database/migrate_key_lifecycle.sql once.

It upgrades:
- users.balance -> BIGINT
- balance_ledger.amount -> BIGINT
- license status -> unused / active / disabled / expired
- duration_seconds
- activated_at
- expires_at
- last_used_at
- max_devices
- new license_devices table

## License lifecycle
When a key is generated: status=unused, activated_at=NULL, expires_at=NULL.
On the first successful API validation, activated_at is set, expires_at is calculated from duration, status becomes active, and the first device is bound.
After expires_at, API returns KEY_EXPIRED and the panel moves the key into /keys/expired.

## API
- POST /api/v1/auth/login
- GET /api/v1/me with Authorization: Bearer <token>
- GET /api/v1/licenses with Authorization: Bearer <token>
- POST /api/v1/license/validate
- POST /api/v1/license/activate (alias)

Loader validation JSON:
{"key":"TD-...","device_id":"stable-device-id-from-loader","device_label":"Nothing A001"}

Successful license response includes activated_at, expires_at, duration_seconds, max_devices, used_devices, remaining_devices and server_time.
Failure codes: INVALID_KEY, INVALID_DEVICE, KEY_DISABLED, KEY_EXPIRED, DEVICE_LIMIT.

## Important loader integration
The panel alone cannot know that a player entered a key inside the Android loader.
For first-use timing and device binding to start from Android key entry, the loader login flow must call POST /api/v1/license/validate with the entered key and a stable device identifier.
Until that wiring is added, panel generation/management works, but Android key entry will not start the timer.

## Production notes
- Keep .env outside public document root when hosting allows it.
- Use a dedicated least-privilege DB user.
- Back up MySQL before running migrations.
- Rotate APP_KEY only with a migration plan because license ciphertext/device fingerprints depend on it.
- Set PHP display_errors=Off; log errors server-side.
- Keep PHP/MySQL/web server patched and force HTTPS.
