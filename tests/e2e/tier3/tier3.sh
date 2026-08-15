#!/usr/bin/env bash
# ============================================================================
# Tier 3 end-to-end test — a real shopper, in a real browser, through a real
# Magento checkout.
#
# Tier 1 proves the module compiles and loads. Tier 2 drives
# getSpectrocoinResponse() and every signed callback status directly, so it
# proves the gateway works - but never that a shopper can reach it. Magento's
# checkout is a Knockout/UI-component app assembled from jsLayout XML merges,
# and this module registers its renderer through exactly that mechanism - a
# gateway can pass every Tier 2 assertion and still never appear at checkout.
#
# So this one buys a product: add to cart, fill in the address, pick the
# shipping method, pick SpectroCoin, place the order, and follow the redirect
# to the payment page. The SpectroCoin API is the same legacy signed-flow
# stub Tier 2 uses, though this tier never delivers a callback itself.
#
# Usage:
#   ./tier3.sh              # run the full journey
#   ./tier3.sh --keep       # leave the stack running for inspection
#   ./tier3.sh --disable-spectrocoin
#                           # negative control: disable ONLY the SpectroCoin
#                           # method (nothing else) and run the same journey -
#                           # used to prove the checkout assertions actually
#                           # detect absence, not just render noise.
#
# Screenshots of any failing step land in ./artifacts/.
# ============================================================================
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/../../.." && pwd)"
MODULE="Spectrocoin_Merchant"
KEEP=0
DISABLE_SPECTROCOIN=0

while [ $# -gt 0 ]; do
  case "$1" in
    --keep) KEEP=1; shift ;;
    --disable-spectrocoin) DISABLE_SPECTROCOIN=1; shift ;;
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
rm -rf artifacts && mkdir -p artifacts

# --------------------------------------------------------------------------
# 1. Certificates. TLS for the stub, plus a merchant key the extension needs
#    configured before it will initialize - the same pair Tier 2 mints, even
#    though this tier never delivers a signed callback itself.
# --------------------------------------------------------------------------
say "Generating certificates"
rm -rf .certs && mkdir -p .certs
openssl req -x509 -newkey rsa:2048 -nodes -days 3650 \
  -keyout .certs/ca.key -out .certs/ca.crt \
  -subj "/CN=SpectroCoin Tier3 Test CA" >/dev/null 2>&1
openssl req -newkey rsa:2048 -nodes -keyout .certs/server.key -out .certs/server.csr \
  -subj "/CN=spectrocoin.com" >/dev/null 2>&1
printf 'subjectAltName=DNS:spectrocoin.com\n' > .certs/ext
openssl x509 -req -in .certs/server.csr -CA .certs/ca.crt -CAkey .certs/ca.key \
  -CAcreateserial -out .certs/server.crt -days 3650 -extfile .certs/ext >/dev/null 2>&1
