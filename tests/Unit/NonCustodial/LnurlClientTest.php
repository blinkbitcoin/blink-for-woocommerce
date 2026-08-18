<?php

declare(strict_types=1);

namespace Blink\WC\Tests\Unit\NonCustodial;

use Blink\WC\Http\HttpResponse;
use Blink\WC\NonCustodial\LnAddress;
use Blink\WC\NonCustodial\LnurlClient;
use Blink\WC\NonCustodial\LnurlFailure;
use Blink\WC\NonCustodial\PayMetadata;
use Blink\WC\NonCustodial\UrlPolicy;
use Blink\WC\NonCustodial\VerifyState;
use Blink\WC\Tests\Support\Fake\FakeDnsResolver;
use Blink\WC\Tests\Support\Fake\FakeHttpClient;
use Blink\WC\Tests\Support\Fake\SpyLogger;
use PHPUnit\Framework\TestCase;

final class LnurlClientTest extends TestCase {
  private FakeHttpClient $http;
  private SpyLogger $log;
  private LnurlClient $client;
  private LnAddress $address;

  protected function setUp(): void {
    parent::setUp();
    $this->http = new FakeHttpClient();
    $this->log = new SpyLogger();
    $policy = new UrlPolicy(
      (new FakeDnsResolver())->fallbackTo('93.184.216.34'),
      $this->log
    );
    $this->client = new LnurlClient($this->http, $policy, $this->log);
    $this->address = LnAddress::parse('shop@blink.sv');
  }

  private function metadata(array $overrides = []): PayMetadata {
    return new PayMetadata(
      $overrides['callback'] ?? 'https://blink.sv/lnurlp/shop/callback',
      $overrides['minSendable'] ?? 1000,
      $overrides['maxSendable'] ?? 100000000000,
      $overrides['commentAllowed'] ?? 255,
      $overrides['metadata'] ?? '[["text/plain","Order GW-1"]]'
    );
  }

  // ---------------------------------------------------------------- metadata

  public function testFetchesPayMetadata(): void {
    $this->http->queueJson([
      'tag' => 'payRequest',
      'callback' => 'https://blink.sv/lnurlp/shop/callback',
      'minSendable' => 1000,
      'maxSendable' => 100000000,
      'commentAllowed' => 128,
      'metadata' => '[["text/plain","hi"]]',
    ]);

    $result = $this->client->fetchPayMetadata($this->address);

    $this->assertInstanceOf(PayMetadata::class, $result);
    $this->assertSame('https://blink.sv/lnurlp/shop/callback', $result->callback);
    $this->assertSame(1000, $result->minSendable);
    $this->assertSame(100000000, $result->maxSendable);
    $this->assertSame(128, $result->commentAllowed);
    $this->assertSame('[["text/plain","hi"]]', $result->metadata);
  }

  public function testFetchesMetadataFromTheWellKnownUrl(): void {
    $this->http->queueJson(['tag' => 'payRequest', 'callback' => 'https://blink.sv/cb']);

    $this->client->fetchPayMetadata($this->address);

    $this->assertSame('https://blink.sv/.well-known/lnurlp/shop', $this->http->lastUrl());
  }

  /**
   * The well-known URL is built from merchant configuration, but that
   * configuration can name any host, so it goes through the policy too.
   */
  public function testWellKnownUrlIsCheckedAgainstThePolicy(): void {
    $policy = new UrlPolicy((new FakeDnsResolver())->fallbackTo('127.0.0.1'), $this->log);
    $client = new LnurlClient($this->http, $policy, $this->log);

    $result = $client->fetchPayMetadata(LnAddress::parse('shop@internal.blink.sv'));

    $this->assertInstanceOf(LnurlFailure::class, $result);
    $this->assertSame('LNURL_URL_REJECTED', $result->code);
    $this->assertSame(0, $this->http->requestCount(), 'no request may be made at all');
  }

