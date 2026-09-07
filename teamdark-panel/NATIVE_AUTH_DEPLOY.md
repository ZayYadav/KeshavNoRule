# TeamDark Native Loader Auth Deployment

This panel endpoint is aligned with the current native login implementation in:

`app/src/main/jni/main.cpp`

JNI function:

`Java_com_team_dark_TeamDark2_Check`

## Loader contract

Endpoint:

`POST /connect`

Content type:

`application/x-www-form-urlencoded`

Fields:

- `game`
- `user_key`
- `serial`

Current loader sends:

`game=PUBG&user_key=<KEY>&serial=<UUID>`

Success:

```json
{
  "status": true,
  "data": {
    "token": "<lowercase md5>",
    "rng": 1788796800,
    "expired_date": "2026-09-10 18:30:00",
    "exdate": "2026-09-10 18:30:00",
    "EXP": "2026-09-10 18:30:00"
  }
}
```

Unlimited expiry returns:

`"expired_date": "UNLIMITED"`

Failure:

```json
{"status":false,"reason":"Invalid Key"}
```

Other reasons:

- Missing Parameters
- Invalid Game
- Invalid Key
- Key Expired
- Key Disabled
- Key Revoked
- Device Limit Reached
- Invalid Request
- Server Error

Protocol-level auth failures intentionally use HTTP 200 because the existing native loader parses `reason` only after a 2xx response.

## Exact token rule

The server computes:

`md5("PUBG-" + user_key + "-" + serial + "-" + TEAMDARK_AUTH_SECRET)`

The result is lowercase MD5 hex.

`TEAMDARK_AUTH_SECRET` must equal the exact current native value used by the `auth += oxorany(...)` line in TeamDarkLoader `main.cpp`.

Do not put this value in panel HTML, JavaScript or API responses.

## Existing database upgrade

Back up the database first, then run once:

`database/migrate_native_connect.sql`

It adds/aligns:

- game
- duration_seconds
- unlimited_expiry
- activated_at
- expires_at
- last_used_at
- max_devices
- unlimited_devices
- status: unused / active / expired / disabled / revoked
- raw serial device binding
- IP address
- active/reset device state
- unique (license_key_id, serial)

## Server .env

Keep the real `.env` only on the server.

Add:

`TEAMDARK_AUTH_SECRET="<exact-current-native-secret>"`

Pricing:

- `KEY_COST` = credits per started 24-hour period
- `UNLIMITED_KEY_COST` = fixed non-owner unlimited-validity key price

Owner always has logical unlimited balance and pays zero key-generation cost.

## First-use activation

Generated key:

- status = unused
- activated_at = NULL
- expires_at = NULL

First successful `/connect` login atomically sets:

- activated_at = current server time
- status = active
- expires_at = activated_at + duration, unless unlimited
- serial device binding

The license row is locked with `SELECT ... FOR UPDATE`, so simultaneous first-use requests and simultaneous device registration cannot over-allocate device slots.

## Devices

Supported finite limits:

1, 2, 5, 10, 20, 30, 50, 100, 500, 1000

Unlimited devices is also supported.

Same serial:

- does not consume another slot
- updates last_seen and IP

Reset serial:

- active becomes 0
- the same serial can bind again later if a slot is available

Panel route:

`/keys/devices?id=<KEY_ID>`

## Key management

Panel supports:

- disable
- enable
- revoke
- Owner-only delete
- per-device reset
- reset all devices
- expired-key section

Expired section:

`/keys/expired`

## Automatic expiry

Web/API requests always enforce expiry.

For background status cleanup, use cPanel Cron:

```bash
php /home/parallax/teamdarkloader.parallaxserver.online/bin/expire-keys.php
```

Run every 1-5 minutes.

## Apache/LiteSpeed route

Root `.htaccess` maps:

`/connect -> public/connect.php`

If the document root is already `public/`, `public/.htaccess` maps:

`/connect -> connect.php`

## Cache policy

`connect.php` sends:

- Cache-Control: no-store, no-cache, must-revalidate, max-age=0
- Pragma: no-cache
- Surrogate-Control: no-store
- Cloudflare-CDN-Cache-Control: no-store

Also configure Cloudflare to bypass cache for:

`/connect`

## Loader endpoint

The TeamDarkLoader branch now points only the native login URL to:

`https://teamdarkloader.parallaxserver.online/connect`

Request generation, serial calculation, JSON parsing, token comparison and ±60-second RNG validation remain unchanged.

## Curl test

```bash
curl -X POST "https://teamdarkloader.parallaxserver.online/connect" \
  -H "Accept: application/json" \
  -H "Content-Type: application/x-www-form-urlencoded" \
  --data-urlencode "game=PUBG" \
  --data-urlencode "user_key=TEST_KEY" \
  --data-urlencode "serial=TEST_DEVICE_UUID"
```

## Automated tests

Workflow:

`.github/workflows/teamdark-native-auth-contract.yml`

It uses isolated MySQL + PHP and verifies:

1. missing game
2. missing user_key
3. missing serial
4. invalid key
5. new unused key
6. first activation
7. same serial second login
8. new serial
9. device limit
10. expired key
11. disabled key
12. revoked key
13. unlimited expiry
14. unlimited devices
15. server timestamp
16. token equality against the exact current native main.cpp secret
17. malformed JSON POST
18. simultaneous first-use requests
19. simultaneous distinct-device registrations

## Main files

- `public/connect.php`
- `app/LoaderAuthService.php`
- `app/KeyManager.php`
- `app/LicenseService.php`
- `public/index.php`
- `database/schema.sql`
- `database/migrate_native_connect.sql`
- `.htaccess`
- `public/.htaccess`
- `.env.example`
