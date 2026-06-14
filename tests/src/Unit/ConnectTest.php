<?php

declare(strict_types=1);

namespace Drupal\Tests\cinatra\Unit;

use Drupal\cinatra\Connect;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the pure "Connect with Cinatra" helpers (cinatra#221, drupal half).
 *
 * @coversDefaultClass \Drupal\cinatra\Connect
 *
 * @group cinatra
 */
final class ConnectTest extends UnitTestCase {

  /**
   * PKCE: verifier is RFC-7636-conformant and the S256 challenge matches it.
   *
   * @covers ::pkce
   * @covers ::base64url
   */
  public function testPkceProducesConformantVerifierAndMatchingChallenge(): void {
    $pkce = Connect::pkce();
    $verifier = $pkce['verifier'];
    $challenge = $pkce['challenge'];

    // RFC 7636 §4.1: 43–128 chars from the unreserved set [A-Za-z0-9-._~].
    // base64url(random_bytes(48)) is 64 chars from [A-Za-z0-9_-].
    $this->assertMatchesRegularExpression('/^[A-Za-z0-9\-._~]{43,128}$/', $verifier);
    // The S256 challenge is base64url(sha256(verifier)) — 43 base64url chars.
    $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $challenge);
    $this->assertSame(
      rtrim(strtr(base64_encode(hash('sha256', $verifier, TRUE)), '+/', '-_'), '='),
      $challenge,
    );
  }

  /**
   * Two handshakes never reuse the same verifier or state.
   *
   * @covers ::pkce
   * @covers ::newState
   */
  public function testHandshakeValuesAreUnique(): void {
    $this->assertNotSame(Connect::pkce()['verifier'], Connect::pkce()['verifier']);
    $this->assertNotSame(Connect::newState(), Connect::newState());
  }

  /**
   * The state store key is sha256(state) — the raw state is never the key.
   *
   * @covers ::stateKey
   */
  public function testStateKeyHashesTheState(): void {
    $state = 'abc123';
    $this->assertSame(hash('sha256', $state), Connect::stateKey($state));
    $this->assertNotSame($state, Connect::stateKey($state));
  }

  /**
   * The authorize URL carries every contract-pinned parameter, RFC3986-encoded.
   *
   * @covers ::authorizeUrl
   */
  public function testAuthorizeUrlCarriesContractParams(): void {
    $url = Connect::authorizeUrl(
      'https://app.example.com',
      'https://site.example/admin/config/services/cinatra/connect/callback',
      'https://site.example',
      'state-value',
      'challenge-value',
    );
    $this->assertStringStartsWith('https://app.example.com/connect/authorize?', $url);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
    $this->assertSame('drupal', $q['client']);
    $this->assertSame('connector:provision', $q['scope']);
    $this->assertSame('S256', $q['code_challenge_method']);
    $this->assertSame('challenge-value', $q['code_challenge']);
    $this->assertSame('state-value', $q['state']);
    $this->assertSame('https://site.example', $q['widget_origin']);
    $this->assertSame('https://site.example/admin/config/services/cinatra/connect/callback', $q['redirect_uri']);
  }

  /**
   * The callback URI validator accepts only the exact contract-pinned shape.
   *
   * @covers ::isValidCallbackUri
   * @dataProvider callbackUriProvider
   */
  public function testIsValidCallbackUri(string $uri, bool $expected): void {
    $this->assertSame($expected, Connect::isValidCallbackUri($uri));
  }

  /**
   * Data provider for testIsValidCallbackUri().
   *
   * @return array<string, array{string, bool}>
   *   Cases keyed by description.
   */
  public static function callbackUriProvider(): array {
    $path = '/admin/config/services/cinatra/connect/callback';
    return [
      'https exact path' => ['https://site.example' . $path, TRUE],
      'http loopback exact path' => ['http://localhost:3000' . $path, TRUE],
      'http non-loopback rejected' => ['http://site.example' . $path, FALSE],
      'wrong path' => ['https://site.example/admin/config/services/cinatra', FALSE],
      'extra query rejected' => ['https://site.example' . $path . '?x=1', FALSE],
      'fragment rejected' => ['https://site.example' . $path . '#f', FALSE],
      'userinfo rejected' => ['https://u:p@site.example' . $path, FALSE],
      'no scheme' => ['site.example' . $path, FALSE],
      'empty' => ['', FALSE],
    ];
  }

  /**
   * The connection-string parser accepts both encodings and gates the URL.
   *
   * @covers ::parseConnectionString
   */
  public function testParseConnectionStringAcceptsValidShapes(): void {
    $json = json_encode(['url' => 'https://app.example.com', 'install_code' => 'cci_abc123']);
    $encoded = 'cinatra-connect:' . rtrim(strtr(base64_encode((string) $json), '+/', '-_'), '=');

    $fromEncoded = Connect::parseConnectionString($encoded);
    $this->assertNotNull($fromEncoded);
    $this->assertSame('https://app.example.com', $fromEncoded['instance_url']);
    $this->assertSame('cci_abc123', $fromEncoded['install_code']);

    $fromJson = Connect::parseConnectionString((string) $json);
    $this->assertNotNull($fromJson);
    $this->assertSame('https://app.example.com', $fromJson['instance_url']);
  }

  /**
   * The connection-string parser rejects malformed / unsafe / oversized input.
   *
   * @covers ::parseConnectionString
   * @dataProvider invalidConnectionStringProvider
   */
  public function testParseConnectionStringRejectsBadInput(string $raw): void {
    $this->assertNull(Connect::parseConnectionString($raw));
  }

  /**
   * Data provider for testParseConnectionStringRejectsBadInput().
   *
   * @return array<string, array{string}>
   *   Cases keyed by description.
   */
  public static function invalidConnectionStringProvider(): array {
    return [
      'empty' => [''],
      'not json' => ['nonsense'],
      'missing code' => [(string) json_encode(['url' => 'https://app.example.com'])],
      'missing url' => [(string) json_encode(['install_code' => 'cci_x'])],
      // Plain HTTP non-loopback is rejected by the CinatraUrl SSRF gate.
      'insecure url' => [(string) json_encode(['url' => 'http://evil.example.com', 'install_code' => 'cci_x'])],
      'url with path' => [(string) json_encode(['url' => 'https://app.example.com/x', 'install_code' => 'cci_x'])],
      'code with whitespace' => [(string) json_encode(['url' => 'https://app.example.com', 'install_code' => 'cci x'])],
      'oversized' => [str_repeat('a', 5000)],
    ];
  }

}
