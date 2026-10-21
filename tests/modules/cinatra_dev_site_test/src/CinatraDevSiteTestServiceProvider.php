<?php

declare(strict_types=1);

namespace Drupal\cinatra_dev_site_test;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceModifierInterface;

/**
 * Points the development-site endpoint at the fake remote controller.
 */
final class CinatraDevSiteTestServiceProvider implements ServiceModifierInterface {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container) {
    $container->setParameter('cinatra_dev_site.remote_controller', FakeRemoteController::class);
  }

}