  public function testMetadataMissingThePayRequestTagIsRejected(): void {
    $this->http->queueJson([
      'tag' => 'withdrawRequest',
      'callback' => 'https://blink.sv/cb',
    ]);

    $result = $this->client->fetchPayMetadata($this->address);

    $this->assertInstanceOf(LnurlFailure::class, $result);
    $this->assertSame('LNURL_NOT_PAYABLE', $result->code);
  }

  public function testMetadataWithoutACallbackIsRejected(): void {
    $this->http->queueJson(['tag' => 'payRequest']);

    $result = $this->client->fetchPayMetadata($this->address);

    $this->assertInstanceOf(LnurlFailure::class, $result);
    $this->assertSame('LNURL_NOT_PAYABLE', $result->code);
  }

  public function testMalformedMetadataIsRejected(): void {
    $this->http->queue(new HttpResponse(200, '{"callback":'));

    $result = $this->client->fetchPayMetadata($this->address);

    $this->assertInstanceOf(LnurlFailure::class, $result);
    $this->assertSame('LNURL_MALFORMED', $result->code);
  }

  public function testLnurlErrorBodyIsReportedWithItsReason(): void {
    $this->http->queueJson(['status' => 'ERROR', 'reason' => 'Wallet unavailable']);

    $result = $this->client->fetchPayMetadata($this->address);

    $this->assertInstanceOf(LnurlFailure::class, $result);
    $this->assertSame('LNURL_ERROR', $result->code);
    $this->assertStringContainsString('Wallet unavailable', $result->message);
  }

  public function testLnurlErrorWithoutAReasonStillReports(): void {
    $this->http->queueJson(['status' => 'ERROR']);

    $result = $this->client->fetchPayMetadata($this->address);

    $this->assertInstanceOf(LnurlFailure::class, $result);
    $this->assertStringContainsString('no reason given', $result->message);
  }

  /**
   * The behaviour the old client could not express: a 404 is a different fact
   * from a network failure, and both are different from a malformed body.
   */
  public function testHttpErrorStatusIsReportedWithItsCode(): void {
    $this->http->queue(new HttpResponse(500, 'Internal Server Error'));

    $result = $this->client->fetchPayMetadata($this->address);

    $this->assertInstanceOf(LnurlFailure::class, $result);
    $this->assertSame('LNURL_HTTP_500', $result->code);
  }

  public function testTransportFailureIsDistinctFromAServerError(): void {
    $this->http->queue(HttpResponse::transportFailure('connect timeout'));

    $result = $this->client->fetchPayMetadata($this->address);

    $this->assertInstanceOf(LnurlFailure::class, $result);
    $this->assertSame('LNURL_UNREACHABLE', $result->code);
  }

  public function testOversizedResponseIsRejected(): void {
    $this->http->queue(new HttpResponse(200, '{}', [], null, true));

    $result = $this->client->fetchPayMetadata($this->address);

    $this->assertInstanceOf(LnurlFailure::class, $result);
    $this->assertSame('LNURL_OVERSIZED', $result->code);
  }

  public function testARedirectIsTreatedAsAFailureRatherThanFollowed(): void {
    $this->http->queue(
      new HttpResponse(302, '', ['location' => 'https://elsewhere.test/'])
    );

    $result = $this->client->fetchPayMetadata($this->address);

    $this->assertInstanceOf(LnurlFailure::class, $result);
    $this->assertSame('LNURL_HTTP_302', $result->code);
    $this->assertSame(1, $this->http->requestCount());
  }

  // ----------------------------------------------------------------- invoice

  public function testRequestsAnInvoiceAndReturnsTheOffer(): void {
    $this->http->queueJson([
      'pr' => 'lnbc100u1xyz',
      'verify' => 'https://blink.sv/verify/' . str_repeat('ab', 32),
    ]);

    $result = $this->client->requestInvoice(
      $this->address,
      $this->metadata(),
      10000000,
      ''
    );

    $this->assertNotInstanceOf(LnurlFailure::class, $result);
    $this->assertSame('lnbc100u1xyz', $result->paymentRequest);
    $this->assertSame(str_repeat('ab', 32), $result->verifyUrlPaymentHash);
  }

