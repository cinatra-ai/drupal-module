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
   * Adds a link field and an entity reference field to the given bundles.
   *
   * Together with the body field they are the stored shapes whose empty item
   * is a clear: formatted text, a link and a reference.
   */
  protected function createClearableFields(array $bundles): void {
    $storages = [
      'field_link' => ['type' => 'link', 'settings' => []],
      'field_ref' => [
        'type' => 'entity_reference',
        'settings' => ['target_type' => 'user'],
      ],
    ];
    foreach ($storages as $name => $storage) {
      FieldStorageConfig::create([
        'entity_type' => 'node',
        'field_name' => $name,
        'type' => $storage['type'],
        'cardinality' => 1,
        'translatable' => TRUE,
        'settings' => $storage['settings'],
      ])->save();
    }
    foreach ($bundles as $bundle) {
      FieldConfig::create([
        'entity_type' => 'node',
        'bundle' => $bundle,
        'field_name' => 'field_link',
        'label' => 'Link',
        'translatable' => TRUE,
        'settings' => ['title' => 0, 'link_type' => 17],
      ])->save();
      FieldConfig::create([
        'entity_type' => 'node',
        'bundle' => $bundle,
        'field_name' => 'field_ref',
        'label' => 'Reference',
        'translatable' => TRUE,
        'settings' => ['handler' => 'default:user'],
      ])->save();
    }
  }

  /**
   * The values the clear tests start from, and every way to clear them.
   *
   * @return array
   *   Field name to the stored items and the clears that must empty it.
   */
  protected function clearCases(): array {
    $body = [
      'value' => 'Old body',
      'summary' => 'Old summary',
      'format' => 'plain_text',
    ];
    return [
      'body' => [
        'initial' => [$body],
        'clears' => [
          'empty item' => [['value' => '']],
          'empty item with empty summary' => [
            ['value' => '', 'summary' => ''],
          ],
          'empty item with an unknown format' => [
            ['value' => '', 'format' => 'no_such_format'],
          ],
          'empty list' => [],
        ],
      ],
      'field_link' => [
        'initial' => [
          ['uri' => 'https://example.com/', 'title' => '', 'options' => []],
        ],
        'clears' => [
          'empty item' => [['uri' => '']],
          'empty list' => [],
        ],
      ],
      'field_ref' => [
        'initial' => [['target_id' => 1]],
        'clears' => [
          'empty item' => [['target_id' => NULL]],
          'empty list' => [],
        ],
      ],
    ];
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
