<?php

declare(strict_types=1);

namespace Drupal\cinatra_dev_site_binding_test;

use Drupal\cinatra_dev_site\Workflow\EditorialBinder;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\State\StateInterface;

/**
 * A State that, when asked, throws once on a write of the ownership record.
 *
 * When asked, it also throws once on the next delete of the ownership key.
 * It logs every set() of the ownership key, with whether the stored
 * editorial record bound node:page at that moment. getValuesSetDuringRequest()
 * returns NULL; every other call goes to the site's State unchanged.
 */
final class FailingState implements StateInterface {

  /**
   * The message of the failure.
   */
  public const MESSAGE = 'failing state: the ownership record was not written';

  /**
   * The message of the failing delete.
   */
  public const DELETE_MESSAGE = 'failing state: the ownership key was not deleted';

  /**
   * Whether the next write of the ownership record throws.
   *
   * @var bool
   */
  public static bool $failNext = FALSE;

  /**
   * Whether the next delete of the ownership key throws.
   *
   * @var bool
   */
  public static bool $failDelete = FALSE;

  /**
   * Each write of the ownership key: the value, and whether page was bound.
   *
   * @var array
   */
  public static array $writes = [];

  /**
   * Constructs the double.
   *
   * @param \Drupal\Core\State\StateInterface $inner
   *   The site's State, which keeps the values.
   * @param \Drupal\Core\Config\StorageInterface $configStorage
   *   The active configuration storage, read at each logged write.
   */
  public function __construct(private readonly StateInterface $inner, private readonly StorageInterface $configStorage) {
  }

  /**
   * {@inheritdoc}
   */
  public function get($key, $default = NULL) {
    return $this->inner->get($key, $default);
  }

  /**
   * {@inheritdoc}
   */
  public function getMultiple(array $keys) {
    return $this->inner->getMultiple($keys);
  }

  /**
   * {@inheritdoc}
   */
  public function set($key, $value) {
    if ($key === EditorialBinder::OWNED_KEY) {
      $stored = $this->configStorage->read(EditorialBinder::CONFIG_NAME);
      $nodes = is_array($stored) ? ($stored['type_settings']['entity_types']['node'] ?? []) : [];
      self::$writes[] = [$value, is_array($nodes) && in_array('page', $nodes, TRUE)];
      // Only the ownership record fails, never the intent before it.
      if (self::$failNext && is_string($value['hash'] ?? NULL)) {
        self::$failNext = FALSE;
        throw new \RuntimeException(self::MESSAGE);
      }
    }
    $this->inner->set($key, $value);
  }

  /**
   * {@inheritdoc}
   */
  public function setMultiple(array $data) {
    $this->inner->setMultiple($data);
  }

  /**
   * {@inheritdoc}
   */
  public function delete($key) {
    if (self::$failDelete && $key === EditorialBinder::OWNED_KEY) {
      self::$failDelete = FALSE;
      throw new \RuntimeException(self::DELETE_MESSAGE);
    }
    $this->inner->delete($key);
  }

  /**
   * {@inheritdoc}
   */
  public function deleteMultiple(array $keys) {
    $this->inner->deleteMultiple($keys);
  }

  /**
   * {@inheritdoc}
   */
  public function resetCache() {
    $this->inner->resetCache();
  }

  /**
   * {@inheritdoc}
   */
  public function getValuesSetDuringRequest(string $key): ?array {
    return NULL;
  }

}
