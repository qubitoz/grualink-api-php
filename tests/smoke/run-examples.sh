#!/usr/bin/env bash
# Runs both examples for real against local stand-ins: the request example against the fake API, and the
# webhook receiver behind the built-in web server of PHP with locally signed deliveries. Nothing leaves
# this machine and every key and secret is an obvious fake.
set -euo pipefail
cd "$(dirname "$0")/../.."

WORK="$(mktemp -d)"
API_PID=""
WEBHOOK_PID=""
cleanup() {
  [ -n "$API_PID" ] && kill "$API_PID" 2>/dev/null || true
  [ -n "$WEBHOOK_PID" ] && kill "$WEBHOOK_PID" 2>/dev/null || true
  rm -rf "$WORK"
}
trap cleanup EXIT

fail() {
  echo "SMOKE FAILED: $1" >&2
  exit 1
}

echo "== examples/request-tow.php =="
php tests/Support/fake-api-server.php --demo > "$WORK/api-port" 2> "$WORK/api.log" &
API_PID=$!
for _ in $(seq 1 50); do
  [ -s "$WORK/api-port" ] && break
  sleep 0.1
done
[ -s "$WORK/api-port" ] || fail "the fake API did not start"
API_PORT="$(head -n 1 "$WORK/api-port")"

GRUALINK_API_KEY="glk_test_FAKE_KEY_FOR_SMOKE_RUN_ONLY" \
GRUALINK_API_BASE_URL="http://127.0.0.1:${API_PORT}/api/v1" \
  php examples/request-tow.php | tee "$WORK/request.out"

grep -q '^API status: ok (v1)$' "$WORK/request.out" || fail "health was not printed"
grep -q 'with status "confirmed", reference TEST-CONFIRMAR-' "$WORK/request.out" || fail "the request was not created"
grep -q 'Total:     1002.62 MXN' "$WORK/request.out" || fail "the quote was not printed"
grep -q 'replayed=true, same id=true$' "$WORK/request.out" || fail "the replay was not shown"
grep -q '^Status now: "confirmed"$' "$WORK/request.out" || fail "the status was not read"
grep -q '^Requests with reference TEST-CONFIRMAR-[0-9a-f]*: 1$' "$WORK/request.out" || fail "the search did not find it"
if grep -rq 'glk_test_FAKE_KEY_FOR_SMOKE_RUN_ONLY' "$WORK/request.out"; then
  fail "the key was printed"
fi

echo "== examples/request-tow.php refuses a live key and a missing key =="
if GRUALINK_API_KEY="glk_live_FAKE_KEY_FOR_SMOKE_RUN_ONLY" php examples/request-tow.php 2> "$WORK/live.err"; then
  fail "the example ran with a live key"
fi
grep -q 'only runs with a TEST key' "$WORK/live.err" || fail "the live key was not refused with its message"
if env -u GRUALINK_API_KEY php examples/request-tow.php 2> /dev/null; then
  fail "the example ran without a key"
fi

echo "== examples/webhook-server.php =="
WEBHOOK_PORT="$(php -r '$s = stream_socket_server("tcp://127.0.0.1:0"); echo substr(strrchr(stream_socket_get_name($s, false), ":"), 1);')"
export GRUALINK_WEBHOOK_SECRET="whsec_TEST_ONLY_FAKE_SECRET_SMOKE"
GRUALINK_WEBHOOK_STATE_DIR="$WORK/deliveries" \
  php -S "127.0.0.1:${WEBHOOK_PORT}" examples/webhook-server.php > "$WORK/webhook.log" 2>&1 &
WEBHOOK_PID=$!
for _ in $(seq 1 50); do
  if php -r 'exit(@fsockopen("127.0.0.1", (int) $argv[1]) ? 0 : 1);' "$WEBHOOK_PORT"; then
    break
  fi
  sleep 0.1
done

php tests/smoke/send-signed-delivery.php "http://127.0.0.1:${WEBHOOK_PORT}/webhooks/grualink" \
  || { cat "$WORK/webhook.log" >&2; fail "the receiver did not answer as expected"; }

cat "$WORK/webhook.log"
[ "$(grep -c '\[test\] webhook.test: Aviso de prueba' "$WORK/webhook.log")" -eq 1 ] \
  || fail "the event was not processed exactly once"
grep -q '401 (signature_mismatch)' "$WORK/webhook.log" || fail "the refusal was not logged"
if grep -q 'whsec_TEST_ONLY_FAKE_SECRET_SMOKE' "$WORK/webhook.log"; then
  fail "the secret was logged"
fi

echo "Both examples ran."
