<?php

// phpcs:disable Squiz.Classes.ClassFileName.NoMatch

declare(strict_types=1);

namespace Drupal\tool\Tool;

use Drupal\Component\Plugin\Definition\PluginDefinition;
use Drupal\Core\Plugin\Context\ContextDefinitionInterface;
use Drupal\tool\TypedData\InputDefinitionInterface;
use Drupal\tool\TypedData\OutputDefinition;
use Drupal\tool\TypedData\OutputDefinitionInterface;

/**
 * Defines a tool plugin definition (test double).
 *
 * A stand-in for the Tool module's ToolDefinition when that module is absent.
 * Copied from drupal/tool 1.0.0-beta11 (commit 4c5809cd),
 * src/Tool/ToolDefinition.php lines 115-175: __construct(), get() and set()
 * are verbatim. Tests load this file only when the real class does not exist,
 * so the real class wins whenever the Tool module is present.
 */
class ToolDefinition extends PluginDefinition {

  /**
   * The human-readable name.
   *
   * @var mixed
   */
  protected $label;

  /**
   * A description of the tool.
   *
   * @var mixed
   */
  protected $description;

  /**
   * Constructs a new tool definition object.
   *
   * @param array $definition
   *   An array of values from the attribute.
   *
   * @throws \Drupal\Component\Plugin\Exception\ContextException
   *   When input definition refiners reference non-existent input definitions.
   */
  public function __construct(array $definition) {
    if (isset($definition['input_definitions'])) {
      foreach ($definition['input_definitions'] as $name => $input_definition) {
        $this->addInputDefinition($name, $input_definition);
      }
      unset($definition['input_definitions']);
    }

    if (isset($definition['output_definitions'])) {
      foreach ($definition['output_definitions'] as $name => $output_definition) {
        if (($output_definition instanceof ContextDefinitionInterface || $output_definition instanceof InputDefinitionInterface) && !$output_definition instanceof OutputDefinitionInterface) {
          @trigger_error(sprintf("Declaring the '%s' output with %s is deprecated in tool:1.0.0-beta9 and is removed from tool:1.0.0-rc1. Use an OutputDefinitionInterface class instead. See https://git.drupalcode.org/project/tool/-/work_items/3583050", $name, $output_definition::class), E_USER_DEPRECATED);
          $output_definition = OutputDefinition::fromContextDefinition($output_definition, $name);
        }
        $this->addOutputDefinition($name, $output_definition);
      }
      unset($definition['output_definitions']);
    }

    if (isset($definition['input_definition_refiners'])) {
      $this->setInputDefinitionRefiners($definition['input_definition_refiners']);
      unset($definition['input_definition_refiners']);
    }

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
    // isset() semantics: declared-but-uninitialized typed properties (e.g. a
    // definition without a 'deriver') must return NULL, not throw.
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
