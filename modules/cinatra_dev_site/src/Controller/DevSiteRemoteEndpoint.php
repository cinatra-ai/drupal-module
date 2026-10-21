<?php

declare(strict_types=1);

namespace Drupal\cinatra_dev_site\Controller;

use Drupal\cinatra_dev_site\CinatraDevSite;
use Drupal\cinatra_dev_site\Mcp\ExecutionGate;
use Drupal\cinatra_dev_site\Mcp\ExposureDeniedException;
use Drupal\cinatra_dev_site\Mcp\ProfileBinding;
use Drupal\cinatra_dev_site\Mcp\ScopedToolContainer;
use Drupal\cinatra_dev_site\Mcp\ScopedToolManager;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Wraps the remote MCP controller for the armed development site.
 *
 * It decides nothing and reads no configuration, no profile and no tool
 * definition. While armed it hands the inner controller a container view
 * whose tool manager and account switcher follow the request's binding.
 */
final class DevSiteRemoteEndpoint implements ContainerInjectionInterface {

  /**
   * Constructs the endpoint.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The container.
   * @param \Psr\Log\LoggerInterface $logger
   *   The cinatra_dev_site logger channel.
   * @param string $innerClass
   *   The inner controller class.
   */
  public function __construct(
    private readonly ContainerInterface $container,
    private readonly LoggerInterface $logger,
    private readonly string $innerClass,
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
    );
  }

  /**
   * Handles the remote request through the inner controller.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The inner controller's response, or the gate's denial.
   */
  public function handle(Request $request): Response {
    $inner = $this->innerClass;
    if (!class_exists($inner) || !is_subclass_of($inner, ContainerInjectionInterface::class)) {
      $this->logger->error('The remote controller class is not available.');
      return new Response('Not found.', 404);
    }
    if (!CinatraDevSite::isArmed()) {
      return $inner::create($this->container)->handle($request);
    }
    $binding = new ProfileBinding($request);
    $request->attributes->set(CinatraDevSite::BINDING_ATTRIBUTE, $binding);
    $scoped = new ScopedToolManager($this->container->get('plugin.manager.tool'), $binding);
    $gate = new ExecutionGate($this->container->get('account_switcher'), $binding);
    try {
      return $inner::create(new ScopedToolContainer($this->container, $scoped, $gate))->handle($request);
    }
    catch (ExposureDeniedException $e) {
      $this->logger->warning('A remote request without a validated cinatra profile was denied.');
      return new Response(CinatraDevSite::DENY_TEXT, 403);
    }
  }

}
