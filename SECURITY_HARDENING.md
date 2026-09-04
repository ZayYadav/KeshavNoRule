# KeshavOwner Security Hardening

Branch: `keshavowner-security-ui-v1`

## Class map

- `KeshavOwner1` — Application / BlackBox bootstrap
- `KeshavOwner2` — Login / license activity
- `KeshavOwner3` — Main dashboard / launcher activity
- `KeshavOwner4` — App/OBB manager
- `KeshavOwner5` — Secure downloader / extractor
- `KeshavOwner6` — AES-GCM Keystore-backed preferences
- `KeshavOwner7` — UI sound/touch manager

## Custom integrity

Put owner-specific integrity logic only in:

`app/src/main/jni/custom_integrity.cpp`

The function:

`keshav_integrity::run(JNIEnv *env, jobject context)`

must return `true` to allow the login activity to continue.

## Release hardening enabled

- R8 full mode + resource shrinking
- LSParanoid string obfuscation on app-owned Java classes
- Java class renaming to KeshavOwner1..7
- Release screenshot / screen-record protection
- Debugger gate on login and dashboard
- APK signing-certificate verification before login
- Android Keystore AES/GCM protection for stored license strings
- HTTPS-only updater transport
- ZIP path traversal and archive-size protection
- Native hidden visibility
- Stack protector + FORTIFY
- RELRO + immediate symbol binding
- Native symbol stripping + static-library symbol hiding
- Release debug symbols disabled
- Backup disabled and cleartext traffic disabled
- Dependency metadata and unnecessary META-INF resources trimmed

## Important

No client-side Android application can be made literally unextractable or permanently uncrackable. The goal here is layered hardening while keeping the existing panel/API flow compatible.
