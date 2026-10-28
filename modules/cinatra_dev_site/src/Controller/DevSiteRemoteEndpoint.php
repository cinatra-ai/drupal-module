<?php

declare(strict_types=1);

namespace Drupal\cinatra_dev_site\Controller;

use Drupal\cinatra_dev_site\CinatraDevSite;
use Drupal\cinatra_dev_site\Mcp\AllowListContainer;
use Drupal\cinatra_dev_site\Mcp\ExposureDeniedException;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Wraps the remote MCP controller for the development site.
 *
 * Its first step is the exposure guard. While the exposure is not active it
 * hands the request to the inner controller unchanged and touches nothing.
 * While it is active the inner controller gets only the allow-list container.
 */
final class DevSiteRemoteEndpoint implements ContainerInjectionInterface {

  /**
   * The registry ids served empty, built with no provider.
   */
  private const EMPTY_REGISTRIES = [
    'mcp_tools.resource_registry',
    'mcp_tools.prompt_registry',
  ];

  /**
   * Constructs the endpoint.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The container.
   * @param \Psr\Log\LoggerInterface $logger
   *   The cinatra_dev_site logger channel.
   * @param string $innerClass
   *   The inner controller class.
   * @param array $registryClasses
   *   The registry class for each id of EMPTY_REGISTRIES.
   */
  public function __construct(
    private readonly ContainerInterface $container,
    private readonly LoggerInterface $logger,
    private readonly string $innerClass,
    private readonly array $registryClasses,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new self(
      $container,
      $container->get('logger.factory')->get('cinatra_dev_site'),
      (string) $container->getParameter('cinatra_dev_site.remote_controller'),
      (array) $container->getParameter('cinatra_dev_site.empty_registries'),
    );
  }

  /**
   * Handles the remote request through the inner controller.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The inner controller's response, or the exposure's refusal.
   */
  public function handle(Request $request): Response {
    $active = CinatraDevSite::exposureActive();
    $inner = $this->innerClass;
    if (!class_exists($inner) || !is_subclass_of($inner, ContainerInjectionInterface::class)) {
      $this->logger->error('The remote controller class is not available.');
      return new Response('Not found.', 404);
    }
    if (!$active) {
      return $inner::create($this->container)->handle($request);
    }
    $served = ['mcp_tools.client_bridge' => NULL];
    foreach (self::EMPTY_REGISTRIES as $id) {
      $class = $this->registryClasses[$id] ?? NULL;
      if (!is_string($class) || !class_exists($class)) {
        $this->logger->error('A registry class of the exposure is not available.');
        return new Response('Not found.', 404);
      }
      $served[$id] = new $class([]);
    }
    try {
      return $inner::create(new AllowListContainer($this->container, $served))->handle($request);
    }
    catch (ExposureDeniedException $e) {
      $this->logger->warning('The exposure refused a remote request.');
      return new Response(CinatraDevSite::DENY_TEXT, 403);
    }
  }

}
