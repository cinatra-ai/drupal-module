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
   * Adds a float and an integer field to the given bundles.
   *
   * The decimal field (precision 10, scale 2) is created by each test class.
   */
  protected function createNumberFields(array $bundles): void {
    foreach (['field_float' => 'float', 'field_int' => 'integer'] as $name => $type) {
      FieldStorageConfig::create([
        'entity_type' => 'node',
        'field_name' => $name,
        'type' => $type,
        'cardinality' => 1,
        'translatable' => TRUE,
      ])->save();
      foreach ($bundles as $bundle) {
        FieldConfig::create([
          'entity_type' => 'node',
          'bundle' => $bundle,
          'field_name' => $name,
          'label' => $name,
          'translatable' => TRUE,
        ])->save();
      }
    }
  }

  /**
   * Numbers a field cannot store exactly, as [field, value] pairs.
   *
   * @return array
   *   Named pairs, each refused before the save.
   */
  protected function inexactNumbers(): array {
    return [
      'float beyond double precision' => ['field_float', '9007199254740993'],
      'float in exponent form' => ['field_float', '1e3'],
      'decimal with more digits than its scale' => ['field_price', '1.239'],
      'decimal with more integer digits than its precision' => ['field_price', '12345678901234567890.1'],
      'integer given with a fraction' => ['field_int', '3.0'],
    ];
  }

  /**
   * Numbers a field stores exactly, as [field, value, stored] triples.
   *
   * @return array
   *   Named triples, each kept as sent.
   */
  protected function exactNumbers(): array {
    return [
      'float with a trailing zero' => ['field_float', '1.10', 1.1],
      'float 0.1' => ['field_float', '0.1', 0.1],
      'float 2.5' => ['field_float', '2.50', 2.5],
      'integer with a plus sign' => ['field_int', '+3', 3],
      'decimal with a leading zero' => ['field_price', '01.50', 1.5],
      'decimal given as a bare fraction' => ['field_price', '.5', 0.5],
    ];
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
