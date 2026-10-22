<?php

declare(strict_types=1);

namespace Drupal\cinatra_dev_site_probe;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceModifierInterface;

/**
 * Points the endpoint at the probe controller and the probe registries.
 */
final class CinatraDevSiteProbeServiceProvider implements ServiceModifierInterface {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container) {
    $container->setParameter('cinatra_dev_site.remote_controller', ProbeController::class);
    $container->setParameter('cinatra_dev_site.empty_registries', [
      'mcp_tools.resource_registry' => ProbeRegistry::class,
      'mcp_tools.prompt_registry' => ProbeRegistry::class,
    ]);
  }

}
