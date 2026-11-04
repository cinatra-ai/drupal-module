<?php

declare(strict_types=1);

namespace Drupal\cinatra_dev_site_page_test;

use Drupal\node\NodeStorage;

/**
 * The node storage of the page migration tests.
 *
 * A node revision that a test names in PageMutation::$unreadable cannot be
 * loaded, as a revision that its storage can no longer read.
 */
final class UnreadableNodeStorage extends NodeStorage {

  /**
   * {@inheritdoc}
   */
  public function loadRevision($revision_id) {
    return PageMutation::unreadable('node', $revision_id) ? NULL : parent::loadRevision($revision_id);
  }

}
