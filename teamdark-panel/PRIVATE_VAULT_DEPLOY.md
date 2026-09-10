# Team Dark File Manager & Owner System

The panel includes an authenticated `/files` File Manager for `.so` and `.zip` files plus a modular Owner System console. Internal storage/controller names remain stable for backward compatibility even though the visible product name is now **File Manager**.

## File Manager storage model

- Files are stored under `storage/private_uploads/u<USER_ID>/`.
- The original upload name is never used as the disk filename.
- Each stored object receives a random 48-hex-character `.blob` name.
- This prevents collisions when different users upload identical filenames.
- Direct web access to `storage/` is denied.
- Downloads go through the authenticated `/files/download?id=...` controller.
- Non-owner accounts may keep at most 2 current files. Replacing or deleting a slot may be done repeatedly.
- Owner storage is unlimited and Owner can view/manage all user uploads through paginated views.

## Database

The project keeps its single-SQL install/upgrade contract. The `user_uploads` table is part of:

```text
database/schema.sql
```

Run the latest `database/schema.sql` once during deployment/upgrade using a database account with schema-change permission.

`UploadManager` first probes the existing table and only attempts `CREATE TABLE IF NOT EXISTS` when the table is actually missing. Production deployments should still apply `schema.sql` explicitly.

## Filesystem permissions

PHP must be able to create/write:

```text
storage/private_uploads/
```

Recommended ownership is the same user/group used by the PHP-FPM/hosting account. Avoid making the directory world-writable unless it is only a temporary diagnostic step.

The root panel rules deny direct requests to `storage/`, and `storage/private_uploads/.htaccess` provides an additional deny layer for Apache deployments.

## 50 MB uploads

The application accepts each `.so` or `.zip` file up to exactly 50 MiB (52,428,800 bytes).

The repository includes `.user.ini` in both the panel root and `public/` so common PHP-FPM/CGI shared-hosting layouts receive these values:

```ini
upload_max_filesize = 50M
post_max_size = 55M
max_execution_time = 600
max_input_time = 600
memory_limit = 256M
```

`post_max_size` is intentionally larger than 50M so multipart form overhead does not reject an otherwise valid 50M upload.

Some hosts enforce a lower web-server/account-level request limit that `.user.ini` cannot override. If an upload is still rejected after deployment, verify the active values in cPanel MultiPHP INI Editor / PHP Info and raise the hosting-level limit there.

## Optional Cloudflare purge hook

File Manager can purge the exact download URL from Cloudflare after upload, replace/update, or delete. Purge failures are logged but never roll back a successful file operation.

Configure only in the server `.env`:

```env
CLOUDFLARE_CACHE_ENABLED="false"
CLOUDFLARE_ZONE_ID=""
CLOUDFLARE_API_TOKEN=""
```

Set `CLOUDFLARE_CACHE_ENABLED="true"` to enable the hook. The API token should be restricted to cache-purge access for the intended zone and must never be committed to Git.

The purge target is the canonical authenticated URL:

```text
APP_URL/files/download?id=<FILE_ID>
```

Downloads deliberately keep `Cache-Control: private, no-store` because access is session-protected. Do not add a Cloudflare Cache Everything rule that serves `/files/download` without reaching origin authentication; that could expose one user's private file to another user.

## Modular Owner System

Owner controls are intentionally separated into focused pages instead of one oversized Server Controls screen:

- `/owner/system` — System overview
- `/owner/server` — Server & Maintenance
- `/owner/device-policy` — One Device / default device policy
- `/owner/key-format` — generated key prefix and random length
- `/owner/pricing` — finite-key credit pricing
- `/owner/ip-management` — exact IP/CIDR deny policy with Owner bypass
- `/owner/rebranding` — panel brand, mark, footer and cinematic splash
- `/owner/security` — security/activity posture
- `/owner/session-controls` — API-token and active device-binding revocation
- `/owner/packages` — release/package metadata attached to an Owner File Manager object
- `/owner/alerts` — panel and Telegram announcement center
- `/owner/update` — release label and optional manual CDN invalidation
- `/owner/settings` — environment/system summary
- `/owner/developer` — existing `/connect` integration reference
- `/activity` — activity logs

`/keys/extend` is the dedicated Extend Duration view and delegates changes to the existing permission-aware Key Editor.

The new Owner System does **not** change the Loader `/connect` request or response contract.

## Deployment checklist

Deploy the complete `teamdark-panel/` directory from `TeamDarkLoader`, not only individual PHP pages. In particular, keep `app/OwnerSystem.php`, `public/assets/system-console.css`, `app/CdnCache.php`, File Manager controllers, `.htaccess`, `.user.ini`, and the existing application classes in sync.

After deployment, hard-refresh the browser once if an older panel shell is still cached. If Cloudflare purge is enabled, Owner can also use **Panel Update → Purge CDN now** to invalidate the known panel shell URLs.
