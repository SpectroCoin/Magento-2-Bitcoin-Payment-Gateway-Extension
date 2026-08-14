#!/usr/bin/env bash
# ============================================================================
# Tier 1 smoke test — install the module into a real Magento 2 and prove it
# actually runs.
#
# The decisive check here is `setup:di:compile`. Magento resolves constructor
# dependencies ahead of time, so a wrong type hint, a missing argument or an
# unresolvable interface fails there — and nowhere else. Unit tests, and even
# enabling the module, will happily pass with a module that cannot compile.
#
# Uses Mage-OS, the auth-free Magento 2 distribution: repo.magento.com requires
# Adobe Marketplace keys and answers 401 without them.
#
# Usage:
#   ./smoke.sh          # install the working tree as a merchant would
#   ./smoke.sh --keep   # leave the stack running for inspection
# ============================================================================
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
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

# --------------------------------------------------------------------------
# 1. The module metadata Magento relies on.
# --------------------------------------------------------------------------
say "Checking module metadata"
php -r 'json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);' "$ROOT/composer.json" 2>/dev/null \
  && pass "composer.json is valid JSON" || fail "composer.json is not valid JSON"

grep -q '"type": *"magento2-module"' "$ROOT/composer.json" \
  && pass "declares type magento2-module" || fail "composer.json type is not magento2-module"

[ -f "$ROOT/registration.php" ] && pass "registration.php present" || fail "registration.php missing"
[ -f "$ROOT/etc/module.xml" ]   && pass "etc/module.xml present"   || fail "etc/module.xml missing"

grep -q "$MODULE" "$ROOT/registration.php" \
  && pass "registration.php registers $MODULE" || fail "registration.php does not register $MODULE"

# --------------------------------------------------------------------------
# 2. Real Magento (Mage-OS).
# --------------------------------------------------------------------------
say "Starting Magento (create-project + install; this takes many minutes)"
cd "$HERE"
docker compose down -v >/dev/null 2>&1 || true
docker compose up -d --build --wait >/dev/null 2>&1
mg() { docker compose exec -T magento "$@"; }

if mg sh -c '[ -f /var/www/html/bin/magento ]' 2>/dev/null; then
  pass "Magento source already present"
else
  mg sh -c 'composer create-project --quiet --no-interaction \
      --repository-url=https://repo.mage-os.org/ \
      mage-os/project-community-edition /var/www/html' > "$WORK/create.log" 2>&1 || true
  if mg sh -c '[ -f /var/www/html/bin/magento ]' 2>/dev/null; then
    pass "Mage-OS source installed"
  else
    fail "composer create-project failed:"; tail -10 "$WORK/create.log" | sed 's/^/        /'
  fi
fi

mg sh -c 'cd /var/www/html && php bin/magento setup:install \
    --base-url=http://localhost:8085/ \
    --db-host=db --db-name=magento --db-user=root --db-password=root \
    --admin-firstname=Smoke --admin-lastname=Admin \
    --admin-email=smoke@example.com --admin-user=admin --admin-password=Smokesmoke123 \
    --language=en_US --currency=USD --timezone=UTC --use-rewrites=1 \
    --search-engine=opensearch --opensearch-host=opensearch --opensearch-port=9200 \
    --no-interaction 2>&1' > "$WORK/install.log" 2>&1 || true

if mg sh -c 'cd /var/www/html && php bin/magento --version' >/dev/null 2>&1; then
  pass "Magento installed ($(mg sh -c 'cd /var/www/html && php bin/magento --version' 2>/dev/null | tr -d '\r'))"
else
  fail "Magento install failed:"; tail -12 "$WORK/install.log" | sed 's/^/        /'
fi

