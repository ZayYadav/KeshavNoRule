# No Rule Panel

Standalone PHP control panel for license/key management, users, referrals, Telegram integration, 2FA, owner controls, file delivery, app APIs and the existing native `/connect` contract.

## Branch layout

This `NORULEPANNEL` branch is panel-only. Android loader/app source, Gradle projects, signing files and other loader code are intentionally not included.

## Branding

Default product branding is **No Rule Panel** with the `NR` mark and `No-Rule-` generated key prefix. Owner rebranding controls can still override supported display values at runtime.

Some non-user-facing compatibility identifiers are intentionally retained internally so existing database/API/client integrations continue to work without changing the request/response contract.

## Requirements

- PHP 8.2+
- PDO MySQL
- OpenSSL
- JSON
- cURL
- MySQL/MariaDB
- HTTPS in production

## Install

1. Point the web server document root to `public/`.
2. Copy `.env.example` to `.env`.
3. Set `APP_URL`, database credentials, `APP_KEY` and `NORULE_AUTH_SECRET`.
4. Import `database/schema.sql`.
5. Ensure `storage/` is writable by the PHP/web-server user where required.
6. Keep `.env` and server secrets outside public web access.

Generate an application key:

```bash
php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"
```

## Native connect contract

The existing `/connect` integration remains compatible:

- Method: `POST`
- Content-Type: `application/x-www-form-urlencoded`
- Fields: `game`, `user_key`, `serial`

The rebrand does not intentionally alter the native response contract.

## Telegram

Configure these values only on the server:

- `TELEGRAM_BOT_TOKEN`
- `TELEGRAM_WEBHOOK_SECRET`
- `TELEGRAM_OWNER_CHAT_ID`

High-risk Telegram mutations, sensitive reads, linked-user key generation and guest keys remain opt-in through `.env` security switches.

## Security defaults

The panel keeps CSRF/session protections, prepared database operations, HTTPS enforcement, rate limiting, audit logs, 2FA support and owner-only controls from the source panel. Never commit production secrets or a real `.env` file.
