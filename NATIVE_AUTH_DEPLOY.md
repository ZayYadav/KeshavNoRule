# No Rule Panel Native Auth Deployment

The panel keeps the existing native `/connect` request and response contract while exposing No Rule branding in the panel UI.

## Request contract

- Endpoint: `POST /connect`
- Content-Type: `application/x-www-form-urlencoded`
- Fields: `game`, `user_key`, `serial`

Example body:

```text
game=PUBG&user_key=<KEY>&serial=<DEVICE_ID>
```

## Success response

The native client continues to receive the same compatible JSON structure, including `status`, token/rng data and expiry fields. Unlimited keys continue to report `UNLIMITED` where supported by the existing contract.

## Failure response

Native validation failures continue to use the existing `status=false` response and reason strings. Rebranding does not intentionally change protocol semantics or field names.

## Secret configuration

Set the server-side native secret in `.env`:

```env
NORULE_AUTH_SECRET="YOUR_EXISTING_NATIVE_SECRET"
```

The secret must match the value expected by the native client. Keep it server-side and never expose it through HTML, JavaScript, logs or public API responses. The configuration loader retains a legacy environment-variable fallback so an existing deployment can be migrated without downtime.

## Production notes

- Use HTTPS only.
- Keep `APP_KEY` and native auth secrets out of Git.
- Import the current `database/schema.sql` before production use.
- Point the web root to `public/`.
- Test a valid key, invalid key, expired key and device-limit flow after deployment.