  public function testAmountIsSentOnTheCallbackQuery(): void {
    $this->http->queueJson(['pr' => 'lnbc1', 'verify' => 'https://blink.sv/verify/x']);

    $this->client->requestInvoice($this->address, $this->metadata(), 10000000, '');

    $this->assertStringContainsString('amount=10000000', (string) $this->http->lastUrl());
  }

  public function testCallbackQueryIsAppendedWhenTheUrlAlreadyHasOne(): void {
    $this->http->queueJson(['pr' => 'lnbc1', 'verify' => 'https://blink.sv/verify/x']);

    $this->client->requestInvoice(
      $this->address,
      $this->metadata(['callback' => 'https://blink.sv/cb?user=shop']),
      10000000,
      ''
    );

    $this->assertStringContainsString(
      'user=shop&amount=',
      (string) $this->http->lastUrl()
    );
  }

  /**
   * "expiry" is not a LUD-06 parameter. Sending it and then trusting it is
   * what made the plugin record an expiry the invoice did not have.
   */
  public function testNoExpiryParameterIsSent(): void {
    $this->http->queueJson(['pr' => 'lnbc1', 'verify' => 'https://blink.sv/verify/x']);

    $this->client->requestInvoice($this->address, $this->metadata(), 10000000, '');

    $this->assertStringNotContainsString('expiry', (string) $this->http->lastUrl());
  }

  public function testCommentIsSentWhenTheServerAllowsOne(): void {
    $this->http->queueJson(['pr' => 'lnbc1', 'verify' => 'https://blink.sv/verify/x']);

    $this->client->requestInvoice($this->address, $this->metadata(), 10000000, 'GW-42');

    $this->assertStringContainsString('comment=GW-42', (string) $this->http->lastUrl());
  }

  public function testCommentIsOmittedWhenTheServerDoesNotSupportComments(): void {
    $this->http->queueJson(['pr' => 'lnbc1', 'verify' => 'https://blink.sv/verify/x']);

    $this->client->requestInvoice(
      $this->address,
      $this->metadata(['commentAllowed' => 0]),
      10000000,
      'GW-42'
    );

    $this->assertStringNotContainsString('comment=', (string) $this->http->lastUrl());
  }

  public function testEmptyCommentIsNotSent(): void {
    $this->http->queueJson(['pr' => 'lnbc1', 'verify' => 'https://blink.sv/verify/x']);

    $this->client->requestInvoice($this->address, $this->metadata(), 10000000, '');

    $this->assertStringNotContainsString('comment=', (string) $this->http->lastUrl());
  }

  public function testCommentIsTruncatedToTheServersLimit(): void {
    $this->http->queueJson(['pr' => 'lnbc1', 'verify' => 'https://blink.sv/verify/x']);

    $this->client->requestInvoice(
      $this->address,
      $this->metadata(['commentAllowed' => 5]),
      10000000,
      'abcdefghij'
    );

    $this->assertStringContainsString(
      'comment=abcde&',
      (string) $this->http->lastUrl() . '&'
    );
  }

  /** A byte-wise cut would split a multi-byte character in half. */
  public function testCommentTruncationCountsCharactersNotBytes(): void {
    $this->http->queueJson(['pr' => 'lnbc1', 'verify' => 'https://blink.sv/verify/x']);

    $this->client->requestInvoice(
      $this->address,
      $this->metadata(['commentAllowed' => 3]),
      10000000,
      "\u{00E5}\u{00E4}\u{00F6}\u{00E5}\u{00E4}\u{00F6}"
    );

    parse_str((string) parse_url((string) $this->http->lastUrl(), PHP_URL_QUERY), $query);
    $this->assertSame("\u{00E5}\u{00E4}\u{00F6}", $query['comment']);
  }

