<?php

declare(strict_types=1);

namespace Drupal\cinatra\Drush;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\Entity\File;
use Drupal\node\Entity\Node;
use Drupal\paragraphs\Entity\Paragraph;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush command: cinatra:import-website
 *
 * Crawls a public website, maps each page to Drupal landing_page nodes with
 * structured paragraph sections (via the Cinatra LLM bridge), and downloads
 * images / documents into the Drupal public file system.
 *
 * Usage:
 *   drush cinatra:import-website https://example.com/de --lang=de --limit=20
 */
final class ImportWebsiteCommands extends DrushCommands {

  private const UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('entity_type.manager'),
      $container->get('http_client'),
      $container->get('file_system'),
    );
  }

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ClientInterface $httpClient,
    private readonly FileSystemInterface $fileSystem,
  ) {
    parent::__construct();
  }

  // ---------------------------------------------------------------------------

  #[CLI\Command(name: 'cinatra:import-website', aliases: ['ciw'])]
  #[CLI\Argument(name: 'url', description: 'Home page URL (e.g. https://example.com/de)')]
  #[CLI\Option(name: 'lang', description: 'Drupal langcode for created nodes', suggestedValues: ['de', 'en', 'fr'])]
  #[CLI\Option(name: 'limit', description: 'Max pages to import (0 = unlimited)')]
  #[CLI\Option(name: 'delete-existing', description: 'Delete all existing landing_page nodes first')]
  #[CLI\Option(name: 'update', description: 'Update existing nodes (default: skip them)')]
  #[CLI\Option(name: 'node-type', description: 'Drupal content type to create', suggestedValues: ['landing_page'])]
  #[CLI\Usage(name: 'drush cinatra:import-website https://example.com/de --lang=de --limit=20', description: 'Import German pages')]
  public function importWebsite(
    string $url,
    array $options = [
      'lang' => 'de',
      'limit' => 20,
      'delete-existing' => FALSE,
      'update' => FALSE,
      'node-type' => 'landing_page',
    ],
  ): void {
    // This is a SERVER-SIDE (container) call to the Cinatra bridge, so it
    // prefers the container-reachable CINATRA_BASE_URL env (e.g.
    // http://host.docker.internal:3000 in the dev docker stack) and only falls
    // back to the stored cinatra_url. The stored cinatra_url is the
    // BROWSER-reachable widget origin (e.g. http://localhost:3000) and is not
    // necessarily resolvable from inside the Drupal container.
    $config = $this->configFactory->get('cinatra.settings');
    $envBase = rtrim((string) getenv('CINATRA_BASE_URL'), '/');
    $cinatraUrl = $envBase !== '' ? $envBase : rtrim((string) $config->get('cinatra_url'), '/');

    if ($cinatraUrl === '') {
      $this->logger()->error('Cinatra base URL is not configured. Set CINATRA_BASE_URL (server-side) or cinatra_url at /admin/config/services/cinatra.');
      return;
    }

    $lang = $options['lang'];
    $limit = (int) $options['limit'];
    $nodeType = $options['node-type'];

    $this->ensureLanguage($lang);

    $this->logger()->notice("Discovering pages at {url} …", ['url' => $url]);
    $pages = $this->discoverPages($url, $limit);

    if (empty($pages)) {
      $this->logger()->error('No pages discovered. Check the URL and try again.');
      return;
    }

    $this->logger()->notice("Found {n} page(s) to import.", ['n' => count($pages)]);

    if ($options['delete-existing']) {
      $this->deleteExistingNodes($nodeType);
    }

    $allowUpdate = (bool) $options['update'];
    $imported = 0;
    $skipped = 0;
    foreach ($pages as $i => $pageUrl) {
      $this->logger()->notice("  [{i}/{n}] {url}", ['i' => $i + 1, 'n' => count($pages), 'url' => $pageUrl]);
      try {
        $result = $this->importPage($pageUrl, $cinatraUrl, $lang, $nodeType, $allowUpdate);
        if ($result === 'skipped') {
          $skipped++;
        } else {
          $imported++;
        }
      }
      catch (\Throwable $e) {
        $this->logger()->warning("  SKIP: {msg}", ['msg' => $e->getMessage()]);
      }
    }

    \Drupal::service('cache_tags.invalidator')->invalidateTags(['node_list']);
    $this->logger()->success("Import complete. {n} created/updated, {s} skipped.", ['n' => $imported, 's' => $skipped]);
  }

  // ---------------------------------------------------------------------------
  // Page discovery
  // ---------------------------------------------------------------------------

  /** @return string[] */
  private function discoverPages(string $baseUrl, int $limit): array {
    $base = $this->normalizeBase($baseUrl);

    // Try sitemap.xml under the origin root.
    $origin = parse_url($baseUrl, PHP_URL_SCHEME) . '://' . parse_url($baseUrl, PHP_URL_HOST);
    $pages = $this->fromSitemap($origin . '/sitemap.xml', $base)
           ?: $this->fromSitemap($origin . '/sitemap_index.xml', $base)
           ?: $this->fromCrawl($baseUrl, $base);

    // Always include the home page itself.
    if (!in_array($baseUrl, $pages, TRUE)) {
      array_unshift($pages, $baseUrl);
    }

    if ($limit > 0) {
      $pages = array_slice($pages, 0, $limit);
    }

    return array_values(array_unique($pages));
  }

  /** @return string[] */
  private function fromSitemap(string $sitemapUrl, string $base): array {
    try {
      $xml = (string) $this->httpClient->get($sitemapUrl, ['timeout' => 10, 'headers' => ['User-Agent' => self::UA]])->getBody();
    }
    catch (\Throwable) {
      return [];
    }

    libxml_use_internal_errors(TRUE);
    $doc = simplexml_load_string($xml);
    if ($doc === FALSE) {
      return [];
    }

    $urls = [];
    // Sitemap index — recurse into child sitemaps.
    foreach ($doc->sitemap ?? [] as $child) {
      $childUrl = (string) $child->loc;
      $urls = array_merge($urls, $this->fromSitemap($childUrl, $base));
    }
    // Regular sitemap.
    foreach ($doc->url ?? [] as $entry) {
      $loc = (string) $entry->loc;
      if (str_starts_with($loc, $base)) {
        $urls[] = $loc;
      }
    }

    return $urls;
  }

  /** @return string[] */
  private function fromCrawl(string $startUrl, string $base): array {
    try {
      $html = (string) $this->httpClient->get($startUrl, ['timeout' => 15, 'headers' => ['User-Agent' => self::UA]])->getBody();
    }
    catch (\Throwable) {
      return [];
    }

    $dom = $this->parseHtml($html);
    $urls = [$startUrl];
    foreach ($dom->getElementsByTagName('a') as $a) {
      $href = $a->getAttribute('href');
      $abs = $this->toAbsolute($href, $startUrl);
      if ($abs && str_starts_with($abs, $base) && !in_array($abs, $urls, TRUE)) {
        $urls[] = $abs;
      }
    }

    return $urls;
  }

  // ---------------------------------------------------------------------------
  // Per-page import
  // ---------------------------------------------------------------------------

  private function importPage(string $pageUrl, string $cinatraUrl, string $lang, string $nodeType, bool $allowUpdate = FALSE): string {
    $existing = $this->findExistingNode($pageUrl, $nodeType);
    if ($existing && !$allowUpdate) {
      $this->logger()->notice("  → skipping (node {nid} exists; pass --update to overwrite)", ['nid' => $existing->id()]);
      return 'skipped';
    }
    // Fetch page HTML.
    try {
      $html = (string) $this->httpClient->get($pageUrl, [
        'timeout' => 20,
        'headers' => [
          'User-Agent'      => self::UA,
          'Accept-Language' => $lang . ',en;q=0.8',
        ],
      ])->getBody();
    }
    catch (RequestException $e) {
      throw new \RuntimeException("HTTP error for $pageUrl: " . $e->getMessage());
    }

    // Extract clean text for the LLM (avoids token bloat from full HTML).
    $cleanText = $this->extractText($html);

    // Ask the LLM bridge to structure the content.
    $structured = $this->callLlmBridge($cinatraUrl, $pageUrl, $cleanText);

    if (empty($structured['title']) && empty($structured['sections'])) {
      throw new \RuntimeException("LLM returned no usable content for $pageUrl");
    }

    $title = $structured['title'] ?? parse_url($pageUrl, PHP_URL_PATH);
    $sections = $structured['sections'] ?? [];

    // Build paragraph entities.
    $paragraphs = [];
    foreach ($sections as $section) {
      $para = $this->buildParagraph($section, $pageUrl);
      if ($para !== NULL) {
        $para->save();
        $paragraphs[] = ['target_id' => $para->id(), 'target_revision_id' => $para->getRevisionId()];
      }
    }

    // Use existing node (already fetched above) or create new.
    $node = $existing ?? Node::create([
      'type'     => $nodeType,
      'langcode' => $lang,
      'status'   => 1,
    ]);

    $node->setTitle($title);
    $node->set('langcode', $lang);
    $node->set('field_sections', $paragraphs);
    $node->setNewRevision(FALSE);
    $node->save();

    // Set URL alias.
    $alias = '/' . ltrim(parse_url($pageUrl, PHP_URL_PATH) ?? '', '/');
    \Drupal::service('path_alias.manager');
    $aliasStorage = $this->entityTypeManager->getStorage('path_alias');
    $existing = $aliasStorage->loadByProperties(['path' => '/node/' . $node->id()]);
    foreach ($existing as $a) {
      $a->delete();
    }
    $aliasStorage->create([
      'path'     => '/node/' . $node->id(),
      'alias'    => $alias,
      'langcode' => $lang,
    ])->save();

    return 'imported';
  }

  // ---------------------------------------------------------------------------
  // LLM bridge call
  // ---------------------------------------------------------------------------

  private function callLlmBridge(string $cinatraUrl, string $pageUrl, string $text): array {
    $system = <<<PROMPT
You are a Drupal content migration expert. Extract the structured content from the provided page text and return ONLY valid JSON matching the schema below. No explanations, no markdown fences.

Available paragraph types and their fields:
- hero_section: {"type":"hero_section","headline":"...","subheadline":"...","image_url":"https://...or empty"}
- feature_cards_section: {"type":"feature_cards_section","cards_title":"..."}
- feature_card: {"type":"feature_card","title":"...","body":"..."}
- benefits_section: {"type":"benefits_section","benefits_title":"..."}
- benefit_item: {"type":"benefit_item","title":"...","body":"..."}
- cloud_features_section: {"type":"cloud_features_section","features_headline":"...","features_list":"bullet1\nbullet2\n...","image_url":"https://...or empty"}
- stats_section: {"type":"stats_section","stat1_number":"...","stat1_label":"...","stat2_number":"...","stat2_label":"...","stat3_number":"...","stat3_label":"..."}
- contact_section: {"type":"contact_section","name":"...","phone":"...","email":"...","image_url":"https://...or empty"}
- downloads_section: {"type":"downloads_section","title":"...","body":"..."}
- text_section: {"type":"text_section","title":"...","body":"..."}

Rules:
- Use feature_cards_section (once) followed by multiple feature_card items for any card grid.
- Use benefits_section (once) followed by multiple benefit_item entries for any accordion/list section.
- For stats, always include exactly 3 stat pairs; use empty strings if fewer stats exist.
- image_url must be an absolute https:// URL present in the text, or empty string.
- Output schema: {"title":"...","sections":[...]}
PROMPT;

    $user = "Page URL: $pageUrl\n\nPage content:\n$text";

    try {
      $response = $this->httpClient->post($cinatraUrl . '/api/llm-bridge', [
        'timeout' => 120,
        'headers' => [
          'Content-Type'    => 'application/json',
          // Docker bridge IP is treated as local development loopback traffic
          // by Cinatra's LLM bridge.
          'X-Forwarded-For' => '172.17.0.1',
        ],
        'json' => [
          'system'   => $system,
          'user'     => $user,
          'agent_id' => 'drupal-importer',
        ],
      ]);

      $body = json_decode((string) $response->getBody(), TRUE);
      // Bridge wraps non-JSON output in {"output":"..."}.
      if (isset($body['output'])) {
        $inner = json_decode($body['output'], TRUE);
        return is_array($inner) ? $inner : [];
      }
      return is_array($body) ? $body : [];
    }
    catch (\Throwable $e) {
      throw new \RuntimeException("LLM bridge error: " . $e->getMessage());
    }
  }

  // ---------------------------------------------------------------------------
  // Paragraph builder
  // ---------------------------------------------------------------------------

  private function buildParagraph(array $section, string $pageBaseUrl): ?Paragraph {
    $type = $section['type'] ?? '';

    $fieldMap = match ($type) {
      'hero_section' => [
        'field_hero_headline'    => $section['headline'] ?? '',
        'field_hero_subheadline' => $section['subheadline'] ?? '',
        'field_hero_image'       => $this->downloadImage($section['image_url'] ?? '', $pageBaseUrl),
      ],
      'feature_card' => [
        'field_card_title' => $section['title'] ?? '',
        'field_card_body'  => $section['body'] ?? '',
      ],
      'feature_cards_section' => [
        'field_cards_title' => $section['cards_title'] ?? '',
      ],
      'benefit_item' => [
        'field_benefit_title' => $section['title'] ?? '',
        'field_benefit_body'  => $section['body'] ?? '',
      ],
      'benefits_section' => [
        'field_benefits_title' => $section['benefits_title'] ?? '',
      ],
      'cloud_features_section' => [
        'field_features_headline' => $section['features_headline'] ?? '',
        'field_features_list'     => $section['features_list'] ?? '',
        'field_features_image'    => $this->downloadImage($section['image_url'] ?? '', $pageBaseUrl),
      ],
      'stats_section' => [
        'field_stat1_number' => $section['stat1_number'] ?? '',
        'field_stat1_label'  => $section['stat1_label'] ?? '',
        'field_stat2_number' => $section['stat2_number'] ?? '',
        'field_stat2_label'  => $section['stat2_label'] ?? '',
        'field_stat3_number' => $section['stat3_number'] ?? '',
        'field_stat3_label'  => $section['stat3_label'] ?? '',
      ],
      'contact_section' => [
        'field_con_name'  => $section['name'] ?? '',
        'field_con_phone' => $section['phone'] ?? '',
        'field_con_email' => $section['email'] ?? '',
        'field_con_image' => $this->downloadImage($section['image_url'] ?? '', $pageBaseUrl),
      ],
      'downloads_section' => [
        'field_dl_title' => $section['title'] ?? '',
        'field_dl_body'  => $section['body'] ?? '',
      ],
      'text_section' => [
        'field_text_title' => $section['title'] ?? '',
        'field_text_body'  => $section['body'] ?? '',
      ],
      default => NULL,
    };

    if ($fieldMap === NULL) {
      $this->logger()->debug("Unknown paragraph type: {type}", ['type' => $type]);
      return NULL;
    }

    $para = Paragraph::create(['type' => $type]);
    foreach ($fieldMap as $field => $value) {
      if ($value === NULL || $value === '') {
        continue;
      }
      if ($value instanceof File) {
        $para->set($field, ['target_id' => $value->id(), 'alt' => $section['headline'] ?? $section['title'] ?? '']);
      }
      else {
        $para->set($field, $value);
      }
    }

    return $para;
  }

  // ---------------------------------------------------------------------------
  // Image download
  // ---------------------------------------------------------------------------

  private function downloadImage(string $url, string $pageBaseUrl): ?File {
    if ($url === '') {
      return NULL;
    }
    $abs = $this->toAbsolute($url, $pageBaseUrl);
    if ($abs === NULL) {
      return NULL;
    }

    try {
      $response = $this->httpClient->get($abs, ['timeout' => 30]);
      $contentType = $response->getHeaderLine('Content-Type');
      if (!str_starts_with($contentType, 'image/')) {
        return NULL;
      }

      $filename = basename(parse_url($abs, PHP_URL_PATH) ?: 'image.jpg');
      $filename = preg_replace('/[^a-z0-9._-]/i', '-', $filename);
      $dir = 'public://imported/';
      $this->fileSystem->prepareDirectory($dir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

      $uri = $this->fileSystem->saveData(
        (string) $response->getBody(),
        $dir . $filename,
        FileExists::Replace,
      );

      $file = File::create(['uri' => $uri, 'status' => 1]);
      $file->save();
      return $file;
    }
    catch (\Throwable $e) {
      $this->logger()->debug("Image download failed {url}: {msg}", ['url' => $abs, 'msg' => $e->getMessage()]);
      return NULL;
    }
  }

  // ---------------------------------------------------------------------------
  // HTML helpers
  // ---------------------------------------------------------------------------

  private function extractText(string $html): string {
    $dom = $this->parseHtml($html);

    // Remove noise elements before serializing.
    foreach (['script', 'style', 'nav', 'footer', 'header', 'form', 'iframe', 'noscript'] as $tag) {
      foreach (iterator_to_array($dom->getElementsByTagName($tag)) as $el) {
        $el->parentNode?->removeChild($el);
      }
    }

    // Serialize the entire cleaned document — saveHTML($node) drops content
    // unpredictably in PHP's DOMDocument; saveHTML() on the full doc is reliable.
    $text = $dom->saveHTML();

    // Strip all tags and collapse whitespace.
    $text = strip_tags((string) $text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/[ \t]+/', ' ', $text);
    $text = preg_replace('/(\n\s*){3,}/', "\n\n", $text);
    $text = trim($text ?? '');

    // Cap at ~12 000 chars to stay within LLM context.
    return mb_substr($text, 0, 12000);
  }

  private function parseHtml(string $html): \DOMDocument {
    $dom = new \DOMDocument();
    libxml_use_internal_errors(TRUE);
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();
    return $dom;
  }

  // ---------------------------------------------------------------------------
  // Utility helpers
  // ---------------------------------------------------------------------------

  private function normalizeBase(string $url): string {
    $parsed = parse_url($url);
    $base = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '');
    if (!empty($parsed['path'])) {
      // Keep everything up to (and including) the path prefix.
      $base .= '/' . ltrim($parsed['path'], '/');
    }
    return rtrim($base, '/');
  }

  private function toAbsolute(string $href, string $base): ?string {
    $href = trim($href);
    if ($href === '' || str_starts_with($href, '#') || str_starts_with($href, 'mailto:') || str_starts_with($href, 'tel:')) {
      return NULL;
    }
    if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) {
      return strtok($href, '#') ?: NULL;
    }
    $origin = parse_url($base, PHP_URL_SCHEME) . '://' . parse_url($base, PHP_URL_HOST);
    $abs = str_starts_with($href, '/') ? $origin . $href : $origin . '/' . ltrim($href, './');
    return strtok($abs, '#') ?: NULL;
  }

  private function findExistingNode(string $pageUrl, string $nodeType): ?Node {
    $alias = '/' . ltrim(parse_url($pageUrl, PHP_URL_PATH) ?? '', '/');
    $path = \Drupal::service('path_alias.manager')->getPathByAlias($alias);
    if (preg_match('#^/node/(\d+)$#', $path, $m)) {
      /** @var Node|null $node */
      $node = $this->entityTypeManager->getStorage('node')->load($m[1]);
      return ($node && $node->getType() === $nodeType) ? $node : NULL;
    }
    return NULL;
  }

  private function ensureLanguage(string $langcode): void {
    if ($langcode === 'en') {
      return;
    }
    $langManager = \Drupal::service('language_manager');
    if ($langManager->getLanguage($langcode)) {
      return;
    }
    // language module must be enabled first.
    $moduleInstaller = \Drupal::service('module_installer');
    $moduleInstaller->install(['language', 'locale'], TRUE);

    /** @var \Drupal\language\ConfigurableLanguageManagerInterface $langManager */
    $langManager = \Drupal::service('language_manager');
    try {
      $lang = \Drupal\language\Entity\ConfigurableLanguage::createFromLangcode($langcode);
      $lang->save();
      $this->logger()->notice("Installed language: {lang}", ['lang' => $langcode]);
    }
    catch (\Throwable $e) {
      $this->logger()->warning("Language install skipped: {msg}", ['msg' => $e->getMessage()]);
    }
  }

  private function deleteExistingNodes(string $nodeType): void {
    $nids = $this->entityTypeManager->getStorage('node')
      ->getQuery()
      ->condition('type', $nodeType)
      ->accessCheck(FALSE)
      ->execute();

    if (empty($nids)) {
      return;
    }
    $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($nids);
    $this->entityTypeManager->getStorage('node')->delete($nodes);
    $this->logger()->notice("Deleted {n} existing {type} node(s).", ['n' => count($nids), 'type' => $nodeType]);
  }

}
