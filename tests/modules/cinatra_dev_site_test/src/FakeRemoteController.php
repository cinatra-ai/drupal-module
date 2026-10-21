<?php

declare(strict_types=1);

namespace Drupal\cinatra_dev_site_test;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\tool\Tool\ToolDefinition;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stands in for the MCP Tools remote controller, in that controller's order.
 *
 * Mirrors drupal/mcp_tools 1.0.0-beta18 (commit 737803e7),
 * modules/mcp_tools_remote/src/Controller/McpToolsRemoteController.php
 * handle() lines 86-171. It leaves out only the mcp/sdk check.
 */
final class FakeRemoteController implements ContainerInjectionInterface {

  /**
   * The three fixed gateway tool names.
   */
  public const GATEWAY_TOOLS = [
    'mcp_tools/discover-tools',
    'mcp_tools/get-tool-info',
    'mcp_tools/execute-tool',
  ];

  /**
   * The number of create() calls.
   *
   * @var int
   */
  public static int $built = 0;

  /**
   * The number of served requests.
   *
   * @var int
   */
  public static int $served = 0;

  /**
   * Constructs the controller.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Component\Plugin\PluginManagerInterface $toolManager
   *   The tool manager.
   * @param object $repository
   *   The server config repository.
   * @param \Drupal\Core\Session\AccountSwitcherInterface $accountSwitcher
   *   The account switcher.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state store.
   */
  public function __construct(
    private ConfigFactoryInterface $configFactory,
    private PluginManagerInterface $toolManager,
    private object $repository,
    private AccountSwitcherInterface $accountSwitcher,
    private EntityTypeManagerInterface $entityTypeManager,
    private StateInterface $state,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    static::$built++;
    return new static(
      $container->get('config.factory'),
      $container->get('plugin.manager.tool'),
      $container->get('mcp_tools.server_config_repository'),
      $container->get('account_switcher'),
      $container->get('entity_type.manager'),
      $container->get('state'),
    );
  }

