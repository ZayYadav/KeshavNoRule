#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${BASE_URL:-http://127.0.0.1:8080}"
FIXTURE="${FIXTURE:-/tmp/teamdark-fixture.json}"

fail() {
  echo "TEST FAILED: $*" >&2
  exit 1
}

post_form() {
  curl -sS -X POST "$BASE_URL/connect" \
    -H "Accept: application/json" \
    -H "Content-Type: application/x-www-form-urlencoded" \
    "$@"
}

assert_false_reason() {
  local json="$1"
  local reason="$2"
  echo "$json" | jq -e --arg r "$reason" '.status == false and .reason == $r' >/dev/null \
    || fail "expected reason '$reason', got: $json"
}

TIMED="$(jq -r .timed "$FIXTURE")"
UNLIMITED="$(jq -r .unlimited "$FIXTURE")"
DISABLED="$(jq -r .disabled "$FIXTURE")"
REVOKED="$(jq -r .revoked "$FIXTURE")"
EXPIRED="$(jq -r .expired "$FIXTURE")"
RACE_ACT="$(jq -r .race_activation "$FIXTURE")"
RACE_DEV="$(jq -r .race_devices "$FIXTURE")"

# 1. Missing game
R="$(post_form --data-urlencode "user_key=$TIMED" --data-urlencode "serial=S1")"
assert_false_reason "$R" "Missing Parameters"

# 2. Missing user_key
R="$(post_form --data-urlencode "game=PUBG" --data-urlencode "serial=S1")"
assert_false_reason "$R" "Missing Parameters"

# 3. Missing serial
R="$(post_form --data-urlencode "game=PUBG" --data-urlencode "user_key=$TIMED")"
assert_false_reason "$R" "Missing Parameters"

# Wrong game
R="$(post_form --data-urlencode "game=OTHER" --data-urlencode "user_key=$TIMED" --data-urlencode "serial=S1")"
assert_false_reason "$R" "Invalid Game"

# 4. Invalid key
R="$(post_form --data-urlencode "game=PUBG" --data-urlencode "user_key=NO-SUCH-KEY" --data-urlencode "serial=S1")"
assert_false_reason "$R" "Invalid Key"

# 5 + 6. New unused key / first activation
SERIAL1="CONTRACT-DEVICE-ONE"
R="$(post_form --data-urlencode "game=PUBG" --data-urlencode "user_key=$TIMED" --data-urlencode "serial=$SERIAL1")"
echo "$R" | jq -e '.status == true and (.data|type=="object") and (.data.token|type=="string") and (.data.rng|type=="number") and (.data.expired_date|type=="string")' >/dev/null \
  || fail "first activation response invalid: $R"

# 15. Server timestamp
RNG="$(echo "$R" | jq -r '.data.rng')"
NOW="$(date +%s)"
DELTA=$(( RNG > NOW ? RNG - NOW : NOW - RNG ))
(( DELTA <= 60 )) || fail "rng outside ±60 sec: $DELTA"

# 16. Token equality with exact current main.cpp secret supplied by CI.
EXPECTED="$(printf '%s' "PUBG-$TIMED-$SERIAL1-$TEAMDARK_AUTH_SECRET" | md5sum | awk '{print $1}')"
ACTUAL="$(echo "$R" | jq -r '.data.token')"
[[ "$EXPECTED" == "$ACTUAL" ]] || fail "server token != native-compatible MD5"

# 7. Same serial second login must not consume slot
R2="$(post_form --data-urlencode "game=PUBG" --data-urlencode "user_key=$TIMED" --data-urlencode "serial=$SERIAL1")"
echo "$R2" | jq -e '.status == true' >/dev/null || fail "same serial second login failed"

HASH_TIMED="$(printf '%s' "$TIMED" | sha256sum | awk '{print $1}')"
COUNT="$(mysql -N -h127.0.0.1 -uroot -proot teamdark_test -e "SELECT COUNT(*) FROM license_devices d JOIN license_keys k ON k.id=d.license_key_id WHERE k.key_hash='$HASH_TIMED' AND d.active=1")"
[[ "$COUNT" == "1" ]] || fail "same serial consumed duplicate slot: $COUNT"

# 8. New serial within limit
R3="$(post_form --data-urlencode "game=PUBG" --data-urlencode "user_key=$TIMED" --data-urlencode "serial=CONTRACT-DEVICE-TWO")"
echo "$R3" | jq -e '.status == true' >/dev/null || fail "second device should be allowed"

