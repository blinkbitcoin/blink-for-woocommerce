import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
  PAY_PAGE_HTML,
  cleanupPayScript,
  defaultConfig,
  jsonResponse,
  loadPayScript,
  statusText,
} from './helpers/loadPayScript.js';

/** Runs pending timers and lets the fetch promise chain settle. */
async function advance(ms) {
  await vi.advanceTimersByTimeAsync(ms);
}

describe('pay page', () => {
  beforeEach(() => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2026-01-01T00:00:00Z'));
    globalThis.fetch = vi.fn(() => Promise.resolve(jsonResponse({ success: true, data: { status: 'PENDING' } })));
  });

  afterEach(() => {
    cleanupPayScript();
    vi.useRealTimers();
    delete globalThis.BlinkPay;
    delete globalThis.qrcode;
  });

  describe('bootstrap', () => {
    it('does nothing when the page did not provide any configuration', async () => {
      await loadPayScript(null);
      await advance(10000);

      expect(globalThis.fetch).not.toHaveBeenCalled();
    });

    it('starts polling after the configured interval', async () => {
      await loadPayScript();

      await advance(2999);
      expect(globalThis.fetch).not.toHaveBeenCalled();

      await advance(1);
      expect(globalThis.fetch).toHaveBeenCalledTimes(1);
    });

    it('posts the order identity the endpoint expects', async () => {
      await loadPayScript();
      await advance(3000);

      const [url, options] = globalThis.fetch.mock.calls[0];
      expect(url).toBe('https://shop.test/wp-admin/admin-ajax.php');
      expect(options.method).toBe('POST');
      expect(options.credentials).toBe('same-origin');
      expect(options.body).toContain('action=blink_check_invoice');
      expect(options.body).toContain('nonce=abc123');
      expect(options.body).toContain('order_id=42');
      expect(options.body).toContain('order_key=wc_order_key');
    });
  });

  describe('deadline', () => {
    /**
     * The original bug: wp_localize_script turns every value into a string, so
     * a `typeof deadline === 'number'` check was never true and the page
     * polled forever. The value is coerced now, so a string still works.
     */
    it('honours a deadline delivered as a string', async () => {
      const past = String(Math.floor(Date.now() / 1000) - 10);
      await loadPayScript(defaultConfig({ deadline: past }));

      await advance(3000);

      expect(globalThis.fetch).not.toHaveBeenCalled();
      expect(statusText()).toContain('expired');
    });

    it('honours a deadline delivered as a number', async () => {
      await loadPayScript(defaultConfig({ deadline: Math.floor(Date.now() / 1000) - 10 }));

      await advance(3000);

      expect(globalThis.fetch).not.toHaveBeenCalled();
      expect(statusText()).toContain('expired');
    });

    it('keeps polling while the deadline is in the future', async () => {
      await loadPayScript();

      await advance(3000);

      expect(globalThis.fetch).toHaveBeenCalledTimes(1);
    });

    it('stops once the deadline passes mid-session', async () => {
      await loadPayScript(defaultConfig({ deadline: Math.floor(Date.now() / 1000) + 10 }));

      await advance(3000);
      expect(globalThis.fetch).toHaveBeenCalledTimes(1);

      await advance(60000);
      const calls = globalThis.fetch.mock.calls.length;

      await advance(60000);
      expect(globalThis.fetch.mock.calls.length).toBe(calls);
      expect(statusText()).toContain('expired');
    });

    it('treats a missing deadline as no deadline rather than an expired one', async () => {
      await loadPayScript(defaultConfig({ deadline: 0 }));

      await advance(3000);

      expect(globalThis.fetch).toHaveBeenCalledTimes(1);
    });

    it('uses the translated expiry message when one is supplied', async () => {
      await loadPayScript(
        defaultConfig({
          deadline: Math.floor(Date.now() / 1000) - 1,
          i18n: { expired: 'Fakturan har gatt ut.' },
        })
      );

      await advance(3000);

      expect(statusText()).toBe('Fakturan har gatt ut.');
    });
  });

  describe('terminal states', () => {
    it('redirects when the payment is reported settled', async () => {
      const assign = vi.fn();
      Object.defineProperty(window, 'location', {
        value: { assign },
        writable: true,
      });
      globalThis.fetch = vi.fn(() =>
        Promise.resolve(
          jsonResponse({
            success: true,
            data: { status: 'PAID', redirect: 'https://shop.test/received/42/' },
          })
        )
      );

      await loadPayScript();
      await advance(3000);

      expect(assign).toHaveBeenCalledWith('https://shop.test/received/42/');
      expect(statusText()).toContain('Payment received');
    });

    it('stops polling once paid', async () => {
      globalThis.fetch = vi.fn(() =>
        Promise.resolve(jsonResponse({ success: true, data: { status: 'PAID' } }))
      );

      await loadPayScript();
      await advance(3000);
      await advance(120000);

      expect(globalThis.fetch).toHaveBeenCalledTimes(1);
    });

    it('stops and explains when the invoice is reported expired', async () => {
      globalThis.fetch = vi.fn(() =>
        Promise.resolve(jsonResponse({ success: true, data: { status: 'EXPIRED' } }))
      );

      await loadPayScript();
      await advance(3000);
      await advance(120000);

      expect(globalThis.fetch).toHaveBeenCalledTimes(1);
      expect(statusText()).toContain('expired');
    });

    it('explains when a paid order is held for review', async () => {
      globalThis.fetch = vi.fn(() =>
        Promise.resolve(jsonResponse({ success: true, data: { status: 'REVIEW' } }))
      );

      await loadPayScript();
      await advance(3000);

      expect(statusText()).toContain('reviewed');
    });

    it('stops on a rejected session rather than retrying forever', async () => {
      globalThis.fetch = vi.fn(() => Promise.resolve(jsonResponse({}, 403)));

      await loadPayScript();
      await advance(3000);
      await advance(300000);

      expect(globalThis.fetch).toHaveBeenCalledTimes(1);
      expect(statusText()).toContain('no longer valid');
    });

    it('keeps polling on an unsuccessful payload', async () => {
      globalThis.fetch = vi.fn(() => Promise.resolve(jsonResponse({ success: false })));

      await loadPayScript();
      await advance(3000);
      await advance(30000);

      expect(globalThis.fetch.mock.calls.length).toBeGreaterThan(1);
    });
  });

  describe('backoff', () => {
    /**
     * The previous version retried at a flat three seconds forever, so an
     * unreachable server was hit twelve hundred times an hour per open tab.
     */
    it('slows down after repeated failures', async () => {
      globalThis.fetch = vi.fn(() => Promise.reject(new Error('network down')));
      vi.spyOn(Math, 'random').mockReturnValue(0.5);

      await loadPayScript();
      await advance(3000);
      expect(globalThis.fetch).toHaveBeenCalledTimes(1);

      // 3000 * 1.6 = 4800, jitter at the midpoint leaves it unchanged.
      await advance(4799);
      expect(globalThis.fetch).toHaveBeenCalledTimes(1);
      await advance(1);
      expect(globalThis.fetch).toHaveBeenCalledTimes(2);

      // 4800 * 1.6 = 7680.
      await advance(7680);
      expect(globalThis.fetch).toHaveBeenCalledTimes(3);
    });

    it('caps the delay so polling never stalls completely', async () => {
      globalThis.fetch = vi.fn(() => Promise.reject(new Error('network down')));
      vi.spyOn(Math, 'random').mockReturnValue(0.5);

      await loadPayScript();
      for (let i = 0; i < 6; i++) {
        await advance(21000);
      }
      const calls = globalThis.fetch.mock.calls.length;

      await advance(20000);

      expect(globalThis.fetch.mock.calls.length).toBe(calls + 1);
    });

    it('spreads retries so many tabs do not align', async () => {
      globalThis.fetch = vi.fn(() => Promise.reject(new Error('network down')));

      vi.spyOn(Math, 'random').mockReturnValue(0);
      await loadPayScript();
      await advance(3000);
      // 4800 with jitter at its lower bound: 4800 - 1200 = 3600.
      await advance(3600);
      expect(globalThis.fetch).toHaveBeenCalledTimes(2);
    });

    it('gives up after a run of failures and offers a manual retry', async () => {
      globalThis.fetch = vi.fn(() => Promise.reject(new Error('network down')));
      vi.spyOn(Math, 'random').mockReturnValue(0.5);

      await loadPayScript();
      for (let i = 0; i < 15; i++) {
        await advance(25000);
      }

      expect(globalThis.fetch.mock.calls.length).toBe(10);
      expect(statusText()).toContain('cannot reach');
      expect(document.getElementById('blink-pay-retry')).not.toBeNull();
    });

    it('offers the retry only once however many failures follow', async () => {
      globalThis.fetch = vi.fn(() => Promise.reject(new Error('network down')));
      vi.spyOn(Math, 'random').mockReturnValue(0.5);

      await loadPayScript();
      for (let i = 0; i < 15; i++) {
        await advance(25000);
      }

      document.getElementById('blink-pay-retry').click();
      await advance(1);
      for (let i = 0; i < 15; i++) {
        await advance(25000);
      }

      expect(document.querySelectorAll('#blink-pay-retry').length).toBe(1);
    });

    it('survives a page with nowhere to show the retry', async () => {
      globalThis.fetch = vi.fn(() => Promise.reject(new Error('network down')));
      vi.spyOn(Math, 'random').mockReturnValue(0.5);

      await loadPayScript(defaultConfig(), '<div id="blink-pay-qr"></div>');
      for (let i = 0; i < 15; i++) {
        await advance(25000);
      }

      expect(document.getElementById('blink-pay-retry')).toBeNull();
    });

    it('resumes when the customer asks it to check again', async () => {
      globalThis.fetch = vi.fn(() => Promise.reject(new Error('network down')));
      vi.spyOn(Math, 'random').mockReturnValue(0.5);

      await loadPayScript();
      for (let i = 0; i < 15; i++) {
        await advance(25000);
      }
      const before = globalThis.fetch.mock.calls.length;

      document.getElementById('blink-pay-retry').click();
      await advance(1);

      expect(globalThis.fetch.mock.calls.length).toBe(before + 1);
      expect(document.getElementById('blink-pay-retry')).toBeNull();
    });

    it('a recovered request clears the failure count', async () => {
      let fail = true;
      globalThis.fetch = vi.fn(() =>
        fail
          ? Promise.reject(new Error('down'))
          : Promise.resolve(jsonResponse({ success: true, data: { status: 'PENDING' } }))
      );
      vi.spyOn(Math, 'random').mockReturnValue(0.5);

      await loadPayScript();
      await advance(3000);
      fail = false;
      await advance(60000);

      // Still polling, and never reached the give-up state.
      expect(statusText()).not.toContain('cannot reach');
    });

    it('treats a server error as a failure to back off from', async () => {
      globalThis.fetch = vi.fn(() => Promise.resolve(jsonResponse({}, 500)));
      vi.spyOn(Math, 'random').mockReturnValue(0.5);

      await loadPayScript();
      await advance(3000);
      await advance(4800);

      expect(globalThis.fetch).toHaveBeenCalledTimes(2);
    });
  });

  describe('visibility', () => {
    function setHidden(hidden) {
      Object.defineProperty(document, 'hidden', { value: hidden, configurable: true });
      document.dispatchEvent(new Event('visibilitychange'));
    }

    it('stops polling while the tab is hidden', async () => {
      await loadPayScript();
      await advance(3000);
      const calls = globalThis.fetch.mock.calls.length;

      setHidden(true);
      await advance(120000);

      expect(globalThis.fetch.mock.calls.length).toBe(calls);
    });

    it('checks immediately when the tab is shown again', async () => {
      await loadPayScript();
      await advance(3000);
      setHidden(true);
      await advance(60000);
      const calls = globalThis.fetch.mock.calls.length;

      setHidden(false);
      await advance(1);

      expect(globalThis.fetch.mock.calls.length).toBe(calls + 1);
    });

    it('does not resume a session that has already finished', async () => {
      globalThis.fetch = vi.fn(() =>
        Promise.resolve(jsonResponse({ success: true, data: { status: 'EXPIRED' } }))
      );

      await loadPayScript();
      await advance(3000);
      const calls = globalThis.fetch.mock.calls.length;

      setHidden(true);
      setHidden(false);
      await advance(10000);

      expect(globalThis.fetch.mock.calls.length).toBe(calls);
    });

    it('cancels pending work when the page goes away', async () => {
      await loadPayScript();
      await advance(3000);
      const calls = globalThis.fetch.mock.calls.length;

      window.dispatchEvent(new Event('pagehide'));
      await advance(120000);

      expect(globalThis.fetch.mock.calls.length).toBe(calls);
    });
  });

  describe('qr code', () => {
    it('renders the invoice as an SVG', async () => {
      const make = vi.fn();
      const addData = vi.fn();
      globalThis.qrcode = vi.fn(() => ({
        addData,
        make,
        createSvgTag: () => '<svg id="rendered"></svg>',
      }));

      await loadPayScript();

      expect(addData).toHaveBeenCalledWith('lightning:LNBC100U1XYZ');
      expect(document.getElementById('blink-pay-qr').innerHTML).toContain('rendered');
    });

    it('retries at a lower error-correction level when the data does not fit', async () => {
      let attempt = 0;
      globalThis.qrcode = vi.fn(() => {
        attempt++;
        if (attempt === 1) {
          return {
            addData: () => {},
            make: () => {
              throw new Error('too much data');
            },
            createSvgTag: () => '',
          };
        }

        return {
          addData: () => {},
          make: () => {},
          createSvgTag: () => '<svg id="fallback"></svg>',
        };
      });

      await loadPayScript();

      expect(document.getElementById('blink-pay-qr').innerHTML).toContain('fallback');
    });

    it('falls back to showing the invoice when no QR can be drawn', async () => {
      globalThis.qrcode = vi.fn(() => ({
        addData: () => {},
        make: () => {
          throw new Error('nope');
        },
        createSvgTag: () => '',
      }));

      await loadPayScript();

      expect(document.getElementById('blink-pay-qr').textContent).toBe('lnbc100u1xyz');
    });

    it('does nothing when the QR library is unavailable', async () => {
      await loadPayScript();

      expect(document.getElementById('blink-pay-qr').innerHTML).toBe('');
    });

    it('does nothing when there is no container', async () => {
      await loadPayScript(defaultConfig(), '<p id="blink-pay-status"></p>');

      expect(document.getElementById('blink-pay-qr')).toBeNull();
    });

    it('does nothing when there is no invoice to encode', async () => {
      globalThis.qrcode = vi.fn();

      await loadPayScript(
        defaultConfig({ lightningUri: '' }),
        '<div id="blink-pay-qr"></div><p id="blink-pay-status"></p>'
      );

      expect(globalThis.qrcode).not.toHaveBeenCalled();
    });

    it('falls back to the configured uri when the element carries none', async () => {
      const addData = vi.fn();
      globalThis.qrcode = vi.fn(() => ({
        addData,
        make: () => {},
        createSvgTag: () => '<svg></svg>',
      }));

      await loadPayScript(
        defaultConfig(),
        '<div id="blink-pay-qr"></div><p id="blink-pay-status"></p>'
      );

      expect(addData).toHaveBeenCalledWith('lightning:LNBC100U1XYZ');
    });
  });

  describe('defensive fallbacks', () => {
    it('works when the page supplies no translations at all', async () => {
      const config = defaultConfig();
      delete config.i18n;
      config.deadline = Math.floor(Date.now() / 1000) - 1;

      await loadPayScript(config);
      await advance(3000);

      expect(statusText()).toContain('expired');
    });

    it('falls back to the default interval when none is configured', async () => {
      const config = defaultConfig();
      delete config.pollInterval;

      await loadPayScript(config);
      await advance(3000);

      expect(globalThis.fetch).toHaveBeenCalledTimes(1);
    });

    it('renders an empty placeholder when there is no invoice to fall back to', async () => {
      globalThis.qrcode = vi.fn(() => ({
        addData: () => {},
        make: () => {
          throw new Error('nope');
        },
        createSvgTag: () => '',
      }));
      const config = defaultConfig();
      delete config.paymentRequest;

      await loadPayScript(config);

      expect(document.getElementById('blink-pay-qr').textContent).toBe('');
    });

    it('copies an empty string rather than undefined when no invoice is set', async () => {
      const writeText = vi.fn(() => Promise.resolve());
      Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true });
      const config = defaultConfig();
      delete config.paymentRequest;

      await loadPayScript(config);
      document.getElementById('blink-pay-copy').click();
      await advance(1);

      expect(writeText).toHaveBeenCalledWith('');
    });

    it('does not attempt to select an invoice element that is not there', async () => {
      Object.defineProperty(navigator, 'clipboard', { value: undefined, configurable: true });

      await loadPayScript(
        defaultConfig(),
        '<button id="blink-pay-copy">Copy</button><p id="blink-pay-status"></p>'
      );

      expect(() => document.getElementById('blink-pay-copy').click()).not.toThrow();
    });
  });

  describe('copy button', () => {
    it('copies the invoice and confirms', async () => {
      const writeText = vi.fn(() => Promise.resolve());
      Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true });

      await loadPayScript();
      document.getElementById('blink-pay-copy').click();
      await advance(1);

      expect(writeText).toHaveBeenCalledWith('lnbc100u1xyz');
      expect(document.getElementById('blink-pay-copy').textContent).toBe('Copied!');
    });

    it('restores the label after a moment', async () => {
      Object.defineProperty(navigator, 'clipboard', {
        value: { writeText: () => Promise.resolve() },
        configurable: true,
      });

      await loadPayScript();
      document.getElementById('blink-pay-copy').click();
      await advance(2001);

      expect(document.getElementById('blink-pay-copy').textContent).toBe('Copy invoice');
    });

    it('uses translated labels when supplied', async () => {
      Object.defineProperty(navigator, 'clipboard', {
        value: { writeText: () => Promise.resolve() },
        configurable: true,
      });

      await loadPayScript(defaultConfig({ i18n: { copied: 'Kopierad!', copy: 'Kopiera' } }));
      document.getElementById('blink-pay-copy').click();
      await advance(1);

      expect(document.getElementById('blink-pay-copy').textContent).toBe('Kopierad!');
    });

    it('selects the invoice when the clipboard API is unavailable', async () => {
      Object.defineProperty(navigator, 'clipboard', { value: undefined, configurable: true });

      await loadPayScript();
      document.getElementById('blink-pay-copy').click();

      expect(window.getSelection().toString()).toContain('lnbc100u1xyz');
    });

    it('does nothing when there is no copy button', async () => {
      await loadPayScript(defaultConfig(), '<p id="blink-pay-status"></p>');

      expect(document.getElementById('blink-pay-copy')).toBeNull();
    });
  });
});
