<?php

declare(strict_types=1);

namespace Drupal\cinatra\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Render\RendererInterface;
use Drupal\Component\Utility\Html;
use Drupal\cinatra\PreviewAuth;
use Drupal\cinatra\PreviewRender;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Renders the host-facing authenticated preview of a node (cinatra#2046).
 *
 * WHAT THE HOST DOES WITH THIS. At review-gate creation the Cinatra host takes
 * ONE signed fetch of this route, sanitizes the page, renders it in an isolated
 * JavaScript-off browser and PINS the screenshot next to the content snapshot.
 * The reviewer then sees the change inside the site's own theme — for as long
 * as the gate exists, from an immutable capture, with no further request to
 * this site. So this route has exactly two jobs: render the node as the site
 * really renders it (drafts included), and mark the regions the module owns.
 *
 * DRAFTS ARE THE POINT. The lifecycle contract HOLDS a proposed change until a
 * reviewer approves it and stages the write unpublished; a preview that only
 * rendered published nodes would be a preview of the wrong thing. Access is
 * therefore not "may the current user view this node" (there is no user — it is
 * a server-to-server call) but "is this request signed by the connected host",
 * settled by the route's access check BEFORE this controller is reached and
 * before any node is loaded.
 *
 * RENDER-CACHE BYPASS. `#cache[keys]` is stripped from the node build. That is
 * not an optimisation opt-out: `hook_entity_view_alter()` — where the anchors
 * are emitted — is a pre-render step that a render-cache HIT would skip, so a
 * cached node would preview WITHOUT anchors; and a MISS would write the
 * anchored markup into the shared render cache, where an ordinary visitor could
 * later be served it. Stripping the keys closes both directions.
 *
 * THE PAGE TITLE *IS* THE TITLE REGION. Drupal renders a node's title through
 * the page-title block, not as a field inside the node build — core themes'
 * full-view node templates deliberately omit it there to avoid showing it
 * twice. So the title anchor is emitted by this route's title callback, which
 * is the one place the title is actually rendered. Anchoring a second copy
 * inside the node would put two same-named regions on the page; leaving the
 * page title unanchored would leave a STALE title heading right beside the
 * composed one, which is worse than no picture at all.
 */
final class PreviewController implements ContainerInjectionInterface {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly RendererInterface $renderer,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('renderer'),
    );
  }

  /**
   * Builds the anchored preview render for a node of ANY publication status.
   *
   * @param string $nid
   *   The RAW node id from the path (never an upcast entity — see the access
   *   check's deny-before-load note).
   *
   * @return array
   *   A render array. Drupal wraps it in the site's own page/theme chrome,
   *   which the reviewer surface shows as explicitly non-decisional context.
   */
  public function preview(string $nid): array {
    if (!ctype_digit($nid)) {
      throw new NotFoundHttpException();
    }
    $node = $this->entityTypeManager->getStorage('node')->load((int) $nid);
    if (!$node instanceof NodeInterface) {
      throw new NotFoundHttpException();
    }

    // Render INSIDE the target scope: Drupal renders a returned array later, in
    // the main-content renderer, by which time the flag would be restored and
    // no anchor would be emitted. `renderInIsolation()` gives that inner render
    // its own context; the wrapper below carries the uncacheable metadata.
    $rendered = PreviewRender::renderTarget((int) $node->id(), function () use ($node) {
      $build = $this->entityTypeManager->getViewBuilder('node')->view($node, 'full');
      unset($build['#cache']['keys']);
      $build['#cache']['max-age'] = 0;
      return $this->renderer->renderInIsolation($build);
    });

    return [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['cinatra-preview'],
        'data-cinatra-preview-node' => (string) $node->id(),
        'data-cinatra-preview-status' => $node->isPublished() ? 'published' : 'unpublished',
      ],
      // A MarkupInterface from Drupal's own renderer: already-safe markup that
      // the render system passes through unfiltered. Re-escaping it here would
      // corrupt the very page the host has to capture.
      'node' => ['#markup' => $rendered],
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * Title callback: the node's own title, wrapped in the title-region anchor.
   *
   * Returned as already-safe markup because the anchor is markup by nature; the
   * only external value in it — the title — is escaped here, so nothing
   * unescaped reaches the page. A node that cannot be loaded gets a neutral
   * heading and no anchor (the host then reports `title` as unplaced rather
   * than composing into a region that means nothing).
   *
   * @param string $nid
   *   The RAW node id from the path.
   *
   * @return \Drupal\Component\Render\MarkupInterface|string
   *   The page title.
   */
  public function title(string $nid) {
    if (!ctype_digit($nid)) {
      return 'Cinatra preview';
    }
    $node = $this->entityTypeManager->getStorage('node')->load((int) $nid);
    if (!$node instanceof NodeInterface) {
      return 'Cinatra preview';
    }
    return Markup::create(
      PreviewRender::anchorOpen(PreviewAuth::REGION_TITLE, (int) $node->id(), 'span')
      . Html::escape((string) $node->label())
      . PreviewRender::anchorClose('span')
    );
  }

}
