<?php

declare(strict_types=1);

namespace Drupal\cinatra_dev_site_test;

use Drupal\Core\Logger\RfcLoggerTrait;
use Psr\Log\LoggerInterface;

/**
 * Records every log line with its channel, for the kernel assertions.
 */
final class TestLogger implements LoggerInterface {

  use RfcLoggerTrait;

  /**
   * The recorded lines, each a list of channel and message.
   *
   * @var array
   */
  public static array $lines = [];

  /**
   * {@inheritdoc}
   */
  public function log($level, string|\Stringable $message, array $context = []): void {
    static::$lines[] = [(string) ($context['channel'] ?? ''), (string) $message];
  }

}
