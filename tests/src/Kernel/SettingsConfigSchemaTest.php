<?php

declare(strict_types=1);

namespace Drupal\Tests\cinatra\Kernel;

use Drupal\Core\Config\Schema\SchemaCheckTrait;
use Drupal\KernelTests\KernelTestBase;

/**
 * Every key this module persists is declared in its config schema.
 *
 * The module writes `cinatra.settings` from two places — the admin form and
 * the connect exchange — and two of the keys it writes are credentials. An
 * undeclared key is not a cosmetic defect on that object: config with no
 * schema is untyped, so it is skipped by config validation, exported without
 * a type, and invisible to config-inspection tooling. A unit test cannot see
 * any of this, because the typed-config manager only exists inside a booted
 * Drupal — which is exactly the layer this suite covers.
 *
 * @group cinatra
 */
final class SettingsConfigSchemaTest extends KernelTestBase {

  use SchemaCheckTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['cinatra', 'user', 'system'];

  /**
   * Every key the module persists at runtime, with a schema-valid value.
   *
   * The two credential-bearing keys carry obvious placeholders: this test
   * asserts on the SHAPE of the config object, never on a real secret.
   *
   * @var array<string, string>
   */
  private const RUNTIME_KEYS = [
    'cinatra_url' => 'https://cinatra.example',
    'instance_id' => 'inst-1',
    'api_key' => 'placeholder-api-key-not-a-credential',
    'webhook_secret' => 'whsec_placeholder_not_a_credential',
    'webhook_binding_id' => 'bind-1',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['cinatra']);
  }

  /**
   * The shipped default installs as a valid, EMPTY config object.
   *
   * `config/install/cinatra.settings.yml` is an explicit empty mapping. A
   * comments-only file parses as NULL instead, which is not a valid config
   * object for the declared `config_object` mapping — and that failure would
   * only ever appear on a real install.
   */
  public function testInstalledDefaultIsAnEmptyMapping(): void {
    $data = $this->config('cinatra.settings')->get();
    $this->assertIsArray($data, 'cinatra.settings must install as a mapping.');
    $this->assertSame([], $data, 'The shipped default carries no values.');
  }

  /**
   * Saving the full runtime key set conforms to the declared schema.
   *
   * KernelTestBase runs with strict config-schema checking, so the save itself
   * is an assertion: an undeclared or wrongly typed key throws here. The
   * explicit checker call then names the offending key when one appears.
   */
  public function testRuntimeKeysConformToSchema(): void {
    $config = $this->config('cinatra.settings');
    foreach (self::RUNTIME_KEYS as $key => $value) {
      $config->set($key, $value);
    }
    $config->save();

    $result = $this->checkConfigSchema(
      $this->container->get('config.typed'),
      'cinatra.settings',
      $this->config('cinatra.settings')->get(),
    );
    $this->assertTrue($result, is_array($result) ? implode(', ', array_keys($result)) : 'Schema check failed.');
  }

  /**
   * Each persisted key is declared individually.
   *
   * Asserted key by key so a future key that is added to the form but not to
   * `config/schema/cinatra.schema.yml` is named in the failure message.
   */
  public function testEachPersistedKeyIsDeclared(): void {
    $typed = $this->container->get('config.typed');
    foreach (self::RUNTIME_KEYS as $key => $value) {
      $result = $this->checkConfigSchema($typed, 'cinatra.settings', [$key => $value]);
      $this->assertTrue($result, sprintf('Config key "%s" has no schema.', $key));
    }
  }

  /**
   * The negative control: an undeclared key IS reported.
   *
   * Without this, the assertions above would also pass if the checker silently
   * accepted everything handed to it.
   */
  public function testUndeclaredKeyIsReported(): void {
    $result = $this->checkConfigSchema(
      $this->container->get('config.typed'),
      'cinatra.settings',
      self::RUNTIME_KEYS + ['not_declared_anywhere' => 'x'],
    );
    $this->assertIsArray($result, 'An undeclared key must not pass the schema check.');
    $this->assertArrayHasKey('cinatra.settings:not_declared_anywhere', $result);
  }

}
