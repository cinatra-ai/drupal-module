<?php

declare(strict_types=1);

namespace Drupal\cinatra_dev_site_probe;

use Drupal\Core\State\StateInterface;

/**
 * A State stand-in that records every read and can make it fail.
 *
 * Each read appends 'state <key>' to ProbeController::$trail, the same list
 * that records each create() of the probe controller, so a test sees whether
 * State was read and in which order. While $fail is TRUE every read throws.
 * Writes go to the site's State unchanged.
 */
final class ProbeState implements StateInterface {

  /**
   * Whether a read throws.
   *
   * @var bool
   */
  public static bool $fail = FALSE;

  /**
   * Constructs the stand-in.
   *
   * @param \Drupal\Core\State\StateInterface $inner
   *   The site's State, which keeps the values.
   */
  public function __construct(private readonly StateInterface $inner) {
  }

  /**
   * {@inheritdoc}
   */
  public function get($key, $default = NULL) {
    $this->read([$key]);
    return $this->inner->get($key, $default);
  }

  /**
   * {@inheritdoc}
   */
  public function getMultiple(array $keys) {
    $this->read($keys);
    return $this->inner->getMultiple($keys);
  }

  /**
   * {@inheritdoc}
   */
  public function set($key, $value) {
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
   * Returns the values set during the request, where core has this method.
   *
   * @param string $key
   *   The State key.
   *
   * @return array|null
   *   The values, or NULL.
   */
  public function getValuesSetDuringRequest(string $key): ?array {
    if (!method_exists($this->inner, 'getValuesSetDuringRequest')) {
      return NULL;
    }
    return $this->inner->getValuesSetDuringRequest($key);
  }

  /**
   * Records a read of the given keys, and fails it while $fail is TRUE.
   *
   * @param array $keys
   *   The keys read.
   */
  private function read(array $keys): void {
    foreach ($keys as $key) {
      ProbeController::$trail[] = 'state ' . $key;
    }
    if (self::$fail) {
      throw new \RuntimeException('probe state failure');
    }
  }

}
