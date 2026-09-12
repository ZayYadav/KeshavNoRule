# No Rule Panel File Manager & Owner System

The panel includes an authenticated `/files` File Manager for `.so` and `.zip` files plus the Owner System console.

## Storage model

- Files are stored under `storage/private_uploads/u<USER_ID>/`.
- Original upload names are not used as raw disk object names.
- Direct web access to `storage/` is denied.
- Downloads go through authenticated panel controllers.
- Non-owner quotas and Owner-wide management remain enforced by the existing panel logic.

## Database

The File Manager tables are included in:

```text
database/schema.sql
```

Apply the current schema during deployment/upgrade with a database account that has the required schema permissions.

## Filesystem permissions

PHP must be able to create and write files below:

```text
storage/private_uploads/
```

Use the same user/group as the PHP-FPM or hosting account where possible. Do not make storage publicly browsable.

## Upload limits

The repository includes `.user.ini` configuration for common PHP-FPM/CGI hosting layouts. Hosting/account-level limits may still override these values, so verify the active PHP configuration if large uploads fail.

## Cloudflare/CDN

Optional CDN cache controls are configured only through server-side `.env` values:

```env
CLOUDFLARE_CACHE_ENABLED="false"
CLOUDFLARE_ZONE_ID=""
CLOUDFLARE_API_TOKEN=""
```

Use a least-privilege API token. Authenticated private downloads must not be exposed through a cache rule that bypasses origin authorization.

## Owner System

Owner controls remain protected by the existing authorization, CSRF, session, audit and server-side validation rules. Rebranding changes presentation/default names only; it does not weaken the access-control model.
