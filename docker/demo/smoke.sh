#!/bin/sh
# Smoke-tests a running demo container: health, page, API, WebSocket upgrade, a ticking world.
# Usage: docker/demo/smoke.sh http://127.0.0.1:10000 <reverb-app-key>
set -eu
BASE="$1"
KEY="$2"

echo "waiting for $BASE/up"
i=0
until curl -fsS "$BASE/up" >/dev/null 2>&1; do
    i=$((i + 1)); [ "$i" -gt 90 ] && { echo "health check never passed"; exit 1; }
    sleep 2
done

echo "waiting for the boot reset to seed the world"
i=0
until curl -fsS "$BASE/api/dashboard" | grep -q '"sku":"FP-1L-STEEL"'; do
    i=$((i + 1)); [ "$i" -gt 90 ] && { echo "world never seeded"; exit 1; }
    sleep 2
done

echo "page renders the dashboard"
curl -fsS "$BASE/" | grep -qF 'repricer\/dashboard'

echo "WebSocket upgrade through nginx to Reverb"
curl -sS -i -N --max-time 5 \
    -H 'Connection: Upgrade' -H 'Upgrade: websocket' \
    -H 'Sec-WebSocket-Version: 13' -H 'Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==' \
    "$BASE/app/$KEY?protocol=7&client=js&version=8.6.0" 2>/dev/null | head -1 | grep -q ' 101 '

echo "the world ticks while watched"
first=$(curl -fsS "$BASE/api/decisions?limit=1" | grep -o '"id":[0-9]*' | head -1)
i=0
while :; do
    curl -fsS "$BASE/api/heartbeat" >/dev/null
    now=$(curl -fsS "$BASE/api/decisions?limit=1" | grep -o '"id":[0-9]*' | head -1)
    [ "$now" != "$first" ] && break
    i=$((i + 1)); [ "$i" -gt 45 ] && { echo "no new decisions in 90s"; exit 1; }
    sleep 2
done

echo "errors stay generic (APP_DEBUG off: no exception class, file or SQL)"
body=$(curl -sS "$BASE/api/products/abc/series")
if echo "$body" | grep -qE 'SQLSTATE|"exception"|"file"|"trace"'; then
    echo "error response leaks internals: $body"; exit 1
fi

echo "smoke test passed"
