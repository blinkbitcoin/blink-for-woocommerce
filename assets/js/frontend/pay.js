/* global BlinkPay, qrcode */
(function () {
  'use strict';

  if (typeof BlinkPay === 'undefined') {
    return;
  }

  function renderQr() {
    var container = document.getElementById('blink-pay-qr');
    if (!container || typeof qrcode === 'undefined') {
      return;
    }

    var data = container.getAttribute('data-uri') || BlinkPay.lightningUri;
    if (!data) {
      return;
    }

    try {
      // Type 0 = auto-detect the smallest fitting version. 'M' error correction.
      var qr = qrcode(0, 'M');
      qr.addData(data);
      qr.make();
      container.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 4, scalable: true });
    } catch (e) {
      // If the payload is too large for the default type, retry with high capacity.
      try {
        var qrBig = qrcode(0, 'L');
        qrBig.addData(data);
        qrBig.make();
        container.innerHTML = qrBig.createSvgTag({ cellSize: 4, margin: 4 });
      } catch (err) {
        container.textContent = data;
      }
    }
  }

  function setupCopy() {
    var button = document.getElementById('blink-pay-copy');
    if (!button) {
      return;
    }
    button.addEventListener('click', function () {
      var text = BlinkPay.paymentRequest || '';
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(function () {
          button.textContent = 'Copied!';
          setTimeout(function () {
            button.textContent = 'Copy invoice';
          }, 2000);
        });
      } else {
        var el = document.getElementById('blink-pay-bolt11');
        if (el) {
          var range = document.createRange();
          range.selectNode(el);
          window.getSelection().removeAllRanges();
          window.getSelection().addRange(range);
        }
      }
    });
  }

  function setStatus(message) {
    var el = document.getElementById('blink-pay-status');
    if (el) {
      el.textContent = message;
    }
  }

  function poll() {
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
      .then(function (res) {
        return res.json();
      })
      .then(function (json) {
        if (json && json.success && json.data) {
          if (json.data.status === 'PAID' && json.data.redirect) {
            setStatus('Payment received! Redirecting…');
            window.location.href = json.data.redirect;
            return;
          }
          if (json.data.status === 'EXPIRED') {
            setStatus('This invoice has expired. Please place the order again.');
            return;
          }
        }
        window.setTimeout(poll, BlinkPay.pollInterval || 3000);
      })
      .catch(function () {
        // Transient error: keep polling.
        window.setTimeout(poll, BlinkPay.pollInterval || 3000);
      });
  }

  document.addEventListener('DOMContentLoaded', function () {
    renderQr();
    setupCopy();
    window.setTimeout(poll, BlinkPay.pollInterval || 3000);
  });
})();
