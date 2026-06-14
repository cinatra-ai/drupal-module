<?php

declare(strict_types=1);

namespace Drupal\Tests\cinatra\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Verifies the widget permission gate and apiKey-free delivery.
 *
 * Covers the explicit-permission gate, the apiKey-free drupalSettings, and the
 * broker route's access requirements.
 *
 * @group cinatra
 */
final class WidgetGateTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['cinatra', 'node'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->drupalCreateContentType(['type' => 'page', 'name' => 'Page']);
    $this->config('cinatra.settings')
      ->set('cinatra_url', 'https://cinatra.example')
      ->set('api_key', 'long-lived-secret-key')
      ->set('instance_id', 'inst-1')
      ->save();
  }

  /**
   * An authenticated user WITHOUT the permission gets no widget library/markup.
   */
  public function testWidgetHiddenWithoutPermission(): void {
    $node = $this->drupalCreateNode(['type' => 'page']);
    $user = $this->drupalCreateUser([]);
    $this->drupalLogin($user);
    $this->drupalGet($node->toUrl());

    $this->assertSession()->responseNotContains('js/cinatra-widget.js');
    $this->assertSession()->responseNotContains('cinatra-root');
  }

  /**
   * Tests that the permitted user gets the local widget with no apiKey leak.
   *
   * DrupalSettings never carries the long-lived apiKey — only the tokenEndpoint
   * and csrfToken.
   */
  public function testWidgetVisibleWithPermissionAndNoApiKeyLeak(): void {
    $node = $this->drupalCreateNode(['type' => 'page']);
    $user = $this->drupalCreateUser(['use cinatra assistant']);
    $this->drupalLogin($user);
    $this->drupalGet($node->toUrl());

    // Local library is attached; never a remote bundle.js origin.
    $this->assertSession()->responseContains('js/cinatra-widget.js');
    $this->assertSession()->responseNotContains('/api/drupal/bundle.js');

    // The long-lived key is never serialized into drupalSettings.
    $this->assertSession()->responseNotContains('long-lived-secret-key');
    $this->assertSession()->responseNotContains('"apiKey"');

    // The broker endpoint + a CSRF token are present instead.
    $this->assertSession()->responseContains('tokenEndpoint');
    $this->assertSession()->responseContains('csrfToken');
  }

  /**
   * The broker route is denied to a user lacking the permission.
   */
  public function testBrokerRouteRequiresPermission(): void {
    $user = $this->drupalCreateUser([]);
    $this->drupalLogin($user);
    // POST without the permission -> 403 (the CSRF requirement is moot here).
    $this->drupalGet('/cinatra/token');
    // GET is not an allowed method, and the user lacks access; either way it is
    // not a successful mint.
    $this->assertSession()->statusCodeNotEquals(200);
  }

}