  public function testMalformedUtf8CommentIsOmittedWithoutFailingCheckout(): void {
    $this->http->queueJson(['pr' => 'lnbc1', 'verify' => 'https://blink.sv/verify/x']);

    $result = $this->client->requestInvoice(
      $this->address,
      $this->metadata(['commentAllowed' => 10]),
      10000000,
      "invalid\xC3\x28"
    );

    $this->assertNotInstanceOf(LnurlFailure::class, $result);
    $this->assertStringNotContainsString('comment=', (string) $this->http->lastUrl());
  }

  public function testCommentIsNeverLongerThanTheProtocolMaximum(): void {
    $this->http->queueJson(['pr' => 'lnbc1', 'verify' => 'https://blink.sv/verify/x']);

    $this->client->requestInvoice(
      $this->address,
      $this->metadata(['commentAllowed' => 5000]),
      10000000,
      str_repeat('a', 5000)
    );

    parse_str((string) parse_url((string) $this->http->lastUrl(), PHP_URL_QUERY), $query);
    $this->assertSame(LnurlClient::MAX_COMMENT_LENGTH, strlen($query['comment']));
  }

  /**
   * @dataProvider badAmounts
   */
  public function testRejectsAmountsThatAreNotWholeSatoshis(int $amountMsat): void {
    $result = $this->client->requestInvoice(
      $this->address,
      $this->metadata(),
      $amountMsat,
      ''
    );

    $this->assertInstanceOf(LnurlFailure::class, $result);
    $this->assertSame('LNURL_BAD_AMOUNT', $result->code);
    $this->assertSame(0, $this->http->requestCount());
  }

  /** @return array<string,array{int}> */
  public static function badAmounts(): array {
    return [
      'zero' => [0],
      'negative' => [-1000],
      'fractional satoshi' => [1500],
    ];
  }

  public function testRejectsAnAmountBelowTheAddressMinimum(): void {
    $result = $this->client->requestInvoice(
      $this->address,
      $this->metadata(['minSendable' => 50000000]),
      10000000,
      ''
    );

    $this->assertInstanceOf(LnurlFailure::class, $result);
    $this->assertSame('LNURL_AMOUNT_OUT_OF_RANGE', $result->code);
    $this->assertSame(0, $this->http->requestCount());
  }

  public function testRejectsAnAmountAboveTheAddressMaximum(): void {
    $result = $this->client->requestInvoice(
      $this->address,
      $this->metadata(['maxSendable' => 1000000]),
      10000000,
      ''
    );

    $this->assertInstanceOf(LnurlFailure::class, $result);
    $this->assertSame('LNURL_AMOUNT_OUT_OF_RANGE', $result->code);
  }

  public function testUnboundedRangesArePermitted(): void {
    $this->http->queueJson(['pr' => 'lnbc1', 'verify' => 'https://blink.sv/verify/x']);

    $result = $this->client->requestInvoice(
      $this->address,
      $this->metadata(['minSendable' => 0, 'maxSendable' => 0]),
      10000000,
      ''
    );

    $this->assertNotInstanceOf(LnurlFailure::class, $result);
  }

  public function testCallbackErrorBodyIsReported(): void {
    $this->http->queueJson(['status' => 'ERROR', 'reason' => 'no route']);

    $result = $this->client->requestInvoice(
      $this->address,
      $this->metadata(),
      10000000,
      ''
    );

    $this->assertInstanceOf(LnurlFailure::class, $result);
    $this->assertSame('LNURL_ERROR', $result->code);
  }

  public function testCallbackWithoutAnInvoiceIsRejected(): void {
    $this->http->queueJson(['verify' => 'https://blink.sv/verify/x']);

    $result = $this->client->requestInvoice(
      $this->address,
      $this->metadata(),
      10000000,
      ''
    );

    $this->assertInstanceOf(LnurlFailure::class, $result);
    $this->assertSame('LNURL_NO_INVOICE', $result->code);
  }

