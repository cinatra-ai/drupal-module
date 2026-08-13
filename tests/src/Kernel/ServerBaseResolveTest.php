<?php

declare(strict_types=1);

namespace Drupal\Tests\cinatra\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\cinatra\ServerBase;
use Psr\Log\AbstractLogger;

/**
 * @coversDefaultClass \Drupal\cinatra\ServerBase
 *
 * The CINATRA_BASE_URL override matrix, exercised directly.
 *
 * This matrix used to be driven through the widget's `cit_` token broker, which
 * is retired with the widget bootstrap protocol-2 change (cinatra#2674): the
 * site no longer obtains a bearer for the assistant at all. The override itself
 * is NOT retired — the node-publish webhook emitter (PublishWebhook) and the
 * connect exchange (ConnectController) still resolve their server-side base
 * through it, and both send credential-bearing requests to whatever it resolves
 * to. So the whole hostile-value matrix is kept and re-pointed at the resolver
 * that owns it, rather than deleted with the controller that used to host it.
 *
 * @group cinatra
 */
final class ServerBaseResolveTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['cinatra', 'user'];

  /**
   * The configured (browser-facing) Cinatra URL every case falls back to.
   */
  private const CONFIG_URL = 'https://cinatra.example';

  /**
   * The in-memory logger the resolver under test writes to.
   *
   * Used to assert that a rejected CINATRA_BASE_URL override is logged without
   * ever including the raw env value.
   */
  private CinatraSpyLogger $spyLogger;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->spyLogger = new CinatraSpyLogger();
    // No leakage from another test in the same process.
    putenv('CINATRA_BASE_URL');
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv('CINATRA_BASE_URL');
    parent::tearDown();
  }

  /**
   * With the env unset the configured URL is returned verbatim.
   *
   * Production parity: nothing changes when CINATRA_BASE_URL is not present.
   *
   * @covers ::resolve
   */
  public function testUnsetUsesConfig(): void {
    $this->assertSame(self::CONFIG_URL, ServerBase::resolve(self::CONFIG_URL, $this->spyLogger));
    $this->assertSame([], $this->spyLogger->records);
  }

  /**
   * An empty or whitespace-only env falls back to the configured URL.
   *
   * @covers ::resolve
   */
  public function testBlankFallsBackToConfig(): void {
    foreach (['', '   '] as $blank) {
      putenv('CINATRA_BASE_URL=' . $blank);
      $this->assertSame(
        self::CONFIG_URL,
        ServerBase::resolve(self::CONFIG_URL, $this->spyLogger),
        'A blank CINATRA_BASE_URL must fall back to the configured cinatra_url.',
      );
    }
    // A blank env is the normal production state, not a rejection: silent.
    $this->assertSame([], $this->spyLogger->records);
  }

  /**
   * A configured trailing-slash URL is returned unchanged.
   *
   * Production parity: the override hardening must NOT alter how a configured
   * URL builds the server-to-server base.
   *
   * @covers ::resolve
   */
  public function testConfigTrailingSlashUnchanged(): void {
    $this->assertSame(
      'https://cinatra.example/',
      ServerBase::resolve('https://cinatra.example/', $this->spyLogger),
    );
  }

  /**
   * A clean, allowlisted override is accepted and canonicalized.
   *
   * @dataProvider acceptedBaseUrlProvider
   *
   * @covers ::resolve
   */
  public function testAcceptsAllowlistedOverride(string $override, string $expected): void {
    putenv('CINATRA_BASE_URL=' . $override);
    $this->assertSame($expected, ServerBase::resolve(self::CONFIG_URL, $this->spyLogger));
    $this->assertSame([], $this->spyLogger->records, 'An accepted override logs nothing.');
  }

  /**
   * Container-topology overrides that must be accepted.
   *
   * @return array<string, array{string, string}>
   *   Provider rows: the raw env value and the canonical origin it yields.
   */
  public static function acceptedBaseUrlProvider(): array {
    return [
      'docker host gateway' => ['http://host.docker.internal:3000', 'http://host.docker.internal:3000'],
      'trailing slash trimmed' => ['http://localhost:3000/', 'http://localhost:3000'],
      'https localhost' => ['https://localhost', 'https://localhost'],
      'IPv4 loopback' => ['http://127.0.0.1:3000', 'http://127.0.0.1:3000'],
      'bracketed IPv6 loopback' => ['http://[::1]:3000', 'http://[::1]:3000'],
      'scheme case is normalized' => ['HTTP://localhost:3000', 'http://localhost:3000'],
    ];
  }

  /**
   * A hostile/malformed override is REJECTED and falls back to the config URL.
   *
   * This is the credential-exfiltration guard: a credential-bearing POST must
   * never be redirected to an env-supplied host/scheme/path that fails
   * validation. Each case must (a) keep the base on the configured URL and
   * (b) leave the raw env value out of all log output.
   *
   * @dataProvider hostileBaseUrlProvider
   *
   * @covers ::resolve
   */
  public function testRejectsHostileOverride(string $hostile): void {
    putenv('CINATRA_BASE_URL=' . $hostile);
    $resolved = ServerBase::resolve(self::CONFIG_URL, $this->spyLogger);

    $this->assertSame(
      self::CONFIG_URL,
      $resolved,
      sprintf('Hostile CINATRA_BASE_URL "%s" must not become the server-to-server base.', $hostile),
    );

    // Exactly one warning was logged about the rejection, and it never echoes
    // the (possibly hostile, possibly credential-bearing) raw value.
    $this->assertCount(1, $this->spyLogger->records);
    $logged = $this->spyLogger->renderedLine(0);
    $this->assertStringNotContainsString($hostile, $logged);
  }

  /**
   * Builds a userinfo-bearing URL from parts, deliberately not as a literal.
   *
   * These fixtures are hostile INPUT — the exact shape the resolver must reject
   * — but written out in full they are indistinguishable from a real credential
   * in a URL, and the repository's secret scanner flags them as one. Assembling
   * them keeps the value under test byte-identical while leaving no
   * userinfo-bearing URL literal in the source for a scanner to trip on.
   * Do not "tidy" these back into literals.
   *
   * @param string $userinfo
   *   The userinfo component, without the trailing "@".
   * @param string $rest
   *   Everything after the "@" — host, optional port, optional path/query.
   * @param string $scheme
   *   The URL scheme.
   *
   * @return string
   *   The assembled URL.
   */
  private static function withUserinfo(string $userinfo, string $rest, string $scheme = 'http'): string {
    return $scheme . '://' . $userinfo . '@' . $rest;
  }

  /**
   * Hostile / malformed CINATRA_BASE_URL values that must be rejected.
   *
   * @return array<string, array{string}>
   *   Provider rows keyed by the threat they represent.
   */
  public static function hostileBaseUrlProvider(): array {
    return [
      'userinfo (credential smuggling)' => [self::withUserinfo('user:pass', 'evil.example:3000')],
      'embedded userinfo at attacker host' => [self::withUserinfo('attacker.example', 'cinatra.example', 'https')],
      'query string' => ['https://evil.example/?x=1'],
      'fragment' => ['https://evil.example/#frag'],
      'meaningful path (exfil endpoint)' => ['https://evil.example/collect'],
      'non-http scheme (file)' => ['file:///etc/passwd'],
      'non-http scheme (gopher)' => ['gopher://evil.example:70'],
      'scheme-relative (no scheme)' => ['//evil.example'],
      'bare host (no scheme)' => ['evil.example:3000'],
      'control char (CR injection)' => ["http://evil.example\r\nX-Inject: 1"],
      'embedded space' => ['http://evil .example'],
      'no host' => ['https://'],
      'port zero (out of range)' => ['http://evil.example:0'],
      'port too high (65536, out of range)' => ['http://evil.example:65536'],
      // parse_url is too permissive on these: it accepts/partially
      // canonicalizes malformed ports to a real port instead of rejecting.
      // The anchored grammar must reject each so no malformed port reaches
      // a credential-bearing request.
      'port with trailing letters (80x)' => ['http://safe.example:80x/'],
      'port with leading plus (+80)' => ['http://safe.example:+80/'],
      'port with decimal (1.2)' => ['http://safe.example:1.2/'],
      // Host grammar parse_url does not validate: backslash hosts, an
      // unbracketed IPv6 literal, and extra-colon forms must all be rejected
      // by the anchored host grammar rather than slipping to a downstream
      // parser/error path.
      'backslash host with userinfo' => ['http://safe.example\\@evil/'],
      'backslash authority (http:\\\\)' => ['http:\\\\safe.example/'],
      'unbracketed IPv6 literal' => ['http://::1:3000/'],
      'extra-colon authority (host::3000)' => ['http://host::3000/'],
      // Clean, grammar-passing origins whose HOST is not a container host: the
      // override is only ever a container-topology pointer, so the host
      // allowlist must reject these even though they are well-formed. A clean
      // arbitrary host that slipped past would become the credential
      // destination.
      'clean public host (not allowlisted)' => ['http://evil.example:3000'],
      'clean https public host (not allowlisted)' => ['https://attacker.example'],
      'clean private IPv4 (not allowlisted)' => ['http://10.0.0.5:3000'],
      'clean public IPv4 (not allowlisted)' => ['http://203.0.113.7'],
      'allowlist-adjacent typo host' => ['http://host.docker.internal.evil.example:3000'],
      'non-loopback bracketed IPv6 (not allowlisted)' => ['http://[2001:db8::1]:3000'],
    ];
  }

  /**
   * On a rejected override the warning carries no raw env value.
   *
   * Explicit boundary assertion on the log surface, independent of the
   * data-provider cases above: the override value can itself carry a secret.
   *
   * @covers ::resolve
   */
  public function testRejectedOverrideLogsNoRawEnv(): void {
    $hostile = self::withUserinfo('user:pass', 'evil.example/collect?key=long-lived-secret-key');
    putenv('CINATRA_BASE_URL=' . $hostile);
    ServerBase::resolve(self::CONFIG_URL, $this->spyLogger);

    $this->assertCount(1, $this->spyLogger->records);
    $line = $this->spyLogger->renderedLine(0);
    $this->assertSame('warning', $this->spyLogger->records[0]['level']);
    $this->assertStringNotContainsString('long-lived-secret-key', $line);
    $this->assertStringNotContainsString('evil.example', $line);
    $this->assertStringNotContainsString($hostile, $line);
    // It DOES record that the override was rejected (for diagnosability).
    $this->assertStringContainsString('CINATRA_BASE_URL', $line);
  }

}

