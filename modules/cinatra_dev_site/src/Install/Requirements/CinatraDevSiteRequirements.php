<?php

declare(strict_types=1);

namespace Drupal\cinatra_dev_site\Install\Requirements;

use Drupal\Core\Extension\InstallRequirementsInterface;
use Drupal\Core\Extension\Requirement\RequirementSeverity;

/**
 * Refuses the install on a site without the development marker.
 *
 * Core requires this file before the module's namespace is registered, so it
 * names no other class of this module.
 */
final class CinatraDevSiteRequirements implements InstallRequirementsInterface {

  /**
   * {@inheritdoc}
   */
  public static function getRequirements(): array {
    if (getenv('CINATRA_DEV_SITE') === '1') {
      return [];
    }
    return [
      'cinatra_dev_site_marker' => [
        'title' => 'Cinatra development site',
        'description' => 'This module installs only on a site with CINATRA_DEV_SITE=1.',
        'severity' => RequirementSeverity::Error,
      ],
    ];
  }

}
