<?php

declare(strict_types=1);

namespace Drupal\Tests\cinatra\Unit;

use Drupal\cinatra\Ssrf;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the HTTP-layer SSRF guard for the module's server-to-server calls.
 *
 * The cases use IP literals and the documented dev hosts only, so the guard's
 * policy is exercised with no dependency on external DNS.
 *
 * @coversDefaultClass \Drupal\cinatra\Ssrf
 *
 * @group cinatra
 */
final class SsrfTest extends UnitTestCase {

  /**
   * The cloud-metadata / private / loopback / link-local targets are blocked.
   *
   * @param string $url
   *   The request URL under test.
   *
   * @dataProvider blockedUrls
   *
   * @covers ::isAllowedUrl
   * @covers ::isPublicIp
   */
  public function testBlockedTargets(string $url): void {
    $this->assertFalse(Ssrf::isAllowedUrl($url), "$url must be blocked");
  }

  /**
   * URLs whose target must be refused by the SSRF guard.
   *
   * @return array<string, array{string}>
   *   Test cases keyed by description.
   */
  public static function blockedUrls(): array {
    return [
      'cloud metadata' => ['https://169.254.169.254/latest/meta-data/'],
      'private 10/8' => ['https://10.0.0.5/api/connect/token'],
      'private 172.16/12' => ['https://172.16.0.9'],
      'private 192.168/16' => ['https://192.168.1.1'],
      'loopback literal (not dev short-circuit path)' => ['https://127.0.0.2'],
      'unspecified 0.0.0.0' => ['https://0.0.0.0'],
      'IPv6 ULA' => ['https://[fd00::1]'],
      'IPv6 link-local' => ['https://[fe80::1]'],
      'IPv4-mapped IPv6 metadata' => ['https://[::ffff:169.254.169.254]'],
      'non-http scheme' => ['ftp://8.8.8.8'],
      'embedded userinfo' => ['https://someuser@8.8.8.8'],
      'no host' => ['https:///api'],
    ];
  }

  /**
   * Public literals and the documented dev/container hosts are allowed.
   *
   * @param string $url
   *   The request URL under test.
   *
   * @dataProvider allowedUrls
   *
   * @covers ::isAllowedUrl
   * @covers ::isPublicIp
   */
  public function testAllowedTargets(string $url): void {
    $this->assertTrue(Ssrf::isAllowedUrl($url), "$url must be allowed");
  }

  /**
   * URLs whose target must be permitted by the SSRF guard.
   *
   * @return array<string, array{string}>
   *   Test cases keyed by description.
   */
  public static function allowedUrls(): array {
    return [
      'public IPv4 literal' => ['https://8.8.8.8/api/connect/token'],
      'public IPv6 literal' => ['https://[2606:4700:4700::1111]'],
      'dev localhost' => ['http://localhost:3000/api/agents/drupal-content-editor/token'],
      'dev 127.0.0.1' => ['http://127.0.0.1:3000'],
      'dev IPv6 loopback' => ['http://[::1]:3000'],
      'dev docker host' => ['http://host.docker.internal:3000'],
      'dev host case-insensitive' => ['https://LOCALHOST'],
      'dev host trailing dot' => ['http://127.0.0.1.:3000'],
    ];
  }

  /**
   * Classifies literal addresses by range.
   *
   * @covers ::isPublicIp
   */
  public function testIsPublicIpClassification(): void {
    // Public.
    $this->assertTrue(Ssrf::isPublicIp('8.8.8.8'));
    $this->assertTrue(Ssrf::isPublicIp('2606:4700:4700::1111'));
    // Private / reserved / loopback / link-local.
    $this->assertFalse(Ssrf::isPublicIp('169.254.169.254'));
    $this->assertFalse(Ssrf::isPublicIp('10.0.0.5'));
    $this->assertFalse(Ssrf::isPublicIp('127.0.0.1'));
    $this->assertFalse(Ssrf::isPublicIp('192.168.1.1'));
    $this->assertFalse(Ssrf::isPublicIp('::1'));
    $this->assertFalse(Ssrf::isPublicIp('fe80::1'));
    // Not an IP at all.
    $this->assertFalse(Ssrf::isPublicIp('not-an-ip'));
  }

}