  /** Without LUD-21 there is no webhook and no way to learn about payment. */
  public function testCallbackWithoutAVerifyUrlIsRejected(): void {
    $this->http->queueJson(['pr' => 'lnbc100u1xyz']);

    $result = $this->client->requestInvoice(
      $this->address,
      $this->metadata(),
      10000000,
      ''
    );

    $this->assertInstanceOf(LnurlFailure::class, $result);
    $this->assertSame('LNURL_NO_VERIFY', $result->code);
  }

  public function testVerifyUrlOnAForeignHostIsRejected(): void {
    $this->http->queueJson([
      'pr' => 'lnbc100u1xyz',
      'verify' => 'https://attacker.example/verify/' . str_repeat('ab', 32),
    ]);

    $result = $this->client->requestInvoice(
      $this->address,
      $this->metadata(),
      10000000,
      ''
    );

    $this->assertInstanceOf(LnurlFailure::class, $result);
    $this->assertSame('LNURL_URL_REJECTED', $result->code);
  }

  public function testInsecureVerifyUrlIsRejected(): void {
    $this->http->queueJson([
      'pr' => 'lnbc100u1xyz',
      'verify' => 'http://blink.sv/verify/' . str_repeat('ab', 32),
    ]);

    $result = $this->client->requestInvoice(
      $this->address,
      $this->metadata(),
      10000000,
      ''
    );

    $this->assertInstanceOf(LnurlFailure::class, $result);
    $this->assertSame('LNURL_URL_REJECTED', $result->code);
  }

  /**
   * The callback URL is chosen by the LNURL server, so it is as untrusted as
   * the verify URL and must be checked before it is fetched.
   */
  public function testCallbackUrlOnAForeignHostIsRejectedBeforeAnyRequest(): void {
    $result = $this->client->requestInvoice(
      $this->address,
      $this->metadata(['callback' => 'https://attacker.example/cb']),
      10000000,
      ''
    );

    $this->assertInstanceOf(LnurlFailure::class, $result);
    $this->assertSame('LNURL_URL_REJECTED', $result->code);
    $this->assertSame(0, $this->http->requestCount());
  }

  public function testInsecureCallbackUrlIsRejected(): void {
    $result = $this->client->requestInvoice(
      $this->address,
      $this->metadata(['callback' => 'http://blink.sv/cb']),
      10000000,
      ''
    );

    $this->assertInstanceOf(LnurlFailure::class, $result);
    $this->assertSame('LNURL_URL_REJECTED', $result->code);
  }

  public function testCallbackServerErrorIsReported(): void {
    $this->http->queue(new HttpResponse(503, 'Service Unavailable'));

    $result = $this->client->requestInvoice(
      $this->address,
      $this->metadata(),
      10000000,
      ''
    );

    $this->assertInstanceOf(LnurlFailure::class, $result);
    $this->assertSame('LNURL_HTTP_503', $result->code);
  }

  public function testCallbackTransportFailureIsReported(): void {
    $this->http->queue(HttpResponse::transportFailure('connect timeout'));

    $result = $this->client->requestInvoice(
      $this->address,
      $this->metadata(),
      10000000,
      ''
    );

    $this->assertInstanceOf(LnurlFailure::class, $result);
    $this->assertSame('LNURL_UNREACHABLE', $result->code);
  }

  public function testMalformedCallbackResponseIsRejected(): void {
    $this->http->queue(new HttpResponse(200, 'not json'));

    $result = $this->client->requestInvoice(
      $this->address,
      $this->metadata(),
      10000000,
      ''
    );

    $this->assertInstanceOf(LnurlFailure::class, $result);
    $this->assertSame('LNURL_MALFORMED', $result->code);
  }

