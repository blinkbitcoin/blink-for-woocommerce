<?php

declare(strict_types=1);

namespace Blink\WC\Tests\Unit\Http;

use Blink\WC\Http\HttpResponse;
use PHPUnit\Framework\TestCase;

final class HttpResponseTest extends TestCase {
  /**
   * @dataProvider statuses
   */
  public function testOkOnlyForSuccessfulStatuses(int $status, bool $expected): void {
    $this->assertSame($expected, (new HttpResponse($status, '{}'))->ok());
  }

  /** @return array<string,array{int,bool}> */
  public static function statuses(): array {
    return [
      '200' => [200, true],
      '204' => [204, true],
      '299' => [299, true],
      '300' => [300, false],
      '302' => [302, false],
      '400' => [400, false],
      '404' => [404, false],
      '500' => [500, false],
      '199' => [199, false]
    ];
  }

  public function testTransportFailureIsNeitherOkNorSuccessful(): void {
    $response = HttpResponse::transportFailure('connect timeout');

    $this->assertTrue($response->failed());
    $this->assertFalse($response->ok());
    $this->assertSame(0, $response->status);
    $this->assertSame('connect timeout', $response->transportError);
  }

  public function testCompletedResponseIsNotAFailureEvenAtFiveHundred(): void {
    $response = new HttpResponse(500, 'boom');

    $this->assertFalse($response->failed());
    $this->assertFalse($response->ok());
  }

  /**
   * @dataProvider bodies
   */
  public function testJsonDecodesOnlyObjectsAndArrays(
    string $body,
    ?array $expected
  ): void {
    $this->assertSame($expected, (new HttpResponse(200, $body))->json());
  }

  /** @return array<string,array{string,array|null}> */
  public static function bodies(): array {
    return [
      'object' => ['{"tag":"payRequest"}', ['tag' => 'payRequest']],
      'array' => ['[1,2]', [1, 2]],
      'empty object' => ['{}', []],
      'empty body' => ['', null],
      'malformed' => ['{"callback":', null],
      'bare string' => ['"ok"', null],
      'bare number' => ['42', null],
      'bare bool' => ['true', null],
      'null literal' => ['null', null],
      'not json at all' => ['<html>nope</html>', null]
    ];
  }

  public function testHeadersAndTruncationAreCarried(): void {
    $response = new HttpResponse(
      200,
      'x',
      ['content-type' => 'application/json'],
      null,
      true
    );

    $this->assertSame('application/json', $response->headers['content-type']);
    $this->assertTrue($response->truncated);
  }
}
