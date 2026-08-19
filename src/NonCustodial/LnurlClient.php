<?php

declare(strict_types=1);

namespace Blink\WC\NonCustodial;

use Blink\WC\Http\HttpClientInterface;
use Blink\WC\Http\HttpRequestOptions;
use Blink\WC\Http\HttpResponse;
use Blink\WC\Support\LoggerInterface;

/**
 * Talks LNURL-pay (LUD-06), comments (LUD-12) and payment verification
 * (LUD-21) to whatever server backs the merchant's Lightning address.
 *
 * Everything this class receives is untrusted: the callback URL, the verify
 * URL and the invoice all come from a third party. Each URL goes through the
 * policy before it is fetched, and the invoice itself is checked separately by
 * InvoiceValidator.
 */
final class LnurlClient implements LnurlClientInterface {
  /** LUD-12 caps a comment at 255 characters. */
  public const MAX_COMMENT_LENGTH = 255;

  public function __construct(
    private HttpClientInterface $http,
    private UrlPolicyInterface $policy,
    private LoggerInterface $log
  ) {
  }

  public function fetchPayMetadata(LnAddress $address): PayMetadata|LnurlFailure {
    $url = $address->wellKnownUrl();

    // The well-known URL is derived from merchant configuration, but that
    // configuration can name any host, so it is checked like any other.
    $response = $this->fetch($url, $address);
    if ($response instanceof LnurlFailure) {
      return $response;
    }

    $json = $response->json();
    if ($json === null) {
      return $this->fail('LNURL_MALFORMED', 'pay metadata was not valid JSON', $url);
    }

    if (($json['status'] ?? null) === 'ERROR') {
      return $this->fail(
        'LNURL_ERROR',
        'server refused: ' . (string) ($json['reason'] ?? 'no reason given'),
        $url
      );
    }

    if (($json['tag'] ?? null) !== 'payRequest' || empty($json['callback'])) {
      return $this->fail('LNURL_NOT_PAYABLE', 'address does not offer LNURL-pay', $url);
    }

    return new PayMetadata(
      (string) $json['callback'],
      (int) ($json['minSendable'] ?? 0),
      (int) ($json['maxSendable'] ?? 0),
      (int) ($json['commentAllowed'] ?? 0),
      (string) ($json['metadata'] ?? '')
    );
  }

  public function requestInvoice(
    LnAddress $address,
    PayMetadata $metadata,
    int $amountMsat,
    string $comment
  ): InvoiceOffer|LnurlFailure {
    if ($amountMsat <= 0 || $amountMsat % 1000 !== 0) {
      return $this->fail(
        'LNURL_BAD_AMOUNT',
        'amount must be a positive whole number of satoshis, got ' .
          $amountMsat .
          ' msat',
        ''
      );
    }

    if (!$metadata->permits($amountMsat)) {
      return $this->fail(
        'LNURL_AMOUNT_OUT_OF_RANGE',
        sprintf(
          '%d msat is outside the range this address accepts (%d-%d msat)',
          $amountMsat,
          $metadata->minSendable,
          $metadata->maxSendable
        ),
        ''
      );
    }

    $query = ['amount' => $amountMsat];
    if ($comment !== '' && $metadata->commentAllowed > 0) {
      $limit = min($metadata->commentAllowed, self::MAX_COMMENT_LENGTH);
      // Truncate by characters, not bytes: a byte-wise cut can split a UTF-8
      // sequence and leave the server with an invalid string.
      $comment = $this->truncateComment($comment, $limit);
      if ($comment !== '') {
        $query['comment'] = $comment;
      }
    }

    // Note the deliberate absence of an "expiry" parameter. It is not part of
    // LUD-06, a server is free to ignore it, and relying on it is what led to
    // the plugin recording an expiry the invoice did not actually have.
    $separator = str_contains($metadata->callback, '?') ? '&' : '?';
    $url = $metadata->callback . $separator . http_build_query($query);

    $response = $this->fetch($url, $address);
    if ($response instanceof LnurlFailure) {
      return $response;
    }

    $json = $response->json();
    if ($json === null) {
      return $this->fail('LNURL_MALFORMED', 'callback response was not valid JSON', $url);
    }

    // LNURL reports its own errors with HTTP 200 and a status field.
    if (($json['status'] ?? null) === 'ERROR') {
      return $this->fail(
        'LNURL_ERROR',
        'server refused: ' . (string) ($json['reason'] ?? 'no reason given'),
        $url
      );
    }

    $paymentRequest = (string) ($json['pr'] ?? '');
    $verifyUrl = (string) ($json['verify'] ?? '');

    if ($paymentRequest === '') {
      return $this->fail('LNURL_NO_INVOICE', 'callback returned no invoice', $url);
    }

    // Without a verify URL there is no way to learn that the invoice was paid,
    // since this flow has no webhook.
    if ($verifyUrl === '') {
      return $this->fail(
        'LNURL_NO_VERIFY',
        'address does not support LUD-21 payment verification',
        $url
      );
    }

    $decision = $this->policy->check($verifyUrl, $address);
    if (!$decision->allowed) {
      return $this->fail(
        'LNURL_URL_REJECTED',
        'verify URL rejected: ' . $decision->reason,
        $verifyUrl
      );
    }

    return new InvoiceOffer(
      $paymentRequest,
      $verifyUrl,
      $this->paymentHashFromVerifyUrl($verifyUrl)
    );
  }

