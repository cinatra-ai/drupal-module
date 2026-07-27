<?php

declare(strict_types=1);

namespace Drupal\cinatra;

use Drupal\Component\Utility\Html;

/**
 * The authenticated-preview RENDER FLAG and the region-anchor markup it emits.
 *
 * WHY A FLAG AT ALL. cinatra#2046 asks for "server-emitted region anchors for
 * the scope-manifest fields on rendered nodes … inert for normal visitors". The
 * anchors have to come from THIS module (the reviewer surface is forbidden to
 * guess regions with CSS selectors), but they must never appear on a page a
 * normal visitor is served: they would change the public markup for everyone
 * and, on a themed site, could be styled or scraped.
 *
 * So the hook implementations in cinatra.module are registered GLOBALLY but
 * read this flag first and return untouched unless the module itself is
 * rendering the previewed node. A normal request never sets the flag, so the
 * public page is byte-identical to what it was before this feature existed.
 * This mirrors the WordPress adapter's `cinatra_preview_target` render flag
 * (wordpress-plugin#94) so both adapters have the same inertness property.
 *
 * RENDER-CACHE SAFETY. Anchored output must never be written to Drupal's render
 * cache, or a later anonymous visitor could be served the anchored markup from
 * cache. Two independent defences, both required:
 *  1. The controller strips `#cache[keys]` from the node build, so the build is
 *     neither read from nor written to the render cache — which ALSO guarantees
 *     `hook_entity_view_alter()` actually runs (it is a pre-render step that a
 *     render-cache HIT would skip entirely, silently producing an anchor-less
 *     page).
 *  2. The alter hook sets `#cache[max-age] = 0` on everything it touches, which
 *     bubbles up and makes the whole response uncacheable regardless.
 */
final class PreviewRender {

  /**
   * The node id currently being rendered by the preview route, or 0.
   *
   * Deliberately process-local static state rather than a service: the flag has
   * to be readable from procedural hook implementations with no container
   * plumbing, it lives for the duration of ONE render, and it is always
   * restored in a `finally` (see ::renderTarget()). The module already uses
   * static helpers for the same reason (PublishWebhook::sign()).
   */
  private static int $target = 0;

  /**
   * The node id the preview render is currently targeting (0 = not previewing).
   *
   * @return int
   *   The target node id, or 0 outside a preview render.
   */
  public static function target(): int {
    return self::$target;
  }

  /**
   * Whether the given node id is the current preview render target.
   *
   * Guarded on the id, not merely on "a preview is happening": one page render
   * can build MANY nodes (a teaser list, a related-content block, a menu), and
   * only the previewed one is the review target. Anchoring the others would
   * hand the host several same-named regions and make composition ambiguous.
   *
   * @param int|string|null $nid
   *   A node id in any of the shapes Drupal hands out (int, numeric string).
   *
   * @return bool
   *   TRUE when this node is the preview target.
   */
  public static function isTarget(int|string|null $nid): bool {
    if (self::$target === 0 || $nid === NULL || $nid === '') {
      return FALSE;
    }
    return (int) $nid === self::$target;
  }

  /**
   * Runs a callback with the preview render flag set to a node id.
   *
   * ALWAYS restores the previous value, including when the callback throws: a
   * leaked flag would anchor whatever renders next in the same request, which
   * is exactly the visitor-visible change this design forbids.
   *
   * @param int $nid
   *   The node id being previewed.
   * @param callable $callback
   *   The render callback.
   *
   * @return mixed
   *   Whatever the callback returns.
   */
  public static function renderTarget(int $nid, callable $callback): mixed {
    $previous = self::$target;
    self::$target = $nid;
    try {
      return $callback();
    }
    finally {
      self::$target = $previous;
    }
  }

  /**
   * The opening tag of a region anchor.
   *
   * The attribute contract is the host's: `data-cinatra-region="<field>"` is
   * the ONLY thing the capture pipeline keys on (it matches it as a whole
   * attribute inside a real tag and reads the element's geometry from the
   * rendered box), and the region name joins to the proposed field name.
   * `data-cinatra-node` is provenance for a human reading the captured source.
   *
   * Both interpolations are escaped even though both are module-derived
   * (a field machine name and an integer): the anchor is emitted as trusted
   * markup, so nothing may reach it unescaped.
   *
   * @param string $region
   *   The owned-region name.
   * @param int $nid
   *   The previewed node id.
   * @param string $tag
   *   The wrapper tag — `div` for a block-level field, `span` for the title,
   *   which is rendered inside a heading and must stay inline-valid.
   *
   * @return string
   *   The opening tag markup.
   */
  public static function anchorOpen(string $region, int $nid, string $tag = 'div'): string {
    return '<' . $tag . ' class="cinatra-region" data-cinatra-region="'
      . Html::escape($region) . '" data-cinatra-node="' . $nid . '">';
  }

  /**
   * The closing tag of a region anchor.
   *
   * @param string $tag
   *   The wrapper tag used by ::anchorOpen().
   *
   * @return string
   *   The closing tag markup.
   */
  public static function anchorClose(string $tag = 'div'): string {
    return '</' . $tag . '>';
  }

  /**
   * Whether some already-rendered markup carries this region's anchor.
   *
   * Makes anchoring IDEMPOTENT: a field that is somehow passed through the
   * alter twice (a nested build, a contrib re-render) is wrapped once, so the
   * host never sees two same-named regions for one field.
   *
   * @param string $markup
   *   Markup to inspect.
   * @param string $region
   *   The owned-region name.
   *
   * @return bool
   *   TRUE when the markup already carries the anchor.
   */
  public static function alreadyAnchored(string $markup, string $region): bool {
    return str_contains($markup, 'data-cinatra-region="' . Html::escape($region) . '"');
  }

}
