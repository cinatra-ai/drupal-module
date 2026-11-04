<?php

/**
 * @file
 * Runs the development site's install requirements as core's check does.
 *
 * Core checks a module that is not installed yet before its namespace is
 * registered. This child process registers only Composer's autoloader, so no
 * class of the module can be autoloaded. Before it loads either form it
 * records, as the premise, whether each class of the module can be
 * autoloaded. It prints the premise, the hook's result and, where core has the
 * class form, the class's result, as JSON.
 *
 * Arguments: the autoloader, the module folder, the phase.
 */

declare(strict_types=1);

[, $autoload, $module, $phase] = $argv;
require $autoload;
$module_classes = [
  'Drupal\cinatra_dev_site\CinatraDevSite',
  'Drupal\cinatra_dev_site\Controller\DevSiteRemoteEndpoint',
  'Drupal\cinatra_dev_site\Install\Requirements\CinatraDevSiteRequirements',
  'Drupal\cinatra_dev_site\Mcp\AllowListContainer',
  'Drupal\cinatra_dev_site\Mcp\ExposureDeniedException',
];
$premise = [];
foreach ($module_classes as $module_class) {
  $premise[$module_class] = class_exists($module_class);
}
require_once $module . '/cinatra_dev_site.install';

$plain = static function (array $requirements): array {
  foreach ($requirements as $key => $requirement) {
    if (isset($requirement['severity']) && $requirement['severity'] instanceof \UnitEnum) {
      $requirements[$key]['severity'] = $requirement['severity']->name;
    }
  }
  return $requirements;
};

$result = [
  'premise' => $premise,
  'hook' => $plain(cinatra_dev_site_requirements($phase)),
  'class' => NULL,
];
if (interface_exists('Drupal\Core\Extension\InstallRequirementsInterface')) {
  require_once $module . '/src/Install/Requirements/CinatraDevSiteRequirements.php';
  $class = 'Drupal\cinatra_dev_site\Install\Requirements\CinatraDevSiteRequirements';
  $result['class'] = $plain($class::getRequirements());
}
print json_encode($result, JSON_THROW_ON_ERROR);
