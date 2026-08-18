import { vi } from 'vitest';

/**
 * Markup matching what renderPayPage() emits.
 */
export const PAY_PAGE_HTML = `
  <section class="blink-pay-container" id="blink-pay">
    <div class="blink-pay-qr" id="blink-pay-qr" data-uri="lightning:LNBC100U1XYZ"></div>
    <code id="blink-pay-bolt11">lnbc100u1xyz</code>
    <button type="button" id="blink-pay-copy">Copy invoice</button>
    <p class="blink-pay-status" id="blink-pay-status"></p>
  </section>
`;

export function defaultConfig(overrides = {}) {
  return {
    ajaxUrl: 'https://shop.test/wp-admin/admin-ajax.php',
    nonce: 'abc123',
    orderId: 42,
    orderKey: 'wc_order_key',
    paymentRequest: 'lnbc100u1xyz',
    lightningUri: 'lightning:LNBC100U1XYZ',
    redirectUrl: 'https://shop.test/checkout/order-received/42/',
    pollInterval: 3000,
    deadline: Math.floor(Date.now() / 1000) + 3600,
    i18n: {},
    ...overrides,
  };
}

const registered = [];

/**
 * Records the listeners pay.js attaches so they can be removed again.
 *
 * jsdom keeps one document for the whole test file, so without this every
 * previously loaded copy of the script keeps polling and the request counts
 * from different tests bleed into each other.
 */
function trackListeners() {
  for (const target of [document, window]) {
    const original = target.addEventListener.bind(target);
    target.addEventListener = (type, listener, options) => {
      registered.push({ target, type, listener, options });
      original(type, listener, options);
    };
    registered.push({ target, restore: original });
  }
}

export function cleanupPayScript() {
  for (const entry of registered.splice(0)) {
    if (entry.restore) {
      entry.target.addEventListener = entry.restore;
      continue;
    }
    entry.target.removeEventListener(entry.type, entry.listener, entry.options);
  }
}

/**
 * Loads pay.js the way the browser would.
 *
 * The global has to exist before the module is imported, because the script
 * returns immediately when it is missing. Importing (rather than eval'ing the
 * file contents) is what lets the coverage instrumenter see the file at all --
 * a readFileSync + new Function approach runs fine and reports zero coverage,
 * which would make the threshold meaningless.
 */
export async function loadPayScript(config = defaultConfig(), html = PAY_PAGE_HTML) {
  cleanupPayScript();
  document.body.innerHTML = html;
  trackListeners();

  if (config !== null) {
    globalThis.BlinkPay = config;
  } else {
    delete globalThis.BlinkPay;
  }

  vi.resetModules();
  // No cache-busting query here: a unique module id per import makes the
  // coverage instrumenter treat each one as a separate file and split the
  // attribution. resetModules() alone is enough to re-execute the script.
  await import('../../../assets/js/frontend/pay.js');

  document.dispatchEvent(new Event('DOMContentLoaded'));
}

export function jsonResponse(data, status = 200) {
  return {
    ok: status >= 200 && status < 300,
    status,
    json: () => Promise.resolve(data),
  };
}

export function statusText() {
  return document.getElementById('blink-pay-status').textContent;
}
