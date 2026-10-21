<?php

declare(strict_types=1);

namespace Drupal\cinatra_dev_site\Install\Requirements;

use Drupal\cinatra_dev_site\CinatraDevSite;
use Drupal\Core\Extension\InstallRequirementsInterface;
use Drupal\Core\Extension\Requirement\RequirementSeverity;

/**
 * Refuses the install on a site without the development marker.
 */
final class CinatraDevSiteRequirements implements InstallRequirementsInterface {

  /**
   * {@inheritdoc}
   */
  public static function getRequirements(): array {
    if (CinatraDevSite::markerPresent()) {
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