/**
 * A minimal in-memory PSR-3 logger used to inspect what the resolver logs.
 *
 * Records every log call so tests can assert that a rejected CINATRA_BASE_URL
 * override is logged without ever exposing the raw env value. Kept tiny and
 * dependency-free so the test is portable.
 */
final class CinatraSpyLogger extends AbstractLogger {

  /**
   * The recorded log calls.
   *
   * @var array<int, array{level: string, message: string, context: array}>
   */
  public array $records = [];

  /**
   * {@inheritdoc}
   */
  public function log($level, string|\Stringable $message, array $context = []): void {
    $this->records[] = [
      'level' => (string) $level,
      'message' => (string) $message,
      'context' => $context,
    ];
  }

  /**
   * Renders a recorded entry (message + interpolated context) to one string.
   *
   * The assertion target: everything that could end up in a real log line for
   * the given record, so a leaked value in EITHER the message or a placeholder
   * is caught.
   *
   * @param int $index
   *   The record index.
   *
   * @return string
   *   The flattened message + context.
   */
  public function renderedLine(int $index): string {
    $record = $this->records[$index];
    $parts = [$record['message']];
    foreach ($record['context'] as $key => $value) {
      $parts[] = $key . '=' . (is_scalar($value) || $value instanceof \Stringable ? (string) $value : json_encode($value));
    }
    return implode(' ', $parts);
  }

}