# 9. Device limit reached
R4="$(post_form --data-urlencode "game=PUBG" --data-urlencode "user_key=$TIMED" --data-urlencode "serial=CONTRACT-DEVICE-THREE")"
assert_false_reason "$R4" "Device Limit Reached"

# 10. Expired
R="$(post_form --data-urlencode "game=PUBG" --data-urlencode "user_key=$EXPIRED" --data-urlencode "serial=EXP-DEVICE")"
assert_false_reason "$R" "Key Expired"

# 11. Disabled
R="$(post_form --data-urlencode "game=PUBG" --data-urlencode "user_key=$DISABLED" --data-urlencode "serial=DIS-DEVICE")"
assert_false_reason "$R" "Key Disabled"

# 12. Revoked
R="$(post_form --data-urlencode "game=PUBG" --data-urlencode "user_key=$REVOKED" --data-urlencode "serial=REV-DEVICE")"
assert_false_reason "$R" "Key Revoked"

# 13 + 14. Unlimited validity + unlimited devices
for i in $(seq 1 15); do
  R="$(post_form --data-urlencode "game=PUBG" --data-urlencode "user_key=$UNLIMITED" --data-urlencode "serial=UNLIMITED-DEVICE-$i")"
  echo "$R" | jq -e '.status == true and .data.expired_date == "UNLIMITED"' >/dev/null \
    || fail "unlimited key/device failed at $i: $R"
done

# 17. Malformed JSON POST: current loader contract is form-urlencoded, so fields are missing.
R="$(curl -sS -X POST "$BASE_URL/connect" -H "Content-Type: application/json" -d '{"game":"PUBG"}')"
assert_false_reason "$R" "Missing Parameters"

# 18. Simultaneous first-use requests for same serial.
TMP1="$(mktemp -d)"
for i in $(seq 1 10); do
  (
    post_form \
      --data-urlencode "game=PUBG" \
      --data-urlencode "user_key=$RACE_ACT" \
      --data-urlencode "serial=RACE-SAME-SERIAL" \
      >"$TMP1/$i.json"
  ) &
done
wait

SUCCESS="$(jq -s '[.[] | select(.status == true)] | length' "$TMP1"/*.json)"
[[ "$SUCCESS" == "10" ]] || fail "simultaneous first-use success count: $SUCCESS"

HASH_RACE_ACT="$(printf '%s' "$RACE_ACT" | sha256sum | awk '{print $1}')"
DEVICE_COUNT="$(mysql -N -h127.0.0.1 -uroot -proot teamdark_test -e "SELECT COUNT(*) FROM license_devices d JOIN license_keys k ON k.id=d.license_key_id WHERE k.key_hash='$HASH_RACE_ACT' AND d.active=1")"
[[ "$DEVICE_COUNT" == "1" ]] || fail "first-use race created $DEVICE_COUNT devices"

ACTIVATED="$(mysql -N -h127.0.0.1 -uroot -proot teamdark_test -e "SELECT activated_at IS NOT NULL FROM license_keys WHERE key_hash='$HASH_RACE_ACT'")"
[[ "$ACTIVATED" == "1" ]] || fail "race key was not activated"

# 19. Simultaneous distinct device registrations with max_devices=5.
TMP2="$(mktemp -d)"
for i in $(seq 1 10); do
  (
    post_form \
      --data-urlencode "game=PUBG" \
      --data-urlencode "user_key=$RACE_DEV" \
      --data-urlencode "serial=RACE-DEVICE-$i" \
      >"$TMP2/$i.json"
  ) &
done
wait

SUCCESS="$(jq -s '[.[] | select(.status == true)] | length' "$TMP2"/*.json)"
LIMITED="$(jq -s '[.[] | select(.status == false and .reason == "Device Limit Reached")] | length' "$TMP2"/*.json)"
[[ "$SUCCESS" == "5" ]] || fail "device race allowed $SUCCESS, expected 5"
[[ "$LIMITED" == "5" ]] || fail "device race rejected $LIMITED, expected 5"

HASH_RACE_DEV="$(printf '%s' "$RACE_DEV" | sha256sum | awk '{print $1}')"
DEVICE_COUNT="$(mysql -N -h127.0.0.1 -uroot -proot teamdark_test -e "SELECT COUNT(*) FROM license_devices d JOIN license_keys k ON k.id=d.license_key_id WHERE k.key_hash='$HASH_RACE_DEV' AND d.active=1")"
[[ "$DEVICE_COUNT" == "5" ]] || fail "device race DB count $DEVICE_COUNT, expected 5"

echo "All TeamDark native /connect contract tests passed."
