<?php

// phpcs:disable Squiz.Classes.ClassFileName.NoMatch


declare(strict_types=1);

namespace Drupal\tool\Tool;

use Drupal\Component\Plugin\Definition\PluginDefinition;

/**
 * A stand-in for the Tool module's ToolDefinition when that module is absent.
 *
 * The kernel fixture has no Tool module. The tests load this file only when
 * the real class does not exist, so the real class wins whenever the Tool
 * module is present. The plain-property loop of the constructor, get() and
 * set() follow drupal/tool 1.0.0-beta12, src/Tool/ToolDefinition.php.
 */
class ToolDefinition extends PluginDefinition {

  /**
   * The human-readable name.
   *
   * @var mixed
   */
  protected $label;

  /**
   * Constructs a definition from plain property values.
   *
   * @param array $definition
   *   The property values.
   */
  public function __construct(array $definition) {
    foreach ($definition as $property => $value) {
      $this->set($property, $value);
    }
  }

  /**
   * Gets any arbitrary property.
   *
   * @param string $property
   *   The property to retrieve.
   *
   * @return mixed
   *   The value for that property, or NULL if the property does not exist.
   */
  public function get(string $property): mixed {
    return $this->{$property} ?? NULL;
  }

  /**
   * Sets a value to an arbitrary property.
   *
   * @param string $property
   *   The property to use for the value.
   * @param mixed $value
   *   The value to set.
   *
   * @return $this
   *   The current instance.
   */
  public function set(string $property, mixed $value): static {
    if (property_exists($this, $property)) {
      $this->{$property} = $value;
    }
    return $this;
  }

}
