<?php

declare(strict_types=1);

namespace Drupal\Tests\cinatra\Kernel;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\KernelTests\KernelTestBase;
use Drupal\cinatra\PreviewRender;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;

/**
 * The region-anchor hook is registered, inert by default, and idempotent.
 *
 * `cinatra_entity_view_alter()` is registered GLOBALLY but must do nothing
 * unless this module is rendering that exact node through the authenticated
 * preview route. Two failure modes are invisible to a unit test, because both
 * live in Drupal's hook system rather than in the function body:
 *
 * - The implementation is never invoked at all (a renamed function, a module
 *   that stops being discovered). Nothing breaks; the reviewer surface simply
 *   loses every region and the host is left guessing with CSS selectors.
 * - The implementation is invoked when it must not be, and an ordinary
 *   visitor's page silently gains markup this module promised never to add.
 *
 * So the hook is exercised THROUGH the module handler — the same dispatch the
 * entity view builder performs — rather than by calling the function.
 *
 * @group cinatra
 */
final class PreviewRegionAnchorHookTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'cinatra',
    'node',
    'user',
    'system',
    'field',
    'text',
    'filter',
  ];

  /**
   * The node id used as the preview target.
   */
  private const NID = 42;

  /**
   * A node id that is rendered on the same page but is NOT the target.
   */
  private const OTHER_NID = 43;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
  }

  /**
   * Builds an unsaved node with a fixed id.
   *
   * The hook reads only the id and the render array, so no storage is
   * involved: the node never has to be written to run this contract.
   *
   * @param int $nid
   *   The node id to report.
   *
   * @return \Drupal\node\Entity\Node
   *   The node.
   */
  private function node(int $nid): Node {
    return Node::create(['nid' => $nid, 'type' => 'page', 'title' => 'Draft']);
  }

  /**
   * The view display the alter hook is handed.
   *
   * @return \Drupal\Core\Entity\Entity\EntityViewDisplay
   *   An unsaved default view display for the page bundle.
   */
  private function display(): EntityViewDisplay {
    return EntityViewDisplay::create([
      'targetEntityType' => 'node',
      'bundle' => 'page',
      'mode' => 'default',
      'status' => TRUE,
    ]);
  }

  /**
   * A node render array holding owned regions and chrome side by side.
   *
   * `links` has no `#field_name`, and `comment` is a field the module does not
   * own — both must come back untouched.
   *
   * @return array
   *   The render array.
   */
  private function build(): array {
    return [
      '#cache' => ['max-age' => Cache::PERMANENT, 'keys' => ['node', 'full']],
      'body' => [
        '#field_name' => 'body',
        '#markup' => 'Body text.',
        '#cache' => ['keys' => ['field', 'body']],
      ],
      'field_teaser' => ['#field_name' => 'field_teaser', '#markup' => 'Teaser.'],
      'comment' => ['#field_name' => 'comment', '#markup' => 'Comments.'],
      'links' => ['#theme' => 'links', '#links' => []],
    ];
  }

  /**
   * Runs the real hook dispatch for a node, at a given preview target.
   *
   * @param array $build
   *   The render array, altered in place.
   * @param int $nid
   *   The node the build belongs to.
   * @param int|null $target
   *   The preview target node id, or NULL for "no preview in progress".
   */
  private function alter(array &$build, int $nid, ?int $target): void {
    $moduleHandler = $this->container->get('module_handler');
    $node = $this->node($nid);
    $display = $this->display();
    $dispatch = function () use ($moduleHandler, &$build, $node, $display): void {
      $moduleHandler->alter('entity_view', $build, $node, $display);
    };
    if ($target === NULL) {
      $dispatch();
      return;
    }
    PreviewRender::renderTarget($target, $dispatch);
  }

  /**
   * The module actually implements the hook.
   *
   * Everything else in this class dispatches through the module handler, so
   * without this a lost implementation would read as "no anchors expected".
   */
  public function testTheHookIsRegistered(): void {
    $this->assertTrue(
      $this->container->get('module_handler')->hasImplementations('entity_view_alter', ['cinatra']),
      'The module must implement hook_entity_view_alter().',
    );
  }

  /**
   * Outside a preview render the build is returned byte-identical.
   *
   * This is the promise the feature is allowed to exist under: an ordinary
   * visitor's page is exactly what it was before the preview route existed.
   */
  public function testOrdinaryRenderIsUntouched(): void {
    $build = $this->build();
    $pristine = $build;
    $this->alter($build, self::NID, NULL);
    $this->assertSame($pristine, $build, 'A non-preview render must not be altered.');
  }

  /**
   * A non-target node inside a preview render is left alone.
   *
   * One preview page builds many nodes — teasers, related content, menus — and
   * anchoring any of them would hand the host several same-named regions.
   */
  public function testNonTargetNodeInsidePreviewIsUntouched(): void {
    $build = $this->build();
    $pristine = $build;
    $this->alter($build, self::OTHER_NID, self::NID);
    $this->assertSame($pristine, $build, 'Only the previewed node may be anchored.');
  }

  /**
   * The previewed node's owned field regions are anchored.
   */
  public function testOwnedRegionsAreAnchoredForTheTargetNode(): void {
    $build = $this->build();
    $this->alter($build, self::NID, self::NID);

    foreach (['body', 'field_teaser'] as $region) {
      $prefix = (string) ($build[$region]['#prefix'] ?? '');
      $this->assertStringContainsString('data-cinatra-region="' . $region . '"', $prefix);
      $this->assertStringContainsString('data-cinatra-node="' . self::NID . '"', $prefix);
      $this->assertSame('</div>', (string) ($build[$region]['#suffix'] ?? ''));
    }
  }

  /**
   * Chrome and unowned fields stay unanchored.
   */
  public function testUnownedElementsAreNotAnchored(): void {
    $build = $this->build();
    $this->alter($build, self::NID, self::NID);

    $this->assertArrayNotHasKey('#prefix', $build['comment'], 'An unowned field must not be anchored.');
    $this->assertArrayNotHasKey('#prefix', $build['links'], 'Chrome without a field name must not be anchored.');
  }

  /**
   * Anchored output can never be persisted in the render cache.
   *
   * Render-cache metadata bubbles UP, so clearing max-age on the parent alone
   * is not enough: an element carrying its OWN `#cache[keys]` would be cached
   * WITH the anchor and later served to an ordinary visitor.
   */
  public function testAnchoredOutputIsNotCacheable(): void {
    $build = $this->build();
    $this->assertArrayHasKey('keys', $build['body']['#cache']);
    $this->alter($build, self::NID, self::NID);

    $this->assertSame(0, $build['#cache']['max-age']);
    $this->assertSame(0, $build['body']['#cache']['max-age']);
    $this->assertArrayNotHasKey('keys', $build['body']['#cache'], 'An anchored element must not stay render-cacheable.');
  }

  /**
   * Passing the same build through the hook twice anchors it once.
   */
  public function testAnchoringIsIdempotent(): void {
    $build = $this->build();
    $this->alter($build, self::NID, self::NID);
    $once = $build;
    $this->alter($build, self::NID, self::NID);

    $this->assertSame($once, $build, 'A second pass must not add a second anchor.');
    $this->assertSame(1, substr_count((string) $build['body']['#prefix'], 'data-cinatra-region="body"'));
  }

  /**
   * The preview flag is restored even when the render throws.
   *
   * A leaked flag would anchor whatever renders next in the same request —
   * the visitor-visible change this design forbids.
   */
  public function testTheTargetFlagIsRestoredAfterThrow(): void {
    $this->assertSame(0, PreviewRender::target());
    try {
      PreviewRender::renderTarget(self::NID, function (): void {
        throw new \RuntimeException('render failed');
      });
      $this->fail('The exception must propagate.');
    }
    catch (\RuntimeException) {
      $this->addToAssertionCount(1);
    }
    $this->assertSame(0, PreviewRender::target(), 'The preview target must not leak past a failed render.');

    $build = $this->build();
    $pristine = $build;
    $this->alter($build, self::NID, NULL);
    $this->assertSame($pristine, $build, 'A leaked flag would anchor the next render.');
  }

}
