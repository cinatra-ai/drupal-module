<?php

declare(strict_types=1);

namespace Drupal\cinatra_dev_site_ensure_test;

use Drupal\Core\Logger\RfcLoggerTrait;
use Drupal\Core\Logger\RfcLogLevel;
use Psr\Log\LoggerInterface;

/**
 * Records the cinatra_dev_site log lines; throws on request.
 *
 * At the info line of a verified run it also makes the lock lapse (step
 * 'lapse'), calls the ensure service again (step 'nested') or releases the
 * binding as a release elsewhere whose lock lapsed would (step 'stale
 * release'), once.
 */
final class FaultLogger implements LoggerInterface {

  use RfcLoggerTrait;

  /**
   * {@inheritdoc}
   */
  public function log($level, string|\Stringable $message, array $context = []): void {
    if (($context['channel'] ?? NULL) !== 'cinatra_dev_site') {
      return;
    }
    EnsureFault::fail('log');
    $placeholders = array_filter($context, static fn($key): bool => str_starts_with((string) $key, '@'), ARRAY_FILTER_USE_KEY);
    EnsureFault::$lines[] = $level . ' ' . strtr((string) $message, $placeholders);
    if ((int) $level !== RfcLogLevel::INFO || !in_array(EnsureFault::$step, ['lapse', 'nested', 'stale release'], TRUE)) {
      return;
    }
    $step = EnsureFault::$step;
    EnsureFault::$step = NULL;
    match ($step) {
      'lapse' => EnsureFault::lapse(),
      'nested' => EnsureFault::nest(),
      'stale release' => EnsureFault::staleRelease(),
    };
  }

}
