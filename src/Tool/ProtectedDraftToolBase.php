<?php

declare(strict_types=1);

namespace Drupal\cinatra\Tool;

use Drupal\cinatra\ProtectedDraft\ProtectedDraftRefusal;
use Drupal\cinatra\ProtectedDraft\ProtectedDraftService;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_tools\Service\AccessManager;
use Drupal\mcp_tools\Service\McpToolCallContext;
use Drupal\tool\ExecutableResult;
use Drupal\tool\Tool\ToolBase;
use Drupal\tool\Tool\ToolDefinition;
use Drupal\tool\Tool\ToolOperation;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Optional Tool API integration using the existing MCP account and scopes.
 *
 * Extend ToolBase rather than a class in optional MCP Tools: a site with Tool
 * API alone can still discover plugins without a missing-parent-class error.
 * Such a site is denied access and execution, before any revision is loaded.
 */
abstract class ProtectedDraftToolBase extends ToolBase {

  public const CONTRACT = 'cinatra.protected-draft/v1';

  /**
   * Protected revision service for the requested operation.
   *
   * @var ProtectedDraftService
   */
  protected ProtectedDraftService $protectedDraft;
  /**
   * Existing MCP scope and write-policy service, when installed.
   *
   * @var AccessManager|null
   */
  protected ?AccessManager $mcpAccess = NULL;
  /**
   * Existing MCP tool-call context, when installed.
   *
   * @var McpToolCallContext|null
   */
  protected ?McpToolCallContext $mcpContext = NULL;
  /**
   * Module log channel for failures that are not refusals, when available.
   *
   * @var LoggerInterface|null
   */
  protected ?LoggerInterface $protectedDraftLogger = NULL;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->protectedDraft = $container->get('cinatra.protected_draft');
    if ($container->has('mcp_tools.access_manager') && $container->has('mcp_tools.tool_call_context')) {
      $instance->mcpAccess = $container->get('mcp_tools.access_manager');
      $instance->mcpContext = $container->get('mcp_tools.tool_call_context');
    }
    // Logging is best effort: a missing or broken log channel leaves the
    // logger unset and never prevents the tool from returning its result.
    try {
      if ($container->has('logger.factory')) {
        $logger = $container->get('logger.factory')->get('cinatra');
        if ($logger instanceof LoggerInterface) {
          $instance->protectedDraftLogger = $logger;
        }
      }
    }
    catch (\Throwable) {
      $instance->protectedDraftLogger = NULL;
    }
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function access(?AccountInterface $account = NULL, $return_as_object = FALSE): bool|AccessResultInterface {
    $account = $account ?? $this->currentUser;
    $definition = $this->getPluginDefinition();
    if (!$definition instanceof ToolDefinition
      || !in_array($definition->getOperation(), [ToolOperation::Read, ToolOperation::Write], TRUE)) {
      $denied = AccessResult::forbidden();
      return $return_as_object ? $denied : FALSE;
    }
    $write = $definition instanceof ToolDefinition && $definition->getOperation() === ToolOperation::Write;
    $scopes_allowed = $this->mcpAccess !== NULL
      && $this->mcpAccess->hasScope(AccessManager::SCOPE_READ);
    if ($write) {
      $scopes_allowed = $scopes_allowed
        && $this->mcpAccess->hasScope(AccessManager::SCOPE_WRITE)
        && !$this->mcpAccess->isReadOnlyMode()
        && $this->mcpAccess->isWriteKindAllowed(AccessManager::WRITE_KIND_CONTENT);
    }
    $access = AccessResult::allowedIfHasPermission($account, 'mcp_tools use content')
      ->andIf($scopes_allowed ? AccessResult::allowed() : AccessResult::forbidden());
    return $return_as_object ? $access : $access->isAllowed();
  }

  /**
   * {@inheritdoc}
   */
  protected function doExecute(array $values): ExecutableResult {
    if (!$this->access()) {
      return ExecutableResult::failure(new TranslatableMarkup('The existing MCP content integration and its read/write permissions are required.'));
    }
    $this->mcpContext?->enter();
    try {
      $result = $this->runProtectedOperation($values);
      return ExecutableResult::success(new TranslatableMarkup('Stored page revision verified.'), [
        'contract' => self::CONTRACT,
        'result' => $result,
      ]);
    }
    catch (ProtectedDraftRefusal $e) {
      return ExecutableResult::failure(new TranslatableMarkup('@reason', ['@reason' => $e->getMessage()]));
    }
    catch (\Throwable $e) {
      // SQL/provider exceptions may contain private request or connection data.
      // Log the class and location only, never the message or a full path.
      try {
        $this->protectedDraftLogger?->error('Protected draft operation failed: @class at @file:@line.', [
          '@class' => self::loggedClassName($e),
          '@file' => basename($e->getFile()),
          '@line' => $e->getLine(),
        ]);
      }
      catch (\Throwable) {
        // A broken log channel must not change the private failure result.
      }
      return ExecutableResult::failure(new TranslatableMarkup('The page revision could not be verified; inspect it before retrying.'));
    }
    finally {
      $this->mcpContext?->leave();
    }
  }

  /**
   * Runs the requested protected revision operation.
   */
  abstract protected function runProtectedOperation(array $values): array;

  /**
   * Returns the class name of a failure without a file path.
   *
   * PHP names an anonymous class "<parent>@anonymous", a NUL byte, and then
   * its defining file path and line. Only the part up to "@anonymous" is
   * returned for such a class.
   */
  private static function loggedClassName(\Throwable $e): string {
    $class = explode("\0", $e::class, 2)[0];
    $anonymous = strpos($class, '@anonymous');
    return $anonymous === FALSE ? $class : substr($class, 0, $anonymous + strlen('@anonymous'));
  }

}