openssl genrsa -out .certs/callback-private.pem 2048 >/dev/null 2>&1
openssl rsa -in .certs/callback-private.pem -pubout -out .certs/callback-public.pem >/dev/null 2>&1
openssl genrsa -out .certs/merchant-private.pem 2048 >/dev/null 2>&1
chmod 644 .certs/*
[ -s .certs/merchant-private.pem ] && pass "issued a TLS certificate and a merchant key" \
  || fail "certificate generation failed"

# --------------------------------------------------------------------------
# 2. The stack.
# --------------------------------------------------------------------------
say "Starting Magento and the API stub (create-project + install; slow)"
docker compose down -v >/dev/null 2>&1 || true
docker compose up -d --build --wait >/dev/null 2>&1

mg()   { docker compose exec -T magento "$@"; }
# Anything a root-run CLI step creates under var/ or generated/ leaves Apache
# unable to write it, and every web request then fails before reaching the
# controller. Run the scripts that touch those as the web user.
mgw()  { docker compose exec -T -u www-data magento "$@"; }
fixperms() { mg sh -c 'chown -R www-data:www-data /var/www/html/var /var/www/html/generated /var/www/html/pub 2>/dev/null' || true; }
stub() { docker compose exec -T spectrocoin "$@"; }
pw()   { docker compose exec -T playwright "$@"; }

mg sh -c 'cat /certs/ca.crt >> /etc/ssl/certs/ca-certificates.crt' >/dev/null 2>&1 || true
# The shop calls SpectroCoin over TLS from PHP, so it has to trust the CA this
# harness minted. Asserted rather than assumed: without it, checkout fails
# with cURL error 60 and the only visible symptom is a stalled place-order.
if mg sh -c 'curl -fsS -o /dev/null https://spectrocoin.com/__test/requests' >/dev/null 2>&1; then
  pass "the shop trusts the stub's certificate"
else
  fail "the shop cannot reach the stub over TLS - checkout will fail with cURL error 60"
fi

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
    --admin-firstname=Tier --admin-lastname=Three \
    --admin-email=tier3@example.com --admin-user=admin --admin-password=Tier3tier3 \
    --language=en_US --currency=EUR --timezone=UTC --use-rewrites=1 \
    --search-engine=opensearch --opensearch-host=opensearch --opensearch-port=9200 \
    --no-interaction 2>&1' > "$WORK/install.log" 2>&1 || true

if mg sh -c 'cd /var/www/html && php bin/magento --version' >/dev/null 2>&1; then
  pass "Magento installed ($(mg sh -c 'cd /var/www/html && php bin/magento --version' 2>/dev/null | tr -d '\r'))"
else
  fail "Magento install failed:"; tail -12 "$WORK/install.log" | sed 's/^/        /'
fi

# --------------------------------------------------------------------------
# 3. The module, installed the way the release is built.
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
  && pass "gateway configured (and Check/Money Order enabled as the checkout control)" \
  || { fail "could not configure the module:"; tail -5 "$WORK/config.log" | sed 's/^/        /'; }

if [ "$DISABLE_SPECTROCOIN" -eq 1 ]; then
  mg sh -c 'cd /var/www/html && php bin/magento config:set payment/spectrocoin_merchant/active 0 --no-interaction' \
    > "$WORK/disable.log" 2>&1 \
    && pass "NEGATIVE CONTROL: SpectroCoin disabled, nothing else changed" \
    || fail "could not disable SpectroCoin for the negative control"
fi

mg sh -c 'cd /var/www/html && php bin/magento cache:flush' >/dev/null 2>&1 || true
fixperms

# --------------------------------------------------------------------------
# 4. Something to buy.
# --------------------------------------------------------------------------
say "Setting up the shop"
docker compose cp create-product.php magento:/tmp/create-product.php >/dev/null 2>&1
mgw sh -c 'cd /var/www/html && rm -f /tmp/tier3-product.json && php /tmp/create-product.php > /tmp/tier3-product.json' \
  > "$WORK/product.log" 2>&1 || true
mgw sh -c 'cat /tmp/tier3-product.json 2>/dev/null' > "$WORK/product.json" 2>/dev/null || true
PRODUCT_ID=$(python3 -c "import json;print(json.load(open('$WORK/product.json')).get('id',''))" 2>/dev/null || true)
[ -n "$PRODUCT_ID" ] && pass "product created (#$PRODUCT_ID)" \
                     || { fail "no product could be created:"; tail -8 "$WORK/product.log" | sed 's/^/        /'; }

mg sh -c 'cd /var/www/html && php bin/magento indexer:reindex' > "$WORK/reindex.log" 2>&1 \
  && pass "catalog reindexed" \
  || { fail "reindex failed:"; tail -8 "$WORK/reindex.log" | sed 's/^/        /'; }
mg sh -c 'cd /var/www/html && php bin/magento cache:flush' >/dev/null 2>&1 || true
fixperms

stub curl -fsS -X POST http://localhost/__test/reset >/dev/null 2>&1

# --------------------------------------------------------------------------
# 5. The shopper.
# --------------------------------------------------------------------------
say "Walking a shopper through checkout"
PRODUCT_URL="http://shop.test/catalog/product/view/id/$PRODUCT_ID/"
# The image carries the browsers but not the client library; pin it to the
# image's own version so the two cannot drift apart.
pw sh -c 'cd /work && [ -d node_modules/playwright ] || npm --silent i playwright@1.50.0' \
  > "$WORK/npm.log" 2>&1 || true
pw sh -c 'node -e "require(\"playwright\")"' >/dev/null 2>&1 \
  && pass "browser client available" \
  || { fail "playwright module could not be installed:"; tail -4 "$WORK/npm.log" | sed 's/^/        /'; }

pw sh -c "SHOP_URL=http://shop.test PRODUCT_URL='$PRODUCT_URL' EXT_TITLE='Bitcoin via Spectrocoin' STOCK_TITLE='Check / Money Order' node /work/checkout.mjs" \
  > "$WORK/browser.log" 2>&1 || true

# A browser run that produces no verdicts at all is a failure in itself, not a
# silent pass - and `set -o pipefail` would otherwise abort the script here.
if ! grep -aqE '^(PASS|FAIL)' "$WORK/browser.log"; then
  fail "the browser run produced no verdicts:"
  tail -12 "$WORK/browser.log" | sed 's/^/        /'
fi

grep -aE '^(PASS|FAIL|INFO)' "$WORK/browser.log" 2>/dev/null | while read -r line; do
  case "$line" in
    PASS*) printf '  \033[32mPASS\033[0m  %s\n' "${line#PASS }" ;;
    FAIL*) printf '  \033[31mFAIL\033[0m  %s\n' "${line#FAIL }" ;;
    INFO*) printf '  \033[33mNOTE\033[0m  %s\n' "${line#INFO }" ;;
  esac
done
browser_failures=$(grep -ac '^FAIL' "$WORK/browser.log" 2>/dev/null || true)
browser_failures=${browser_failures:-0}
FAILED=$((FAILED + browser_failures))
if [ "$browser_failures" -gt 0 ]; then
  echo "        --- browser log tail ---"
  tail -20 "$WORK/browser.log" | sed 's/^/        /'
fi

# --------------------------------------------------------------------------
# 6. What SpectroCoin ended up with.
# --------------------------------------------------------------------------
say "Verifying the order that resulted"

# The shop's own grand total - product price plus flat-rate shipping - not a
# literal that would ignore shipping.
docker compose cp order-total.php magento:/tmp/order-total.php >/dev/null 2>&1
mgw sh -c 'php /tmp/order-total.php' > "$WORK/order.json" 2>/dev/null || true
oval() { python3 -c "import json;print(json.load(open('$WORK/order.json')).get('$1',''))" 2>/dev/null || true; }
ORDER_TOTAL=$(oval grand_total)
ORDER_CCY=$(oval currency)
if [ "$DISABLE_SPECTROCOIN" -eq 0 ] && [ -n "$ORDER_TOTAL" ]; then
  pass "Magento order $(oval increment_id) total is $ORDER_TOTAL $ORDER_CCY"
fi

stub curl -fsS http://localhost/__test/requests > "$WORK/requests.json" 2>/dev/null

created=$(python3 - "$WORK/requests.json" <<'PYEOF'
import json,sys
for r in json.load(open(sys.argv[1])):
    if r["path"].endswith("/createOrder"):
        print(json.dumps(r.get("post") or {}))
        break
PYEOF
)
[ -n "$created" ] || created='{}'
field() { printf '%s' "$created" | python3 -c "import json,sys;print(json.load(sys.stdin).get('$1',''))" 2>/dev/null; }

if [ "$DISABLE_SPECTROCOIN" -eq 1 ]; then
  # The negative control expects the opposite outcome: no request should ever
  # have reached SpectroCoin, because the gateway was never offered to pick.
  if [ -z "$(field orderId)" ]; then
    pass "NEGATIVE CONTROL: no create-order request reached SpectroCoin, as expected"
  else
    fail "NEGATIVE CONTROL: a create-order request reached SpectroCoin even though the gateway was disabled"
  fi
else
  if [ -n "$(field orderId)" ]; then
    pass "checkout produced a SpectroCoin order ($(field orderId))"
  else
    fail "checkout never reached SpectroCoin - no create-order request arrived"
  fi

  # The extension puts the amount on the pay side or the receive side
  # depending on payment_settings/order_payment_method (the shipped default
  # is "pay"), and its own matchesOrder() accepts either - so assert the
  # shop's own grand total (product price plus flat-rate shipping) arrived
  # on one of them, not on a particular one and not on a literal that
  # ignores shipping.
  if [ -n "$ORDER_TOTAL" ] && python3 -c "
import sys
def near(v):
    try: return abs(float(v) - float('$ORDER_TOTAL')) < 0.005
    except ValueError: return False
sys.exit(0 if near('$(field payAmount)') or near('$(field receiveAmount)') else 1)" 2>/dev/null; then
    pass "the order was sent for the shop's total ($ORDER_TOTAL)"
  else
    fail "neither payAmount '$(field payAmount)' nor receiveAmount '$(field receiveAmount)' is the shop's total '${ORDER_TOTAL:-unknown}'"
  fi
fi

# --------------------------------------------------------------------------
# 7. Magento log.
# --------------------------------------------------------------------------
say "Magento log"
log=$(mg sh -c 'cat /var/www/html/var/log/*.log 2>/dev/null || true')
ours=$(printf '%s\n' "$log" | grep -iE "critical|fatal|uncaught" | grep -iE "spectrocoin|guzzle" || true)
[ -z "$ours" ] && pass "no errors attributable to the module" \
  || { fail "errors in the log:"; printf '%s\n' "$ours" | head -6; }

if [ "$KEEP" -eq 1 ]; then
  echo -e "\nstack left running: add '127.0.0.1 shop.test' to /etc/hosts, then"
  echo    "http://shop.test:8096 (admin/Tier3tier3)"
else
  docker compose down -v >/dev/null 2>&1 || true
  rm -rf .certs
fi

echo
[ "$FAILED" -eq 0 ] && echo "tier 3 PASSED" || echo "tier 3 FAILED ($FAILED check(s))"
exit $([ "$FAILED" -eq 0 ] && echo 0 || echo 1)
