#!/usr/bin/env bash
# ============================================================================
# Tier 2 end-to-end test — configure the extension, put a real Magento order
# through it, deliver signed callbacks, and assert what the shop actually does.
#
# Tier 1 proves the module compiles and loads. This proves it *works*: that the
# order we send SpectroCoin describes the shop's order, and that every status on
# the wire moves the order where it should — or deliberately leaves it alone.
#
# Magento is the one plugin still on the LEGACY flow: form-encoded callbacks
# authenticated by an RSA-SHA1 signature. So this test generates a keypair, has
# the stub serve the public half at the hardcoded certificate URL the extension
# fetches, and signs every callback with the private half. A callback that is
# not correctly signed is refused — which is itself asserted below.
#
# Note the extension answers in the BODY (*ok* / *error*) and always with
# HTTP 200, so the assertions read the body.
#
# Usage:
#   ./tier2.sh          # run the full flow
#   ./tier2.sh --keep   # leave the stack running for inspection
# ============================================================================
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/../../.." && pwd)"
MODULE="Spectrocoin_Merchant"
KEEP=0

while [ $# -gt 0 ]; do
  case "$1" in
    --keep) KEEP=1; shift ;;
    *) echo "unknown argument: $1" >&2; exit 2 ;;
  esac
done

say()  { printf '\n\033[1m== %s\033[0m\n' "$*"; }
pass() { printf '  \033[32mPASS\033[0m  %s\n' "$*"; }
fail() { printf '  \033[31mFAIL\033[0m  %s\n' "$*"; FAILED=$((FAILED+1)); }
FAILED=0

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

cd "$HERE"

# --------------------------------------------------------------------------
# 1. Certificates: TLS for the stub, and the callback signing keypair.
# --------------------------------------------------------------------------
say "Generating certificates and the callback signing key"
rm -rf .certs && mkdir -p .certs
openssl req -x509 -newkey rsa:2048 -nodes -days 3650 \
  -keyout .certs/ca.key -out .certs/ca.crt \
  -subj "/CN=SpectroCoin Tier2 Test CA" >/dev/null 2>&1
openssl req -newkey rsa:2048 -nodes -keyout .certs/server.key -out .certs/server.csr \
  -subj "/CN=spectrocoin.com" >/dev/null 2>&1
printf 'subjectAltName=DNS:spectrocoin.com\n' > .certs/ext
openssl x509 -req -in .certs/server.csr -CA .certs/ca.crt -CAkey .certs/ca.key \
  -CAcreateserial -out .certs/server.crt -days 3650 -extfile .certs/ext >/dev/null 2>&1

