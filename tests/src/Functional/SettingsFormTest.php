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
   * The manual config fields render inside an OPEN details group.
   *
   * The Cinatra URL + API key fields are the canonical configuration surface;
   * a collapsed <details> hides them from a real browser (even when the site
   * is already connected), so the group must render with the `open` attribute
   * and expose the `edit-cinatra-url` / `edit-api-key` elements. Guards the
   * regression where the connected state collapsed the group.
   */
  public function testManualConfigFieldsRenderOpen(): void {
    // Put the site into the "connected" state — the case that previously
    // collapsed the manual group.
    $this->config('cinatra.settings')
      ->set('cinatra_url', 'https://app.example.com')
      ->set('api_key', 'long-lived-secret')
      ->save();

    $this->drupalGet(self::SETTINGS_PATH);
    $assert = $this->assertSession();
    // The fields exist and carry the documented ids the UAT asserts against.
    $assert->fieldExists('cinatra_url');
    $assert->fieldExists('api_key');
    // The wrapping <details> is open (not collapsed) so the fields are visible.
    $details = $this->getSession()->getPage()->find('css', 'details#edit-manual');
    $this->assertNotNull($details, 'The manual configuration details element is present.');
    $this->assertTrue($details->hasAttribute('open'), 'The manual configuration details element renders open.');
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
