<?php

declare(strict_types=1);

namespace Drupal\cinatra_dev_site_test;

use Drupal\Component\Plugin\Discovery\StaticDiscovery;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\Core\State\StateInterface;
use Drupal\tool\Tool\ToolDefinition;

/**
 * Stands in for the Tool module's manager, with three static tools.
 */
final class TestToolManager extends DefaultPluginManager {

  /**
   * The tool ids of the static discovery and their providers.
   */
  public const TOOLS = [
    'cinatra_read_protected_revision' => 'cinatra',
    'cinatra_write_protected_draft' => 'cinatra',
    'cinatra_dev_site_test_third' => 'mcp_tools_content',
  ];

  /**
   * The number of getDefinitions() calls.
   *
   * @var int
   */
  public static int $definitionsCalls = 0;

  /**
   * The number of createInstance() calls.
   *
   * @var int
   */
  public static int $instanceCalls = 0;

  /**
   * The state store.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  private StateInterface $state;

  /**
   * Constructs the manager.
   *
   * @param \Traversable $namespaces
   *   The namespaces.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state store.
   */
  public function __construct(\Traversable $namespaces, ModuleHandlerInterface $module_handler, StateInterface $state) {
    parent::__construct(FALSE, $namespaces, $module_handler);
    $this->state = $state;
    $this->alterInfo('tool_info');
  }

  /**
   * Loads the ToolDefinition double when the Tool module is absent.
   */
  public static function loadToolDefinition(): void {
    if (!class_exists(ToolDefinition::class)) {
      require_once dirname(__DIR__, 3) . '/fixtures/ToolDefinitionDouble.php';
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function getDiscovery() {
    static::loadToolDefinition();
    $omit = $this->state->get('cinatra_dev_site_test.omit', []);
    $discovery = new StaticDiscovery();
    foreach (self::TOOLS as $id => $provider) {
      if (in_array($id, (array) $omit, TRUE)) {
        continue;
      }
      $discovery->setDefinition($id, new ToolDefinition([
        'id' => $id,
        'class' => TestTool::class,
        'provider' => $provider,
      ]));
    }
    return $discovery;
  }

  /**
   * {@inheritdoc}
   */
  protected function providerExists($provider) {
    if ($provider === 'mcp_tools_content') {
      return TRUE;
    }
    return parent::providerExists($provider);
  }

  /**
   * {@inheritdoc}
   */
  public function getDefinitions() {
    static::$definitionsCalls++;
    return parent::getDefinitions();
  }

  /**
   * {@inheritdoc}
   */
  public function createInstance($plugin_id, array $configuration = []) {
    static::$instanceCalls++;
    return parent::createInstance($plugin_id, $configuration);
  }

}
