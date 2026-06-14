<?php

declare(strict_types=1);

namespace Drupal\Tests\cinatra\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Tests the "Connect with Cinatra" UI + callback route (cinatra#221).
 *
 * Covers the surface that needs no live Cinatra instance: the settings
 * form renders the Connect section + manual fallback, the callback route is
 * permission-gated, and the callback handles the cancel / missing-code / stale-
 * state cases without provisioning anything. The happy-path exchange is covered
 * by the pure-helper unit tests (the HTTP exchange needs a live instance).
 *
 * @group cinatra
 */
final class ConnectUiTest extends BrowserTestBase {

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
   * The contract-pinned callback path.
   */
  private const CALLBACK_PATH = '/admin/config/services/cinatra/connect/callback';

  /**
   * The settings form exposes the Connect section, fallback, and manual fields.
   */
  public function testFormShowsConnectAndManualSections(): void {
    $admin = $this->drupalCreateUser(['administer site configuration']);
    $this->drupalLogin($admin);

    $this->drupalGet(self::SETTINGS_PATH);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Connect to Cinatra');
    $this->assertSession()->buttonExists('Connect with Cinatra');
    $this->assertSession()->buttonExists('Connect with code');
    // Manual fields are still present (advanced path).
    $this->assertSession()->fieldExists('api_key');
    $this->assertSession()->fieldExists('instance_id');
  }

  /**
   * The callback route requires the admin permission.
   */
  public function testCallbackRequiresPermission(): void {
    $weak = $this->drupalCreateUser([]);
    $this->drupalLogin($weak);
    $this->drupalGet(self::CALLBACK_PATH);
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * A cancelled handshake (?error=access_denied) provisions nothing.
   */
  public function testCallbackCancelDoesNotProvision(): void {
    $admin = $this->drupalCreateUser(['administer site configuration']);
    $this->drupalLogin($admin);

    $this->drupalGet(self::CALLBACK_PATH, ['query' => ['error' => 'access_denied', 'state' => 'x']]);
    // Redirected back to the settings form.
    $this->assertSession()->addressEquals(self::SETTINGS_PATH);
    $this->assertSession()->pageTextContains('cancelled on the Cinatra side');
    $this->assertSame('', (string) $this->config('cinatra.settings')->get('api_key'));
  }

  /**
   * A callback with an unknown/expired state is rejected, provisioning none.
   */
  public function testCallbackUnknownStateRejected(): void {
    $admin = $this->drupalCreateUser(['administer site configuration']);
    $this->drupalLogin($admin);

    $this->drupalGet(self::CALLBACK_PATH, ['query' => ['code' => 'somecode', 'state' => 'never-issued']]);
    $this->assertSession()->addressEquals(self::SETTINGS_PATH);
    $this->assertSession()->pageTextContains('expired or did not match');
    $this->assertSame('', (string) $this->config('cinatra.settings')->get('api_key'));
  }

  /**
   * A bad connection string is rejected without provisioning.
   */
  public function testInstallCodeRejectsBadString(): void {
    $admin = $this->drupalCreateUser(['administer site configuration']);
    $this->drupalLogin($admin);

    $this->drupalGet(self::SETTINGS_PATH);
    $this->submitForm(['connection_string' => 'not-a-valid-string'], 'Connect with code');
    $this->assertSession()->pageTextContains('connection string is not valid');
    $this->assertSame('', (string) $this->config('cinatra.settings')->get('api_key'));
  }

}
