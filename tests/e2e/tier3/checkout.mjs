/**
 * Drives a real shopper through Magento's checkout in a real browser.
 *
 * Tier 2 calls getSpectrocoinResponse() directly, which proves the payload
 * and the signed-callback contract but never that a shopper can reach the
 * gateway. Magento's checkout is a Knockout/UI-component app assembled from
 * jsLayout XML merges, and a payment method can be wired up correctly on the
 * backend and still never render at checkout - so the only way to answer
 * "can a shopper pay with this" is to walk one there.
 *
 * Prints "PASS <name>" / "FAIL <name>" / "INFO <name>" lines for the shell
 * wrapper to count, and exits non-zero if anything failed.
 */

import { chromium } from 'playwright';

const SHOP = process.env.SHOP_URL || 'http://shop.test';
const PRODUCT_URL = process.env.PRODUCT_URL;
const TITLE = process.env.EXT_TITLE || 'Bitcoin via Spectrocoin';
const STOCK_TITLE = process.env.STOCK_TITLE || 'Check / Money Order';

let failed = 0;
const pass = (m) => console.log(`PASS ${m}`);
const fail = (m) => { failed++; console.log(`FAIL ${m}`); };
const info = (m) => console.log(`INFO ${m}`);

const browser = await chromium.launch();
// The stub answers as spectrocoin.com with a certificate from a CA the
// harness minted, which the browser has no reason to trust.
const ctx = await browser.newContext({ ignoreHTTPSErrors: true });
const page = await ctx.newPage();
page.setDefaultTimeout(30000);

const shot = async (name) => {
  try { await page.screenshot({ path: `/work/artifacts/${name}.png`, fullPage: true }); } catch {}
};

const fillFirst = async (selectors, value) => {
  for (const sel of selectors) {
    const el = page.locator(sel).first();
    if (await el.count()) { await el.fill(value).catch(() => {}); return true; }
  }
  return false;
};

const clickFirst = async (selectors) => {
  for (const sel of selectors) {
    const el = page.locator(sel).first();
    if (await el.count() && await el.isVisible().catch(() => false)) { await el.click().catch(() => {}); return true; }
  }
  return false;
};

try {
  // ---- add to cart ------------------------------------------------------
  await page.goto(PRODUCT_URL, { waitUntil: 'domcontentloaded' });
  const addToCart = page.locator('#product-addtocart-button, button.tocart').first();
  if (await addToCart.count()) {
    await addToCart.click();
    await page.waitForTimeout(2500);
    pass('product can be added to the cart');
  } else {
    fail('no add-to-cart button on the product page');
    await shot('product');
  }

  // ---- checkout ---------------------------------------------------------
  await page.goto(`${SHOP}/checkout/`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(4000); // the checkout UI-components hydrate client-side

  const onCheckout = await page.locator('.opc-wrapper, [data-role="checkout-form"], .checkout-shipping-address').count() > 0;
  console.log(`INFO checkout app loaded: ${onCheckout}`);

  // ---- shipping: shopper details -----------------------------------------
  await fillFirst(['#customer-email'], 'tier3@example.com');
  await fillFirst(['input[name="firstname"]'], 'Tier');
  await fillFirst(['input[name="lastname"]'], 'Three');
  await fillFirst(['input[name="street[0]"]'], '1 Test Street');
  await fillFirst(['input[name="city"]'], 'Vilnius');
  await fillFirst(['input[name="postcode"]'], '01100');
  await fillFirst(['input[name="telephone"]'], '00000000');

  const country = page.locator('select[name="country_id"]').first();
  if (await country.count()) {
    await country.selectOption('LT').catch(() => {});
  }
  // Give the region dropdown a chance to repopulate for the chosen country,
  // then pick whatever it offers - the fixture has no interest in address
  // validation, only in reaching the payment step.
  await page.waitForTimeout(1000);
  const region = page.locator('select[name="region_id"]').first();
  if (await region.count() && await region.isVisible().catch(() => false)) {
    const options = await region.locator('option').count();
    if (options > 1) await region.selectOption({ index: 1 }).catch(() => {});
  }

  const shipToThis = await clickFirst(['button.action.primary.action-save-address', 'button[data-role="opc-continue"]']);
  if (shipToThis) {
    pass('shopper details were accepted');
  } else {
    fail('no continue button on the shipping-address step');
    await shot('shipping-address');
  }
  await page.waitForTimeout(3000);

  // ---- shipping method ----------------------------------------------------
  const shippingRadio = page.locator('#checkout-shipping-method-load input[type="radio"], .table-checkout-shipping-method input[type="radio"]').first();
  if (await shippingRadio.count()) {
    await shippingRadio.check().catch(() => {});
    pass('a shipping method can be chosen');
  } else {
    fail('no shipping method was offered - the shop cannot ship the order');
    await shot('shipping-method-empty');
  }
  await clickFirst(['button.button.action.continue.primary', 'button[data-role="opc-continue"]']);
  await page.waitForTimeout(3000);

  // ---- payment: the assertion this tier exists for -----------------------
  const onPayment = await page.locator('.payment-method, #checkout-payment-method-load').count() > 0;
  info(`reached payment step: ${onPayment}`);

  // Decisive control: if the payment step is empty for every method, that's
  // a fixture problem, not evidence against our extension.
  const stockOffered = await page.getByText(STOCK_TITLE, { exact: false }).count();
  if (stockOffered > 0) {
    pass(`a stock payment method (${STOCK_TITLE}) is offered too (control for an empty payment step)`);
  } else {
    fail(`no stock payment method is offered, not even ${STOCK_TITLE} - this is a fixture problem, not the extension`);
    await shot('payment-method-no-control');
  }

  const offered = await page.getByText(TITLE, { exact: false }).count();
  if (offered > 0) {
    pass('the gateway is offered at checkout');
  } else {
    fail('the gateway is NOT offered at checkout');
    await shot('payment-method-missing');
  }

  const radio = page.locator('input[value="spectrocoin_merchant"], #spectrocoin_merchant').first();
  if (await radio.count()) {
    await radio.check({ force: true }).catch(() => {});
    pass('the gateway can be selected');
  } else {
    fail('the gateway could not be selected');
    await shot('payment-method-select');
  }
  await page.waitForTimeout(1500);

  // ---- place the order --------------------------------------------------
  // The template renders one "Place Order" button per payment method, kept
  // in the DOM but hidden/disabled until its method is the selected one -
  // so scope the click to the active method's container.
  const placeOrder = page.locator(
    '.payment-method._active button.action.primary.checkout, ' +
    '.payment-method._active button:has-text("Place Order"), ' +
    'button.action.primary.checkout:visible'
  ).first();

  if (!(await placeOrder.count())) {
    fail('no place-order button once the gateway is selected');
    await shot('checkout-no-button');
  } else {
    await Promise.all([
      page.waitForURL(/spectrocoin\.com\/pay\//, { timeout: 45000 }).catch(() => {}),
      placeOrder.click({ force: true }),
    ]);
    await page.waitForTimeout(3000);

    const url = page.url();
    info(`landed on: ${url}`);
    if (/spectrocoin\.com\/pay\//.test(url)) {
      pass('placing the order redirects the shopper to SpectroCoin');
    } else {
      fail(`placing the order did not redirect to SpectroCoin (landed on ${url})`);
      await shot('after-place-order');
    }
  }
} catch (err) {
  fail(`browser run threw: ${err.message.split('\n')[0]}`);
  await shot('threw');
} finally {
  await browser.close();
}

process.exit(failed === 0 ? 0 : 1);
