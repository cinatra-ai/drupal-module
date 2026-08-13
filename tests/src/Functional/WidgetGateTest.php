<?php

declare(strict_types=1);

namespace Drupal\Tests\cinatra\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Verifies the widget permission gate and credential-free delivery.
 *
 * Covers the explicit-permission gate and — since the widget bootstrap moved
 * to protocol 2 (cinatra#2674) — the ABSENCE of every credential-bearing seam
 * this page used to carry. The old version of this test asserted that the
 * broker endpoint and its CSRF token WERE present in drupalSettings, because
 * the widget needed them to mint a site token and run the per-user sign-in.
 * The iframe runs all of that itself now, so each of those assertions is
 * inverted: what the page hands the browser must be public selectors and
 * nothing else.
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
   * The permitted user gets the local widget with public selectors only.
   */
  public function testWidgetVisibleWithPermissionAndPublicSelectorsOnly(): void {
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

    // THE FLIP (cinatra#2674). These four keys were REQUIRED here until the
    // site stopped being a party to the sign-in. Their presence would mean the
    // page is handing the browser a way to obtain a bearer again.
    $this->assertSession()->responseNotContains('tokenEndpoint');
    $this->assertSession()->responseNotContains('authInitEndpoint');
    $this->assertSession()->responseNotContains('authTokenEndpoint');
    $this->assertSession()->responseNotContains('csrfToken');

    // The public selectors the frame needs ARE present — the negative control
    // for the assertions above, so "nothing is delivered" cannot pass by the
    // widget settings having gone missing entirely.
    $this->assertSession()->responseContains('cinatraUrl');
    $this->assertSession()->responseContains('instanceId');
  }

  /**
   * No credential-shaped value appears anywhere in the delivered page.
   *
   * The recursive shape check the widget applies to its outbound bridge
   * message, applied to the OTHER outbound payload this module composes: the
   * rendered page itself, drupalSettings included. A bearer smuggled into an
   * allowed field would not be caught by a key-name assertion, so the value
   * shape is checked directly.
   */
  public function testNoCredentialShapedValueInDeliveredPage(): void {
    $node = $this->drupalCreateNode(['type' => 'page']);
    $user = $this->drupalCreateUser(['use cinatra assistant']);
    $this->drupalLogin($user);
    $this->drupalGet($node->toUrl());

    $html = $this->getSession()->getPage()->getContent();
    // The bearer prefixes the platform mints, at a token boundary, anywhere in
    // the response — case-insensitively, because a value that differs from a
    // credential only by case is a credential someone is trying to sneak past.
    $this->assertSame(
      0,
      preg_match('/(?:^|[^A-Za-z0-9])(?:cwu|cit|cnx)_/i', $html),
      'The delivered page carries a credential-shaped value; the module must compose none.',
    );
  }

  /**
   * The retired broker routes are gone — not merely permission-denied.
   *
   * A stale cached page that still POSTs to one of them must get a plain 404
   * from Drupal: no relay, no key-bearing request to a retired endpoint, and
   * nothing logged as an upstream failure.
   */
  public function testRetiredBrokerRoutesAreGone(): void {
    $user = $this->drupalCreateUser(['use cinatra assistant']);
    $this->drupalLogin($user);
    foreach (['/cinatra/token', '/cinatra/widget-auth/init', '/cinatra/widget-auth/token'] as $path) {
      $this->drupalGet($path);
      $this->assertSession()->statusCodeEquals(404);
    }
  }

}
