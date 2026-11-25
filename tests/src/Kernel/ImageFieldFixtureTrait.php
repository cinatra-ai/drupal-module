<?php

declare(strict_types=1);

namespace Drupal\Tests\cinatra\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;

/**
 * A core image field and real image files for the stored-value tests.
 */
trait ImageFieldFixtureTrait {

  /**
   * Adds a revisionable, translatable image field to the given bundles.
   */
  protected function createImageField(array $bundles): void {
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    FieldStorageConfig::create([
      'entity_type' => 'node',
      'field_name' => 'field_image',
      'type' => 'image',
      'cardinality' => 1,
      'translatable' => TRUE,
    ])->save();
    foreach ($bundles as $bundle) {
      FieldConfig::create([
        'entity_type' => 'node',
        'bundle' => $bundle,
        'field_name' => 'field_image',
        'label' => 'Image',
        'translatable' => TRUE,
      ])->save();
    }
  }

  /**
   * Saves a permanent PNG file of the given size and returns its entity.
   */
  protected function createImageFile(string $name, int $width, int $height): File {
    $image = imagecreatetruecolor($width, $height);
    ob_start();
    imagepng($image);
    $png = (string) ob_get_clean();
    $uri = 'public://' . $name;
    file_put_contents($uri, $png);
    $file = File::create(['uri' => $uri, 'filename' => $name, 'status' => 1, 'uid' => 1]);
    $file->save();
    return $file;
  }

}
