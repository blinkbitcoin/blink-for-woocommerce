jQuery(document).ready(function ($) {
  jQuery(document).on(
    'click',
    '.blink-review-notice button.blink-review-dismiss',
    function (e) {
      e.preventDefault();
      $.ajax({
        url: BlinkNotifications.ajax_url,
        type: 'post',
        data: {
          action: 'blink_notifications',
          nonce: BlinkNotifications.nonce,
        },
        success: function (data) {
          jQuery('.blink-review-notice').remove();
        },
      });
    },
  );
  jQuery(document).on(
    'click',
    '.blink-review-notice button.blink-review-dismiss-forever',
    function (e) {
      e.preventDefault();
      $.ajax({
        url: BlinkNotifications.ajax_url,
        type: 'post',
        data: {
          action: 'blink_notifications',
          nonce: BlinkNotifications.nonce,
          dismiss_forever: true,
        },
        success: function (data) {
          jQuery('.blink-review-notice').remove();
        },
      });
    },
  );
});