  /**
   * @dataProvider unhashableVerifyUrls
   */
  public function testVerifyUrlWithoutAUsableHashStillYieldsAnOffer(
    string $verifyUrl
  ): void {
    $this->http->queueJson(['pr' => 'lnbc100u1xyz', 'verify' => $verifyUrl]);

    $result = $this->client->requestInvoice(
      $this->address,
      $this->metadata(),
      10000000,
      ''
    );

    $this->assertNotInstanceOf(LnurlFailure::class, $result);
    $this->assertNull(
      $result->verifyUrlPaymentHash,
      'the hash is only a cross-check; the invoice is authoritative'
    );
  }

  /** @return array<string,array{string}> */
  public static function unhashableVerifyUrls(): array {
    return [
      'not a hash' => ['https://blink.sv/verify/notahash'],
      'too short' => ['https://blink.sv/verify/abcdef'],
      'no path' => ['https://blink.sv'],
    ];
  }

  public function testVerifyUrlHashIsNormalisedToLowercase(): void {
    $this->http->queueJson([
      'pr' => 'lnbc1',
      'verify' => 'https://blink.sv/verify/' . strtoupper(str_repeat('ab', 32)),
    ]);

    $result = $this->client->requestInvoice(
      $this->address,
      $this->metadata(),
      10000000,
      ''
    );

    $this->assertSame(str_repeat('ab', 32), $result->verifyUrlPaymentHash);
  }

  // ------------------------------------------------------------------ verify

  private const VERIFY_URL = 'https://blink.sv/verify/aabbccddeeff00112233445566778899aabbccddeeff001122334455667788';

  public function testSettledPaymentIsReported(): void {
    $this->http->queueJson([
      'status' => 'OK',
      'settled' => true,
      'preimage' => 'ff00',
      'pr' => 'lnbc1',
    ]);

    $result = $this->client->verify(self::VERIFY_URL, $this->address);

    $this->assertSame(VerifyState::Settled, $result->state);
    $this->assertSame('ff00', $result->preimage);
    $this->assertSame('lnbc1', $result->paymentRequest);
  }

  public function testUnsettledPaymentIsReported(): void {
    $this->http->queueJson(['status' => 'OK', 'settled' => false]);

    $result = $this->client->verify(self::VERIFY_URL, $this->address);

    $this->assertSame(VerifyState::Unsettled, $result->state);
    $this->assertNull($result->preimage);
  }

  public function testMissingSettledFlagIsTreatedAsUnsettled(): void {
    $this->http->queueJson(['status' => 'OK']);

    $this->assertSame(
      VerifyState::Unsettled,
      $this->client->verify(self::VERIFY_URL, $this->address)->state
    );
  }

  /** A truthy-but-not-true value must not be read as payment. */
  public function testNonBooleanSettledValueIsNotTreatedAsPaid(): void {
    $this->http->queueJson(['settled' => 'yes']);

    $this->assertSame(
      VerifyState::Unsettled,
      $this->client->verify(self::VERIFY_URL, $this->address)->state
    );
  }

  public function testSettledWithoutAPreimageIsStillSettled(): void {
    $this->http->queueJson(['settled' => true]);

    $result = $this->client->verify(self::VERIFY_URL, $this->address);

    $this->assertSame(VerifyState::Settled, $result->state);
    $this->assertNull($result->preimage);
  }

  /**
   * The distinction the old client could not make: 404 means the server is
   * telling us something, a timeout means it is telling us nothing.
   */
  public function testNotFoundIsDistinctFromATransportFailure(): void {
    $this->http->queue(new HttpResponse(404, ''));
    $this->assertSame(
      VerifyState::NotFound,
      $this->client->verify(self::VERIFY_URL, $this->address)->state
    );

    $this->http->queue(HttpResponse::transportFailure('timeout'));
    $this->assertSame(
      VerifyState::TransportError,
      $this->client->verify(self::VERIFY_URL, $this->address)->state
    );
  }

