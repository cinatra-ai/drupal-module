<?php

declare(strict_types=1);

namespace Drupal\cinatra_dev_site_page_test;

use Drupal\Core\Entity\Sql\SqlContentEntityStorage;

/**
 * The moderation state storage of the page migration tests.
 *
 * A state row revision that a test names in PageMutation::$unreadable cannot
 * be loaded, as a row that its storage can no longer read.
 */
final class UnreadableStateStorage extends SqlContentEntityStorage {

  /**
   * {@inheritdoc}
   */
  public function loadRevision($revision_id) {
    return PageMutation::unreadable('content_moderation_state', $revision_id) ? NULL : parent::loadRevision($revision_id);
  }

}
