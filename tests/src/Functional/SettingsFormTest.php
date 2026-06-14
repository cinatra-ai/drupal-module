<?php

declare(strict_types=1);

namespace Drupal\Tests\cinatra\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Tests the Cinatra settings form, focusing on the safe-origin URL gate.
 *
 * @group cinatra
 */
final class SettingsFormTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['cinatra'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The settings form path.
   */
  private const SETTINGS_PATH = '/admin/config/services/cinatra';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $admin = $this->drupalCreateUser(['administer site configuration']);
    $this->drupalLogin($admin);
  }

  /**
   * A non-HTTPS, non-loopback origin is rejected with a validation error.
   */
  public function testInsecureUrlRejected(): void {
    $this->drupalGet(self::SETTINGS_PATH);
    $this->submitForm([
      'cinatra_url' => 'http://evil.example.com',
      'api_key' => 'k',
    ], 'Save configuration');
    $this->assertSession()->pageTextContains('The Cinatra URL must be an HTTPS origin');
    // The bad value was not persisted.
    $this->assertSame('', (string) $this->config('cinatra.settings')->get('cinatra_url'));
  }

  /**
   * A URL carrying a path is rejected (origin-only contract).
   */
  public function testUrlWithPathRejected(): void {
    $this->drupalGet(self::SETTINGS_PATH);
    $this->submitForm([
      'cinatra_url' => 'https://app.example.com/some/path',
      'api_key' => 'k',
    ], 'Save configuration');
    $this->assertSession()->pageTextContains('The Cinatra URL must be an HTTPS origin');
    $this->assertSame('', (string) $this->config('cinatra.settings')->get('cinatra_url'));
  }

  /**
   * A URL carrying credentials or a fragment is rejected.
   */
  public function testUrlWithCredentialsOrFragmentRejected(): void {
    $this->drupalGet(self::SETTINGS_PATH);
    $this->submitForm([
      'cinatra_url' => 'https://user:pass@app.example.com',
      'api_key' => 'k',
    ], 'Save configuration');
    $this->assertSession()->pageTextContains('The Cinatra URL must be an HTTPS origin');

    $this->submitForm([
      'cinatra_url' => 'https://app.example.com/#frag',
      'api_key' => 'k',
    ], 'Save configuration');
    $this->assertSession()->pageTextContains('The Cinatra URL must be an HTTPS origin');
  }

  /**
   * A valid HTTPS origin is accepted and normalized (trailing path stripped).
   */
  public function testHttpsOriginAcceptedAndNormalized(): void {
    $this->drupalGet(self::SETTINGS_PATH);
    $this->submitForm([
      'cinatra_url' => 'https://app.example.com',
      'api_key' => 'long-lived-secret',
    ], 'Save configuration');
    $this->assertSession()->pageTextContains('The configuration options have been saved.');
    $this->assertSame('https://app.example.com', (string) $this->config('cinatra.settings')->get('cinatra_url'));
  }

  /**
   * A loopback HTTP URL is accepted for local development.
   */
  public function testLoopbackHttpAccepted(): void {
    $this->drupalGet(self::SETTINGS_PATH);
    $this->submitForm([
      'cinatra_url' => 'http://localhost:3000',
      'api_key' => 'k',
    ], 'Save configuration');
    $this->assertSession()->pageTextContains('The configuration options have been saved.');
    $this->assertSame('http://localhost:3000', (string) $this->config('cinatra.settings')->get('cinatra_url'));
  }

}
