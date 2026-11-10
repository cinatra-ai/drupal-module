<?php

declare(strict_types=1);

namespace Drupal\cinatra_dev_site_verify_test;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceModifierInterface;
use Symfony\Component\DependencyInjection\Definition;

/**
 * Puts the test tool manager under the tool manager's id.
 *
 * It replaces the alias the probe module sets under that id. The definition
 * is public, as the tool module's own one is: a private definition with one
 * user is inlined when the container compiles, and the test then cannot read
 * it from the container.
 */
final class CinatraDevSiteVerifyTestServiceProvider implements ServiceModifierInterface {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container) {
    $container->setDefinition('plugin.manager.tool', (new Definition(VerifyToolManager::class))->setPublic(TRUE));
  }

}
