# Team Dark Private Binary Vault

The panel includes an authenticated `/files` vault for `.so` and `.zip` files.

## Storage model

- Files are stored under `storage/private_uploads/u<USER_ID>/`.
- The original upload name is never used as the disk filename.
- Each stored object receives a random 48-hex-character `.blob` name.
- This prevents collisions when different users upload identical filenames.
- Direct web access to `storage/` is denied.
- Downloads go through the authenticated `/files/download?id=...` controller and support HTTP Range/resume.
- Non-owner accounts may keep at most 2 current files. Replacing or deleting a slot may be done repeatedly.
- Owner storage is unlimited and Owner can view/manage all user uploads through paginated views.

## Database

The project keeps its single-SQL install/upgrade contract. The `user_uploads` table is part of:

```text
database/schema.sql
```

Run the latest `database/schema.sql` once during deployment/upgrade using a database account with schema-change permission.

`UploadManager` also uses `CREATE TABLE IF NOT EXISTS` as a runtime compatibility fallback. Production deployments should still apply `schema.sql` explicitly so the normal web user does not need DDL privileges.

## Filesystem permissions

PHP must be able to create/write:

```text
storage/private_uploads/
```

Recommended ownership is the same user/group used by the PHP-FPM/hosting account. Avoid making the directory world-writable unless it is only a temporary diagnostic step.

The root panel rules deny direct requests to `storage/`, and `storage/private_uploads/.htaccess` provides an additional deny layer for Apache deployments.

## Large uploads and downloads

The application accepts files up to about 2 GiB, but PHP and the web server may enforce smaller upload limits first. Set hosting values appropriate for your intended maximum, for example:

```ini
upload_max_filesize = 512M
post_max_size = 520M
max_execution_time = 300
max_input_time = 300
```

Private downloads are streamed in chunks, support a single HTTP byte range, and release the PHP session lock before streaming so another panel tab is not blocked by a long download.

Use larger upload values only if the hosting plan and available storage support them. Restart/reload PHP if your hosting provider requires it after changing PHP configuration.
