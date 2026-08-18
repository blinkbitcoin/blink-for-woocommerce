/**
 * Pay page for non-custodial (lightning address) orders.
 *
 * Renders the invoice as a QR code and reports settlement. The browser does
 * not drive verification any more: it asks the server what it last observed,
 * and the server decides whether that warrants a live check.
 *
 * Deliberately a plain script with no build step. Nothing in this repository
 * compiles JavaScript, and adding a bundler for a page this size would mean
 * shipping generated code into the WordPress.org tree for no benefit.
 */
(function () {
  if (typeof BlinkPay === 'undefined') {
    return;
  }

  var BASE_DELAY = 3000;
  var MAX_DELAY = 20000;
  var GROWTH = 1.6;
  var JITTER = 0.25;
  var MAX_CONSECUTIVE_FAILURES = 10;

  /**
   * How many more times the server is asked after the local deadline passes.
   *
   * The deadline handed to this page is already the invoice expiry plus the
   * same grace the server allows, so it falls exactly when the server first
   * becomes willing to call the invoice dead. Stopping there meant giving up
   * one request short of a real answer. Six polls is roughly two minutes at
   * the delay ceiling.
   */
  var MAX_POST_DEADLINE_POLLS = 6;

  var delay = BASE_DELAY;
  var failures = 0;
  var postDeadlinePolls = 0;
  var timer = null;
  var stopped = false;

  var strings = BlinkPay.i18n || {};

  function text(key, fallback) {
    return strings[key] || fallback;
  }

  /**
   * wp_localize_script() would have turned this into a string. It is passed
   * with wp_json_encode now, but coercing anyway means a stale cached script
   * cannot silently disable the deadline again.
   */
  function deadline() {
    return Number(BlinkPay.deadline) || 0;
  }

  function pastDeadline() {
    var at = deadline();

    return at > 0 && Date.now() / 1000 > at;
  }

  function setStatus(message) {
    var el = document.getElementById('blink-pay-status');
    if (el) {
      el.textContent = message;
    }
  }

  function clearTimer() {
    if (timer !== null) {
      window.clearTimeout(timer);
      timer = null;
    }
  }

  /** Ends polling and optionally offers a manual retry. */
  function stop(message, allowRetry) {
    stopped = true;
    clearTimer();
    setStatus(message);

    if (allowRetry) {
      showRetry();
    }
  }

  function showRetry() {
    var container = document.getElementById('blink-pay-status');
    if (!container || document.getElementById('blink-pay-retry')) {
      return;
    }

    var button = document.createElement('button');
    button.id = 'blink-pay-retry';
    button.type = 'button';
    button.className = 'blink-pay-retry';
    button.textContent = text('checkAgain', 'Check again');
    button.addEventListener('click', function () {
      button.remove();
      stopped = false;
      failures = 0;
      delay = BASE_DELAY;
      schedule(0);
    });
    container.appendChild(button);
  }

  /** Exponential growth with jitter, so many tabs do not align. */
  function nextDelay(current, wasFailure) {
    var base = wasFailure
      ? Math.min(current * GROWTH, MAX_DELAY)
      : Math.min(current * 1.25, MAX_DELAY);
    var spread = base * JITTER;

    return Math.round(base - spread + Math.random() * 2 * spread);
  }

  function schedule(ms) {
    // No stopped-guard needed: stop() clears the pending timer, and every
    // caller that resumes clears the flag first.
    clearTimer();
    timer = window.setTimeout(poll, ms);
  }

  function renderQr() {
    var container = document.getElementById('blink-pay-qr');
    if (!container || typeof qrcode !== 'function') {
      return;
    }

    var uri = container.getAttribute('data-uri') || BlinkPay.lightningUri;
    if (!uri) {
      return;
    }

    try {
      var qr = qrcode(0, 'M');
      qr.addData(uri);
      qr.make();
      container.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 4, scalable: true });
    } catch {
      try {
        // A lower error-correction level fits more data in.
        var fallback = qrcode(0, 'L');
        fallback.addData(uri);
        fallback.make();
        container.innerHTML = fallback.createSvgTag({
          cellSize: 4,
          margin: 4,
          scalable: true,
        });
      } catch {
        // Better a copyable invoice than a broken image.
        container.textContent = BlinkPay.paymentRequest || '';
      }
    }
  }

  function setupCopy() {
    var button = document.getElementById('blink-pay-copy');
    if (!button) {
      return;
    }

    button.addEventListener('click', function () {
      var invoice = BlinkPay.paymentRequest || '';

      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(invoice).then(function () {
          button.textContent = text('copied', 'Copied!');
          window.setTimeout(function () {
            button.textContent = text('copy', 'Copy invoice');
          }, 2000);
        });

        return;
      }

      // Without the clipboard API, select the invoice so it can be copied.
      var code = document.getElementById('blink-pay-bolt11');
      if (code && window.getSelection) {
        var range = document.createRange();
        range.selectNodeContents(code);
        var selection = window.getSelection();
        selection.removeAllRanges();
        selection.addRange(range);
      }
    });
  }

  function onPaid(data) {
    stop(text('paid', 'Payment received! Redirecting…'), false);
    if (data.redirect) {
      window.location.assign(data.redirect);
    }
  }

  function handle(json) {
    if (!json || !json.success || !json.data) {
      return false;
    }

    var status = json.data.status;

    if (status === 'PAID') {
      onPaid(json.data);

      return true;
    }

    if (status === 'EXPIRED') {
      stop(
        text('expired', 'This invoice has expired. Please place the order again.'),
        false,
      );

      return true;
    }

    if (status === 'REVIEW') {
      stop(
        text(
          'review',
          'Payment received. This order is being reviewed before it is completed.',
        ),
        false,
      );

      return true;
    }

    return false;
  }

  function poll() {
    if (pastDeadline()) {
      // The server decides whether an invoice is dead, not this clock. Past
      // the deadline it gets a bounded number of further chances to say so,
      // and a definitive EXPIRED still ends polling through handle() with the
      // usual message. Only when it will not answer is the outcome genuinely
      // unknown -- and an unknown outcome must never tell a customer who may
      // already have paid to pay again.
      if (postDeadlinePolls >= MAX_POST_DEADLINE_POLLS) {
        stop(
          text(
            'unconfirmed',
            'We could not confirm this payment. If you have already paid, ' +
              'do not pay again — your order will be updated once the payment ' +
              'is confirmed.',
          ),
          false,
        );

        return;
      }

      postDeadlinePolls++;
    }

    var body = new URLSearchParams();
    body.append('action', 'blink_check_invoice');
    body.append('nonce', BlinkPay.nonce);
    body.append('order_id', BlinkPay.orderId);
    body.append('order_key', BlinkPay.orderKey);

    fetch(BlinkPay.ajaxUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString(),
      credentials: 'same-origin',
    })
      .then(function (response) {
        // An expired nonce answers 403 forever; retrying cannot help.
        if (response.status === 403) {
          stop(
            text(
              'invalid',
              'This payment session is no longer valid. Please reload the page.',
            ),
            false,
          );

          return null;
        }

        if (!response.ok) {
          throw new Error('HTTP ' + response.status);
        }

        return response.json();
      })
      .then(function (json) {
        if (json === null) {
          return;
        }

        failures = 0;

        if (handle(json)) {
          return;
        }

        delay = nextDelay(delay, false);
        schedule(delay);
      })
      .catch(function () {
        failures++;

        if (failures >= MAX_CONSECUTIVE_FAILURES) {
          stop(
            text(
              'unreachable',
              'We cannot reach the payment server right now. Any payment you have made is safe.',
            ),
            true,
          );

          return;
        }

        delay = nextDelay(delay, true);
        schedule(delay);
      });
  }

  /**
   * A hidden tab is not watching, and browsers throttle its timers rather than
   * stopping them, so polling would keep costing requests for nothing.
   */
  function setupVisibility() {
    document.addEventListener('visibilitychange', function () {
      if (document.hidden) {
        clearTimer();

        return;
      }

      if (!stopped) {
        delay = BASE_DELAY;
        schedule(0);
      }
    });

    window.addEventListener('pagehide', clearTimer);
  }

  document.addEventListener('DOMContentLoaded', function () {
    renderQr();
    setupCopy();
    setupVisibility();
    schedule(BlinkPay.pollInterval || BASE_DELAY);
  });
})();
