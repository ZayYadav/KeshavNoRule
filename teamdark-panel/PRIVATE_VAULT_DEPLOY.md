# Team Dark Private Binary Vault

The panel now includes an authenticated `/files` vault for `.so` and `.zip` files.

## Storage model

- Files are stored under `storage/private_uploads/u<USER_ID>/`.
- The original upload name is never used as the disk filename.
- Each stored object receives a random 48-hex-character `.blob` name.
- This prevents collisions when different users upload identical filenames.
- Direct web access to `storage/` is denied. Downloads go through the authenticated `/files/download?id=...` controller.
- Non-owner accounts may keep at most 2 current files. Replacing or deleting a slot may be done repeatedly.
- Owner storage is unlimited and Owner can view/manage all user uploads.

## Database

`UploadManager` creates `user_uploads` automatically with `CREATE TABLE IF NOT EXISTS` when the vault is first opened.

If the production database account does not have CREATE permission, import:

```text
database/20260910_private_uploads.sql
```

once as a privileged database user.

## Filesystem permissions

PHP must be able to create/write:

```text
storage/private_uploads/
```

Recommended ownership is the same user/group used by the PHP-FPM/hosting account. Avoid making the directory world-writable unless it is only a temporary diagnostic step.

## Large uploads

The application accepts files up to about 2 GiB, but PHP and the web server may enforce smaller limits first. Set hosting values appropriate for your intended maximum, for example:

```ini
upload_max_filesize = 512M
post_max_size = 520M
max_execution_time = 300
max_input_time = 300
```

Use larger values only if the hosting plan and available storage support them. Restart/reload PHP if your hosting provider requires it after changing PHP configuration.
