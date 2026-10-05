<?php

declare(strict_types=1);

namespace Drupal\cinatra\ProtectedDraft;

/**
 * A protected edit could not be performed without changing the live page.
 */
final class ProtectedDraftRefusal extends \RuntimeException {
}