  public function testNotFoundReportedInsideATwoHundredBodyIsRecognised(): void {
    $this->http->queueJson(['status' => 'ERROR', 'reason' => 'Invoice not found']);

    $this->assertSame(
      VerifyState::NotFound,
      $this->client->verify(self::VERIFY_URL, $this->address)->state
    );
  }

  public function testOtherErrorBodiesAreTransportErrorsNotNotFound(): void {
    $this->http->queueJson(['status' => 'ERROR', 'reason' => 'Temporarily unavailable']);

    $this->assertSame(
      VerifyState::TransportError,
      $this->client->verify(self::VERIFY_URL, $this->address)->state
    );
  }

  /**
   * @dataProvider serverErrors
   */
  public function testServerErrorsAreTransportErrors(int $status): void {
    $this->http->queue(new HttpResponse($status, ''));

    $this->assertSame(
      VerifyState::TransportError,
      $this->client->verify(self::VERIFY_URL, $this->address)->state
    );
  }

  /** @return array<string,array{int}> */
  public static function serverErrors(): array {
    return [
      '500' => [500],
      '502' => [502],
      '503' => [503],
      '429' => [429],
      '403' => [403],
    ];
  }

  public function testMalformedVerifyBodyIsATransportError(): void {
    $this->http->queue(new HttpResponse(200, '{"settled":'));

    $this->assertSame(
      VerifyState::TransportError,
      $this->client->verify(self::VERIFY_URL, $this->address)->state
    );
  }

  public function testOversizedVerifyBodyIsATransportError(): void {
    $this->http->queue(new HttpResponse(200, '{}', [], null, true));

    $this->assertSame(
      VerifyState::TransportError,
      $this->client->verify(self::VERIFY_URL, $this->address)->state
    );
  }

  public function testAVerifyUrlRejectedByThePolicyIsNeverFetched(): void {
    $result = $this->client->verify('https://attacker.example/verify/x', $this->address);

    $this->assertSame(VerifyState::PolicyError, $result->state);
    $this->assertSame(0, $this->http->requestCount());
  }

  public function testVerifyRequestIsPinnedToTheValidatedAddresses(): void {
    $this->http->queueJson(['settled' => false]);

    $this->client->verify(self::VERIFY_URL, $this->address);

    $this->assertSame(
      ['blink.sv:443' => ['93.184.216.34']],
      $this->http->lastOptions()?->dnsPins
    );
  }

  public function testPlainHttpIsOnlyPermittedForLocalDevelopment(): void {
    $this->http->queueJson([
      'tag' => 'payRequest',
      'callback' => 'http://localhost:8889/cb',
    ]);
    $local = LnAddress::parse('ok@localhost:8889');

    $this->client->fetchPayMetadata($local);

    $this->assertTrue($this->http->lastOptions()?->allowPlainHttp);
  }

  public function testPlainHttpIsNotPermittedForAPublicAddress(): void {
    $this->http->queueJson(['tag' => 'payRequest', 'callback' => 'https://blink.sv/cb']);

    $this->client->fetchPayMetadata($this->address);

    $this->assertFalse($this->http->lastOptions()?->allowPlainHttp);
  }

  public function testFailuresAreLogged(): void {
    $this->http->queue(new HttpResponse(500, ''));

    $this->client->fetchPayMetadata($this->address);

    $this->assertTrue($this->log->hasMessageContaining('LNURL_HTTP_500', 'error'));
  }

  /** Conclusive states are the only ones settlement may act on. */
  public function testOnlySettledAndUnsettledAreConclusive(): void {
    $this->assertTrue(VerifyState::Settled->isConclusive());
    $this->assertTrue(VerifyState::Unsettled->isConclusive());
    $this->assertFalse(VerifyState::NotFound->isConclusive());
    $this->assertFalse(VerifyState::TransportError->isConclusive());
    $this->assertFalse(VerifyState::PolicyError->isConclusive());
  }
}