# The pair the callbacks are signed with. The stub serves the public half at
# the certificate URL the extension has hardcoded.
openssl genrsa -out .certs/callback-private.pem 2048 >/dev/null 2>&1
openssl rsa -in .certs/callback-private.pem -pubout -out .certs/callback-public.pem >/dev/null 2>&1
# The extension signs its own outbound requests with this one; the stub does not
# check it, but the extension refuses to run without a usable key.
openssl genrsa -out .certs/merchant-private.pem 2048 >/dev/null 2>&1
chmod 644 .certs/*

[ -s .certs/callback-public.pem ] && pass "issued a TLS certificate and a callback signing key" \
  || fail "certificate generation failed"

# --------------------------------------------------------------------------
# 2. The stack.
# --------------------------------------------------------------------------
say "Starting Magento and the API stub (create-project + install; slow)"
docker compose down -v >/dev/null 2>&1 || true
docker compose up -d --build --wait >/dev/null 2>&1

mg()   { docker compose exec -T magento "$@"; }
# Anything a root-run CLI step creates under var/ or generated/ leaves Apache
# unable to write it, and then every web request fails before reaching the
# controller. Run the scripts that touch those as the web user.
mgw()  { docker compose exec -T -u www-data magento "$@"; }
fixperms() { mg sh -c 'chown -R www-data:www-data /var/www/html/var /var/www/html/generated /var/www/html/pub 2>/dev/null' || true; }
stub() { docker compose exec -T spectrocoin "$@"; }
# Requests to the shop come from the stub container: that is where a callback
# comes from in production, and only a container on this network resolves the
# shop's hostname.
shopcurl() { docker compose exec -T spectrocoin curl "$@"; }

mg sh -c 'cat /certs/ca.crt >> /etc/ssl/certs/ca-certificates.crt' >/dev/null 2>&1 || true

if ! mg sh -c '[ -f /var/www/html/bin/magento ]' 2>/dev/null; then
  mg sh -c 'composer create-project --quiet --no-interaction \
      --repository-url=https://repo.mage-os.org/ \
      mage-os/project-community-edition /var/www/html' > "$WORK/create.log" 2>&1 || true
fi
mg sh -c '[ -f /var/www/html/bin/magento ]' 2>/dev/null \
  && pass "Mage-OS source installed" \
  || { fail "composer create-project failed:"; tail -8 "$WORK/create.log" | sed 's/^/        /'; }

mg sh -c 'cd /var/www/html && php bin/magento setup:install \
    --base-url=http://shop.test/ \
    --db-host=db --db-name=magento --db-user=root --db-password=root \
    --admin-firstname=Tier --admin-lastname=Two \
    --admin-email=tier2@example.com --admin-user=admin --admin-password=Tier2tier2 \
    --language=en_US --currency=EUR --timezone=UTC --use-rewrites=1 \
    --search-engine=opensearch --opensearch-host=opensearch --opensearch-port=9200 \
    --no-interaction 2>&1' > "$WORK/install.log" 2>&1 || true

if mg sh -c 'cd /var/www/html && php bin/magento --version' >/dev/null 2>&1; then
  pass "Magento installed ($(mg sh -c 'cd /var/www/html && php bin/magento --version' 2>/dev/null | tr -d '\r'))"
else
  fail "Magento install failed:"; tail -12 "$WORK/install.log" | sed 's/^/        /'
fi

# --------------------------------------------------------------------------
# 3. The module.
# --------------------------------------------------------------------------
say "Installing and configuring the module"
docker compose cp "$ROOT/." magento:/tmp/module >/dev/null 2>&1
mg sh -c "rm -rf /var/www/html/app/code/Spectrocoin/Merchant /tmp/module/tests /tmp/module/.git \
          && mkdir -p /var/www/html/app/code/Spectrocoin/Merchant \
          && cp -a /tmp/module/. /var/www/html/app/code/Spectrocoin/Merchant/ \
          && chown -R www-data:www-data /var/www/html/app/code" \
  && pass "module copied into app/code" || fail "module could not be copied"

mg sh -c "cd /var/www/html && php bin/magento module:enable $MODULE --no-interaction \
          && php bin/magento setup:upgrade --no-interaction \
          && php bin/magento setup:di:compile --no-interaction" \
  > "$WORK/enable.log" 2>&1 \
  && pass "module enabled and compiled" \
  || { fail "module failed to enable or compile:"; grep -iE "error|exception" "$WORK/enable.log" | head -5 | sed 's/^/        /'; }

docker compose cp configure.php magento:/tmp/configure.php >/dev/null 2>&1
mgw sh -c 'cd /var/www/html && php /tmp/configure.php' > "$WORK/config.log" 2>&1 || true
grep -q CONFIGURED "$WORK/config.log" \
  && pass "credentials configured" \
  || { fail "could not configure the module:"; tail -5 "$WORK/config.log" | sed 's/^/        /'; }

mg sh -c 'cd /var/www/html && php bin/magento cache:flush' >/dev/null 2>&1 || true
fixperms

# --------------------------------------------------------------------------
# 4. Place a real order and let the module create the SpectroCoin order.
# --------------------------------------------------------------------------
say "Placing an order through the extension"
stub curl -fsS -X POST http://localhost/__test/reset >/dev/null 2>&1
docker compose cp place-order.php magento:/tmp/place-order.php >/dev/null 2>&1
mgw sh -c 'cd /var/www/html && rm -f /tmp/tier2-order.json && php /tmp/place-order.php' \
  > "$WORK/place.log" 2>&1 || true
fixperms

mgw sh -c 'cat /tmp/tier2-order.json 2>/dev/null' > "$WORK/order.json" 2>/dev/null || true
ofield() { python3 -c "import json;print(json.load(open('$WORK/order.json')).get('$1',''))" 2>/dev/null || true; }
INC=$(ofield increment_id)
TOTAL=$(ofield grand_total)
CCY=$(ofield currency)

if [ -n "$INC" ]; then
  pass "Magento order $INC created (total $TOTAL $CCY)"
else
  fail "no order was created:"; tail -10 "$WORK/place.log" | sed 's/^/        /'
fi

# --------------------------------------------------------------------------
# 5. What the extension actually sent us.
# --------------------------------------------------------------------------
say "Inspecting the request the extension sent"
stub curl -fsS http://localhost/__test/requests > "$WORK/requests.json" 2>/dev/null

created=$(python3 - "$WORK/requests.json" <<'PYEOF'
import json, sys
for r in json.load(open(sys.argv[1])):
    if r["path"].endswith("/createOrder"):
        print(json.dumps({**(r.get("post") or {}), "_ua": r["user_agent"]}))
        break
PYEOF
)
[ -n "$created" ] || created='{}'
field() { printf '%s' "$created" | python3 -c "import json,sys;print(json.load(sys.stdin).get('$1',''))" 2>/dev/null; }

[ -n "$(field orderId)" ] && pass "an order was sent to SpectroCoin" \
  || { fail "no create-order request reached SpectroCoin:"; tail -8 "$WORK/place.log" | sed 's/^/        /'; }

[ "$(field orderId)" = "$INC" ] \
  && pass "orderId is the shop's increment id ($INC)" \
  || fail "orderId was '$(field orderId)', expected '$INC'"

[ -n "$CCY" ] && [ "$(field receiveCurrency)" = "$CCY" ] \
  && pass "order was sent in the shop's currency ($CCY)" \
  || fail "receiveCurrency was '$(field receiveCurrency)', order is in '${CCY:-none}'"

# The extension puts the amount on the pay side or the receive side depending
# on payment_settings/order_payment_method, and its own matchesOrder() accepts
# either - so assert the shop's total arrived on one of them, not on a
# particular one.
if python3 -c "
import sys
def near(v):
    try: return abs(float(v) - float('$TOTAL')) < 0.01
    except ValueError: return False
sys.exit(0 if near('$(field payAmount)') or near('$(field receiveAmount)') else 1)" 2>/dev/null; then
  pass "order was sent for the shop's total ($TOTAL)"
else
  fail "neither payAmount '$(field payAmount)' nor receiveAmount '$(field receiveAmount)' is the order total '$TOTAL'"
fi

case "$(field callbackUrl)" in
  *spectrocoin*callback*) pass "callbackUrl points at the extension's endpoint" ;;
  *) fail "unexpected callbackUrl: '$(field callbackUrl)'" ;;
esac

case "$(field _ua)" in
  SpectroCoin-Magento2/*) pass "identifies itself as $(field _ua)" ;;
  *) fail "User-Agent was '$(field _ua)', expected SpectroCoin-Magento2/<version>" ;;
esac

# --------------------------------------------------------------------------
# 6. Deliver signed callbacks and assert what the shop does with each status.
# --------------------------------------------------------------------------
say "Delivering signed callbacks for every status on the wire"

CB="http://shop.test/spectrocoin/statuspage/callback"

# Builds a correctly signed, form-encoded callback body for a status code.
signed_body() {
  local status="$1" amount="${2:-$TOTAL}" currency="${3:-$CCY}"
  python3 sign-callback.py "$(python3 - <<PYEOF
import json
print(json.dumps({
  "userId": "tier2-merchant",
  "merchantApiId": "tier2-project",
  "merchantId": "1",
  "apiId": "1",
  "orderId": "$INC",
  "payCurrency": "$currency",
  "payAmount": "$amount",
  "receiveCurrency": "$currency",
  "receiveAmount": "$amount",
  "receivedAmount": "$amount",
  "description": "Tier 2",
  "orderRequestId": "1001",
  "status": "$status",
}))
PYEOF
)" .certs/callback-private.pem
}

deliver() {
  local body="$1"
  printf '%s' "$body" > "$WORK/body.txt"
  docker compose cp "$WORK/body.txt" spectrocoin:/tmp/body.txt >/dev/null 2>&1
  shopcurl -s -X POST -H 'Content-Type: application/x-www-form-urlencoded' \
    --data-binary @/tmp/body.txt "$CB"
}

order_status() {
  mgw sh -c "cd /var/www/html && php -r '
    require \"app/bootstrap.php\";
    \$om = \Magento\Framework\App\Bootstrap::create(BP, \$_SERVER)->getObjectManager();
    \$o = \$om->create(\Magento\Sales\Model\Order::class)->loadByIncrementId(\"$INC\");
    echo \$o->getStatus();
  ' 2>/dev/null" | tr -d '\r'
}

reset_order() {
  mgw sh -c "cd /var/www/html && php -r '
    require \"app/bootstrap.php\";
    \$om = \Magento\Framework\App\Bootstrap::create(BP, \$_SERVER)->getObjectManager();
    \$o = \$om->create(\Magento\Sales\Model\Order::class)->loadByIncrementId(\"$INC\");
    \$o->setState(\"new\")->setStatus(\"pending\");
    \$o->save();
  ' >/dev/null 2>&1" || true
}

BASE=$(order_status)
[ -n "$BASE" ] && pass "order is readable, starting status '$BASE'" \
               || fail "could not read the order status back"

check_status() {
  local code="$1" want="$2" note="${3:-}"
  reset_order
  local out got
  out=$(deliver "$(signed_body "$code")")
  got=$(order_status)
  if [ "$out" = "*ok*" ] && [ "$got" = "$want" ]; then
    pass "status $code -> $want${note:+ ($note)}"
  else
    fail "status $code answered '$out' and left the order '$got', expected '*ok*' and '$want'${note:+ ($note)}"
  fi
}

# Codes and expectations come from the extension's own configuration defaults.
# NB: these config values are Magento STATES, not statuses - updateOrderStatus
# sets the state and then takes that state's default status. So the 'new' state
# lands the order on the 'pending' status.
check_status 1  pending         "New state -> its default status"
check_status 2  pending_payment "Pending"
check_status 3  complete        "Paid"
check_status 5  canceled        "Expired"
check_status 4  closed          "Failed, a cancellation"
check_status 13 closed          "Cancelled"
check_status 14 closed          "InvalidPayment"
check_status 21 closed          "Rejected"
check_status 6  payment_review  "Test"
check_status 15 payment_review  "TestPaid"
check_status 16 payment_review  "TestExpired"

# Informational statuses report on a payment already under way. The order must
# be left exactly as it was.
for code in 10 11 12 17 18 19 20; do
  check_status "$code" pending "informational, no change"
done

# --------------------------------------------------------------------------
# 7. The callback endpoint is a public URL. It must refuse the obvious abuse.
# --------------------------------------------------------------------------
say "Callback endpoint guards"

out=$(shopcurl -s "$CB")
[ "$out" = "*error*" ] && pass "GET is refused" \
                       || fail "GET answered '$out', expected '*error*' - the callback must be POST-only"

# The signature is the only thing authenticating this request.
reset_order
body=$(signed_body 3)
tampered=$(printf '%s' "$body" | sed 's/&sign=.*/\&sign=bm90LWEtc2lnbmF0dXJl/')
out=$(deliver "$tampered")
now=$(order_status)
if [ "$out" = "*error*" ] && [ "$now" != "complete" ]; then
  pass "a callback with a bad signature is refused"
