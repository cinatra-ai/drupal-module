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

  protected ProtectedDraftService $protectedDraft;
  protected ?AccessManager $mcpAccess = NULL;
  protected ?McpToolCallContext $mcpContext = NULL;

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
      return ExecutableResult::failure(new TranslatableMarkup('The page revision could not be verified; inspect it before retrying.'));
    }
    finally {
      $this->mcpContext?->leave();
    }
  }

  abstract protected function runProtectedOperation(array $values): array;

}
