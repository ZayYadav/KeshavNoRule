#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SOURCE_REPO="https://github.com/anggorodhanumurti/themaphack.git"
SOURCE_COMMIT="327b9ccad7ca36a76aeff09e78799df17a611eef"
WORK_DIR="${ROOT_DIR}/.bewafa32-native-src"
DEST_ROOT="${ROOT_DIR}/app/src/main/jni/includes/curl"
SOURCE_ROOT="${WORK_DIR}/jni/Tools/Login/library"

rm -rf "${WORK_DIR}"
mkdir -p "${WORK_DIR}"
git -C "${WORK_DIR}" init -q
git -C "${WORK_DIR}" remote add origin "${SOURCE_REPO}"
git -C "${WORK_DIR}" config core.sparseCheckout true
cat > "${WORK_DIR}/.git/info/sparse-checkout" <<'EOF'
jni/Tools/Login/library/curl-android-armeabi-v7a/
jni/Tools/Login/library/openssl-android-armeabi-v7a/
EOF

git -C "${WORK_DIR}" fetch --depth=1 origin "${SOURCE_COMMIT}"
git -C "${WORK_DIR}" checkout -q FETCH_HEAD

CURL_LIB="${SOURCE_ROOT}/curl-android-armeabi-v7a/lib/libcurl.a"
SSL_LIB="${SOURCE_ROOT}/openssl-android-armeabi-v7a/lib/libssl.a"
CRYPTO_LIB="${SOURCE_ROOT}/openssl-android-armeabi-v7a/lib/libcrypto.a"

[[ "$(git -C "${WORK_DIR}" hash-object "$CURL_LIB")" == "b74a0a287cc45c8b06c2645739c687de47b8d23e" ]]
[[ "$(git -C "${WORK_DIR}" hash-object "$SSL_LIB")" == "8b80e55fb727438950d9399b7f0a8d6f91bb4cca" ]]
[[ "$(git -C "${WORK_DIR}" hash-object "$CRYPTO_LIB")" == "5530dce398087f37d81e1fb00bc61268f5f69df6" ]]

rm -rf \
  "${DEST_ROOT}/curl-android-armeabi-v7a" \
  "${DEST_ROOT}/openssl-android-armeabi-v7a"
cp -a "${SOURCE_ROOT}/curl-android-armeabi-v7a" "${DEST_ROOT}/"
cp -a "${SOURCE_ROOT}/openssl-android-armeabi-v7a" "${DEST_ROOT}/"

[[ -s "${DEST_ROOT}/curl-android-armeabi-v7a/lib/libcurl.a" ]]
[[ -s "${DEST_ROOT}/openssl-android-armeabi-v7a/lib/libssl.a" ]]
[[ -s "${DEST_ROOT}/openssl-android-armeabi-v7a/lib/libcrypto.a" ]]

echo "BEWAFA SERVER ARMv7 curl/OpenSSL prebuilts prepared from pinned commit ${SOURCE_COMMIT}."