# --------------------------------------------------------------------------
# 3. Install the module exactly as a merchant would.
# --------------------------------------------------------------------------
say "Installing the module"
docker compose cp "$ROOT/." magento:/tmp/module >/dev/null 2>&1
mg sh -c "rm -rf /var/www/html/app/code/Spectrocoin/Merchant /tmp/module/tests /tmp/module/.git \
          && mkdir -p /var/www/html/app/code/Spectrocoin/Merchant \
          && cp -a /tmp/module/. /var/www/html/app/code/Spectrocoin/Merchant/ \
          && chown -R www-data:www-data /var/www/html/app/code" \
  && pass "module copied into app/code" || fail "module could not be copied"

mg sh -c "cd /var/www/html && php bin/magento module:enable $MODULE --no-interaction 2>&1" \
  > "$WORK/enable.log" 2>&1 || true
mg sh -c 'cd /var/www/html && php bin/magento setup:upgrade --no-interaction 2>&1' \
  > "$WORK/upgrade.log" 2>&1 || true

# --------------------------------------------------------------------------
# 4. Assertions that only a real Magento can make.
# --------------------------------------------------------------------------
say "Verifying inside the running installation"

status=$(mg sh -c "cd /var/www/html && php bin/magento module:status $MODULE 2>&1" | tr -d '\r' || true)
if printf '%s' "$status" | grep -qi "enabled"; then
  pass "module reports enabled"
else
  fail "module is NOT enabled ($status). enable said:"; sed 's/^/        /' "$WORK/enable.log" | head -6
fi

# The decisive check: Magento pre-resolves constructor dependencies here.
if mg sh -c 'cd /var/www/html && php bin/magento setup:di:compile --no-interaction 2>&1' \
     > "$WORK/di.log" 2>&1; then
  pass "setup:di:compile succeeds with the module enabled"
else
  fail "setup:di:compile FAILED - the module has unresolvable dependencies:"
  grep -iE "error|exception|spectrocoin" "$WORK/di.log" | head -8 | sed 's/^/        /'
fi

# The module's etc/config.xml must be merged into Magento's configuration.
# NB: `config:show payment` lists SAVED values only, and this method ships
# active=0 by design, so it legitimately has none. Read the merged config.
if mg sh -c 'cd /var/www/html && php -r "
  require \"app/bootstrap.php\";
  \$om = \Magento\Framework\App\Bootstrap::create(BP, [])->getObjectManager();
  \$cfg = \$om->get(\Magento\Framework\App\Config\ScopeConfigInterface::class);
  echo \$cfg->getValue(\"payment/spectrocoin_merchant/model\");
" 2>/dev/null' | grep -q 'Spectrocoin.Merchant.Model.Payment'; then
  pass "payment method merged into Magento configuration"
else
  fail "payment method NOT in the merged configuration - etc/config.xml was not picked up"
fi

# Classes must resolve through Magento's autoloader.
if mg sh -c 'cd /var/www/html && php -r "
  require \"vendor/autoload.php\";
  exit(class_exists(\"Spectrocoin\\\\Merchant\\\\Model\\\\Payment\") ? 0 : 1);"' >/dev/null 2>&1; then
  pass "Payment model resolves via autoload"
else
  fail "Payment model does NOT resolve via autoload"
fi

# --------------------------------------------------------------------------
# 5. Nothing may have been logged as an error.
# --------------------------------------------------------------------------
say "Magento log"
log=$(mg sh -c 'cat /var/www/html/var/log/*.log 2>/dev/null || true')
ours=$(printf '%s\n' "$log" | grep -iE "critical|fatal|uncaught" | grep -iE "spectrocoin|guzzle" || true)
[ -z "$ours" ] && pass "no errors attributable to the module" \
  || { fail "errors in the log:"; printf '%s\n' "$ours" | head -8; }

if [ "$KEEP" -eq 1 ]; then
  echo -e "\nstack left running: http://localhost:8085 (admin/Smokesmoke123)"
else
  docker compose down -v >/dev/null 2>&1 || true
fi

echo
[ "$FAILED" -eq 0 ] && echo "smoke test PASSED" || echo "smoke test FAILED ($FAILED check(s))"
exit $([ "$FAILED" -eq 0 ] && echo 0 || echo 1)
