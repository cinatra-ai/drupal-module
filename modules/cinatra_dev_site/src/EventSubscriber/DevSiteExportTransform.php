<?php

declare(strict_types=1);

namespace Drupal\cinatra_dev_site\EventSubscriber;

use Drupal\cinatra_dev_site\CinatraDevSite;
use Drupal\Core\Config\ConfigEvents;
use Drupal\Core\Config\StorageTransformEvent;
use Drupal\Core\State\StateInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Keeps the submodule and the page binding it added out of every export.
 */
final class DevSiteExportTransform implements EventSubscriberInterface {

  /**
   * Constructs the subscriber.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   */
  public function __construct(
    private readonly StateInterface $state,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [ConfigEvents::STORAGE_TRANSFORM_EXPORT => ['onExportTransform']];
  }

  /**
   * Removes the submodule and an owned page binding from the export copy.
   *
   * @param \Drupal\Core\Config\StorageTransformEvent $event
   *   The transform event.
   */
  public function onExportTransform(StorageTransformEvent $event): void {
    $storage = $event->getStorage();
    $extension = $storage->read('core.extension');
    if (is_array($extension) && isset($extension['module']['cinatra_dev_site'])) {
      unset($extension['module']['cinatra_dev_site']);
      $storage->write('core.extension', $extension);
    }
    if ($this->state->get(CinatraDevSite::BOUND_KEY) !== TRUE) {
      return;
    }
    $editorial = $storage->read('workflows.workflow.editorial');
    if (!is_array($editorial)) {
      return;
    }
    $nodes = $editorial['type_settings']['entity_types']['node'] ?? [];
    if (is_array($nodes)) {
      $nodes = array_values(array_diff($nodes, ['page']));
      if ($nodes === []) {
        unset($editorial['type_settings']['entity_types']['node']);
      }
      else {
        $editorial['type_settings']['entity_types']['node'] = $nodes;
      }
    }
    if (isset($editorial['dependencies']['config']) && is_array($editorial['dependencies']['config'])) {
      $config = $editorial['dependencies']['config'];
      $editorial['dependencies']['config'] = array_values(array_diff($config, ['node.type.page']));
      if ($editorial['dependencies']['config'] === []) {
        unset($editorial['dependencies']['config']);
      }
    }
    $storage->write('workflows.workflow.editorial', $editorial);
  }

}