else
  fail "bad signature answered '$out' and left the order '$now' - the signature is not being enforced"
fi

# A correctly signed callback for an order that does not exist.
reset_order
out=$(deliver "$(python3 sign-callback.py "$(python3 -c "
import json
print(json.dumps({'userId':'tier2-merchant','merchantApiId':'tier2-project','merchantId':'1','apiId':'1',
 'orderId':'000000999','payCurrency':'$CCY','payAmount':'$TOTAL','receiveCurrency':'$CCY',
 'receiveAmount':'$TOTAL','receivedAmount':'$TOTAL','description':'Tier 2','orderRequestId':'1001','status':'3'}))")" .certs/callback-private.pem)")
[ "$out" = "*error*" ] && pass "a callback for an unknown order is refused" \
                       || fail "unknown order answered '$out', expected '*error*'"

# A settlement that disagrees with the order's amount and currency.
reset_order
out=$(deliver "$(signed_body 3 "0.01" "XXX")")
now=$(order_status)
if [ "$out" = "*error*" ] && [ "$now" != "complete" ]; then
  pass "a settlement that does not match the order is refused"
else
  fail "mismatched settlement answered '$out' and left the order '$now'"
fi

# --------------------------------------------------------------------------
# 8. Nothing may have been logged as an error by the module.
# --------------------------------------------------------------------------
say "Magento log"
log=$(mg sh -c 'cat /var/www/html/var/log/*.log 2>/dev/null || true')
ours=$(printf '%s\n' "$log" | grep -iE "critical|fatal|uncaught" | grep -iE "spectrocoin|guzzle" || true)
[ -z "$ours" ] && pass "no errors attributable to the module" \
  || { fail "errors in the log:"; printf '%s\n' "$ours" | head -6; }

if [ "$KEEP" -eq 1 ]; then
  echo -e "\nstack left running: add '127.0.0.1 shop.test' to /etc/hosts, then"
  echo    "http://shop.test:8090 (admin/Tier2tier2)"
else
  docker compose down -v >/dev/null 2>&1 || true
  rm -rf .certs
fi

echo
[ "$FAILED" -eq 0 ] && echo "tier 2 PASSED" || echo "tier 2 FAILED ($FAILED check(s))"
exit $([ "$FAILED" -eq 0 ] && echo 0 || echo 1)