  /**
   * Handles a request in the remote controller's order.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The answer.
   */
  public function handle(Request $request): Response {
    $settings = $this->configFactory->get('mcp_tools_remote.settings');
    // 1. Disabled.
    if (!$settings->get('enabled')) {
      return new Response('Not found.', 404);
    }
    // 2. Client IP, compared literally.
    $allowed_ips = (array) ($settings->get('allowed_ips') ?? []);
    if ($allowed_ips !== [] && !in_array($request->getClientIp(), $allowed_ips, TRUE)) {
      return new Response('Not found.', 404);
    }
    // 3. Origin, hosts compared literally.
    $allowed_origins = (array) ($settings->get('allowed_origins') ?? []);
    $origin = (string) $request->headers->get('Origin', '');
    if ($origin !== '') {
      $origin_host = (string) parse_url($origin, PHP_URL_HOST);
      $ok = $allowed_origins !== [] ? in_array($origin_host, $allowed_origins, TRUE) : $origin_host === $request->getHost();
      if (!$ok) {
        return new Response('Not found.', 404);
      }
    }
    elseif ($allowed_origins !== [] && !in_array($request->getHost(), $allowed_origins, TRUE)) {
      return new Response('Not found.', 404);
    }
    // 4. Accept.
    $accept = (string) $request->headers->get('Accept', '');
    $needs = $request->isMethod('POST') ? ['application/json', 'text/event-stream'] : ['text/event-stream'];
    foreach ($needs as $type) {
      if (!str_contains($accept, $type)) {
        return new Response('Not acceptable.', 406);
      }
    }
    // 5. The key.
    $key = $this->extractApiKey($request);
    $expected = $this->state->get('cinatra_dev_site_test.key');
    if ($key === NULL || !is_string($expected) || $expected === '' || !hash_equals($expected, $key)) {
      $response = new JsonResponse(['error' => 'Authentication required'], 401);
      $response->headers->set('WWW-Authenticate', 'Bearer realm="mcp_tools_remote"');
      return $response;
    }
    $extra = $this->state->get('cinatra_dev_site_test.extra_read');
    // 6. The profile.
    $server_id = trim((string) ($settings->get('server_id') ?? ''));
    $profile = NULL;
    if ($server_id !== '') {
      if ($extra === 'before') {
        $this->repository->getServer($server_id);
      }
      $profile = $this->repository->getServer($server_id);
      if ($profile === NULL) {
        return new Response('Configured MCP server profile not found: ' . $server_id, 500);
      }
    }
    // 7. Profile access and transport.
    if ($profile !== NULL) {
      $access = $this->repository->checkAccess($profile, $request);
      if (empty($access['allowed'])) {
        return new Response((string) ($access['message'] ?? 'Access denied.'), 403);
      }
      if (!$this->repository->allowsTransport($profile, 'http')) {
        return new Response('Server profile does not allow HTTP transport.', 403);
      }
    }
    if ($extra === 'after' && $server_id !== '') {
      $this->repository->getServer($server_id);
    }
    // 8. Scopes.
    $scopes = ['read', 'write'];
    if ($profile !== NULL && !empty($profile['scopes'])) {
      $scopes = array_values(array_intersect($scopes, (array) $profile['scopes']));
      if ($scopes === []) {
        return new Response('Access denied: no permitted scopes for this server profile.', 403);
      }
    }
    // 9. The execution account.
    $uid = (int) ($settings->get('uid') ?? 0);
    if ($uid === 0 || ($uid === 1 && !$settings->get('allow_uid1'))) {
      return new Response('Execution user not configured.', 500);
    }
    $account = $this->entityTypeManager->getStorage('user')->load($uid);
    if ($account === NULL) {
      return new Response('Execution user not found.', 500);
    }
    // 10. The switch, outside the try.
    $this->accountSwitcher->switchTo($account);
    try {
      // 11. The body.
      if ($extra === 'late' && $server_id !== '') {
        $this->repository->getServer($server_id);
      }
      static::$served++;
      $body = ['listed' => $this->listed($profile)];
      $call = $request->query->get('call');
      if (is_string($call) && $call !== '') {
        $body['call'] = $this->call($call, $profile);
      }
      return new JsonResponse($body);
    }
    finally {
      $this->accountSwitcher->switchBack();
    }
  }

  /**
   * Lists the tool names the server would register.
   *
   * @param array|null $profile
   *   The profile.
   *
   * @return string[]
   *   The listed names.
   */
  private function listed(?array $profile): array {
    if ($profile !== NULL && !empty($profile['gateway_mode'])) {
      return self::GATEWAY_TOOLS;
    }
    return array_keys($this->eligible($profile));
  }

  /**
   * Calls a tool the way the call handler does.
   *
   * @param string $id
   *   The tool id.
   * @param array|null $profile
   *   The profile.
   *
   * @return string
   *   'called' or 'Unknown tool'.
   */
  private function call(string $id, ?array $profile): string {
    if (!array_key_exists($id, $this->eligible($profile))) {
      return 'Unknown tool';
    }
    $this->toolManager->createInstance($id);
    return 'called';
  }

  /**
   * Returns the definitions that pass the provider filter.
   *
   * @param array|null $profile
   *   The profile.
   *
   * @return array
   *   The eligible definitions, keyed by id.
   */
  private function eligible(?array $profile): array {
    $all = !empty($profile['include_all_tools']);
    $eligible = [];
    foreach ($this->toolManager->getDefinitions() as $id => $definition) {
      if (!$definition instanceof ToolDefinition) {
        continue;
      }
      $provider = (string) $definition->getProvider();
      if ($all || str_starts_with($provider, 'mcp_tools')) {
        $eligible[$id] = $definition;
      }
    }
    return $eligible;
  }

  /**
   * Reads the key from the request.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return string|null
   *   The key, or NULL.
   */
  private function extractApiKey(Request $request): ?string {
    $header = (string) $request->headers->get('Authorization', '');
    if (str_starts_with($header, 'Bearer ')) {
      $value = trim(substr($header, 7));
      return $value !== '' ? $value : NULL;
    }
    $value = trim((string) $request->headers->get('X-MCP-Api-Key', ''));
    return $value !== '' ? $value : NULL;
  }

}
