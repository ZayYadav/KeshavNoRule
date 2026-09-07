# TeamDark PHP Panel

Standalone PHP 8.2+ / MySQL control panel. It is intentionally isolated from the Android loader source.

## Features
- Owner / Admin / Reseller / User hierarchy.
- Each user sees only authorized license keys; Owner sees all keys.
- Referral registration with configurable signup/referrer credit bonuses.
- Balance ledger and server-side role checks.
- License keys are random and encrypted at rest using AES-256-GCM; SHA-256 lookup hash is stored separately.
- JSON API username/password login returns a short-lived bearer token; API tokens are stored hashed.
- PDO native prepared statements, CSRF protection, strict sessions, rate limiting, audit log, CSP/HSTS/security headers.
- Folder/front-controller routing via `public/index.php` + `.htaccess`.

## Install
1. Set web document root to `teamdark-panel/public` (recommended).
2. Copy `.env.example` to `.env` and set database credentials.
3. Generate `APP_KEY`:
   `php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"`
4. Import `database/schema.sql` into MySQL.
5. Create first owner:
   `php bin/create-owner.php ownername 'use-a-long-unique-password'`
6. Force HTTPS at the hosting/reverse-proxy layer.

## API
- `POST /api/v1/auth/login` JSON: `{ "username": "...", "password": "..." }`
- `GET /api/v1/me` header: `Authorization: Bearer <token>`
- `GET /api/v1/licenses` header: `Authorization: Bearer <token>`

## Production notes
- Keep `.env` outside public document root when hosting allows it.
- Use a dedicated least-privilege DB user.
- Back up MySQL and rotate APP_KEY only with a migration plan because license ciphertext depends on it.
- Set PHP `display_errors=Off`; log errors server-side.
- “Secure” is not absolute: patch PHP/MySQL/web server regularly and put the panel behind HTTPS/WAF/rate limits where possible.