  /**
   * Truncate an optional LNURL comment without requiring PHP's mbstring extension.
   */
  private function truncateComment(string $comment, int $limit): string {
    $matched = preg_match('/^.{1,' . $limit . '}/us', $comment, $prefix);

    // A malformed UTF-8 comment is optional, so omit it rather than failing checkout.
    return $matched === 1 ? $prefix[0] : '';
  }

  public function verify(string $verifyUrl, LnAddress $address): VerifyResult {
    $decision = $this->policy->check($verifyUrl, $address);
    if (!$decision->allowed) {
      return VerifyResult::of(VerifyState::PolicyError, $decision->reason);
    }

    $response = $this->http->get(
      $verifyUrl,
      $this->options($address, $decision->dnsPins)
    );

    if ($response->failed()) {
      return VerifyResult::of(
        VerifyState::TransportError,
        (string) $response->transportError
      );
    }

    if ($response->truncated) {
      return VerifyResult::of(
        VerifyState::TransportError,
        'response exceeded the size limit'
      );
    }

    if ($response->status === 404) {
      return VerifyResult::of(
        VerifyState::NotFound,
        'verify endpoint reports no such invoice'
      );
    }

    if (!$response->ok()) {
      return VerifyResult::of(
        VerifyState::TransportError,
        'verify endpoint returned HTTP ' . $response->status
      );
    }

    $json = $response->json();
    if ($json === null) {
      return VerifyResult::of(
        VerifyState::TransportError,
        'verify response was not valid JSON'
      );
    }

    if (($json['status'] ?? null) === 'ERROR') {
      $reason = (string) ($json['reason'] ?? '');

      return stripos($reason, 'not found') !== false
        ? VerifyResult::of(VerifyState::NotFound, $reason)
        : VerifyResult::of(VerifyState::TransportError, 'server refused: ' . $reason);
    }

    // Never guess in either direction. Demanding a JSON boolean read a server
    // that emits "settled": 1 as *unsettled*, and an unsettled answer past the
    // grace period expires the order -- cancelling an order that was paid. But
    // reading a fuzzy value as settled would credit an order that was not.
    // So only a real boolean is an answer; anything else present is reported
    // as a transport error and retried.
    $settled = $this->readSettledFlag($json['settled'] ?? null);
    if ($settled === null) {
      return VerifyResult::of(
        VerifyState::TransportError,
        'verify response had an unreadable settled flag'
      );
    }

    if ($settled) {
      return new VerifyResult(
        VerifyState::Settled,
        isset($json['preimage']) ? (string) $json['preimage'] : null,
        isset($json['pr']) ? (string) $json['pr'] : null
      );
    }

    return VerifyResult::of(VerifyState::Unsettled);
  }

  /**
   * Reads a LUD-21 settled flag, or null when it cannot be read at all.
   *
   * LUD-21 specifies a boolean and nothing else is interpreted. A server that
   * answers some other way leaves the order unresolved -- held for a human by
   * UnpaidOrderGuard rather than credited or expired on a guess.
   *
   * @param mixed $value
   */
  private function readSettledFlag($value): ?bool {
    return is_bool($value) ? $value : null;
  }

  /**
   * Extracts the payment hash from a LUD-21 verify URL of the form
   * {origin}/verify/{payment_hash}.
   *
   * Returning null is not a soft failure. Without this hash there is nothing
   * binding the verify URL to the invoice the customer is shown, so the
   * validator refuses the offer outright rather than accepting it unchecked.
   */
  private function paymentHashFromVerifyUrl(string $verifyUrl): ?string {
    // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- wp_parse_url() only compensates for PHP < 5.4.7, and this class must stay free of WordPress.
    $path = parse_url($verifyUrl, PHP_URL_PATH);
    if (!is_string($path)) {
      return null;
    }

    $segments = explode('/', trim($path, '/'));
    $last = end($segments);

    return is_string($last) && preg_match('/^[a-f0-9]{64}$/i', $last) === 1
      ? strtolower($last)
      : null;
  }

  /** @return HttpResponse|LnurlFailure */
  private function fetch(string $url, LnAddress $address): HttpResponse|LnurlFailure {
    $decision = $this->policy->check($url, $address);
    if (!$decision->allowed) {
      return $this->fail('LNURL_URL_REJECTED', $decision->reason, $url);
    }

    $response = $this->http->get($url, $this->options($address, $decision->dnsPins));

    if ($response->failed()) {
      return $this->fail('LNURL_UNREACHABLE', (string) $response->transportError, $url);
    }

    if ($response->truncated) {
      return $this->fail('LNURL_OVERSIZED', 'response exceeded the size limit', $url);
    }

    if (!$response->ok()) {
      return $this->fail(
        'LNURL_HTTP_' . $response->status,
        'HTTP ' . $response->status,
        $url
      );
    }

    return $response;
  }

  /** @param array<string,list<string>> $dnsPins */
  private function options(LnAddress $address, array $dnsPins): HttpRequestOptions {
    return (new HttpRequestOptions())
      ->withDnsPins($dnsPins)
      ->withPlainHttpAllowed($address->isLocalDev());
  }

  private function fail(string $code, string $message, string $url): LnurlFailure {
    $this->log->error(
      'LNURL failure [' . $code . '] ' . $message . ($url === '' ? '' : ' (' . $url . ')')
    );

    return new LnurlFailure($code, $message);
  }
}
