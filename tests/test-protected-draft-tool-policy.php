<?php

declare(strict_types=1);

/** Native wrapper-policy cases using dependency doubles, not Drupal/MCP proof. */

namespace Drupal\Core\Session {
  interface AccountInterface { public function hasPermission(string $permission): bool; }
}
namespace Drupal\Core\Access {
  interface AccessResultInterface { public function isAllowed(): bool; }
  final class AccessResult implements AccessResultInterface {
    public function __construct(private bool $allowed) {}
    public static function allowed(): self { return new self(TRUE); }
    public static function forbidden(): self { return new self(FALSE); }
    public static function allowedIfHasPermission($account, string $permission): self { return new self($account->hasPermission($permission)); }
    public function andIf(self $other): self { return new self($this->allowed && $other->allowed); }
    public function isAllowed(): bool { return $this->allowed; }
  }
}
namespace Drupal\Core\StringTranslation {
  final class TranslatableMarkup {
    public function __construct(public string $message, public array $arguments = []) {}
  }
}
namespace Drupal\mcp_tools\Service {
  final class AccessManager {
    public const SCOPE_READ = 'read'; public const SCOPE_WRITE = 'write'; public const WRITE_KIND_CONTENT = 'content';
    public function __construct(public array $scopes = ['read','write'], public bool $readOnly = FALSE, public bool $contentWrite = TRUE) {}
    public function hasScope(string $scope): bool { return in_array($scope, $this->scopes, TRUE); }
    public function isReadOnlyMode(): bool { return $this->readOnly; }
    public function isWriteKindAllowed(string $kind): bool { return $kind === self::WRITE_KIND_CONTENT && $this->contentWrite; }
  }
  final class McpToolCallContext {
    public int $enters = 0; public int $leaves = 0;
    public function enter(): void { $this->enters++; }
    public function leave(): void { $this->leaves++; }
  }
}
namespace Drupal\tool {
  final class ExecutableResult {
    public function __construct(public bool $ok, public $message, public array $data = []) {}
    public static function success($message, array $data): self { return new self(TRUE, $message, $data); }
    public static function failure($message): self { return new self(FALSE, $message); }
  }
}
namespace Drupal\tool\Tool {
  enum ToolOperation { case Read; case Write; case Unknown; }
  final class ToolDefinition {
    public function __construct(private ToolOperation $operation) {}
    public function getOperation(): ToolOperation { return $this->operation; }
  }
  abstract class ToolBase {
    protected $currentUser; protected $definition;
    public function getPluginDefinition() { return $this->definition; }
  }
}
namespace {
  require_once dirname(__DIR__) . '/src/ProtectedDraft/ProtectedDraftRefusal.php';
  require_once dirname(__DIR__) . '/src/Tool/ProtectedDraftToolBase.php';
  use Drupal\cinatra\Tool\ProtectedDraftToolBase;
  use Drupal\mcp_tools\Service\AccessManager;
  use Drupal\mcp_tools\Service\McpToolCallContext;
  use Drupal\tool\Tool\ToolDefinition;
  use Drupal\tool\Tool\ToolOperation;
  final class PolicyAccount implements Drupal\Core\Session\AccountInterface {
    public function __construct(private bool $permitted) {}
    public function hasPermission(string $permission): bool { return $this->permitted && $permission === 'mcp_tools use content'; }
  }
  final class NativePolicyTool extends ProtectedDraftToolBase {
    public int $operations = 0;
    public function __construct($definition, ?AccessManager $manager, public McpToolCallContext $context, bool $permission = TRUE, private bool $fail = FALSE) {
      $this->definition = $definition; $this->mcpAccess = $manager; $this->mcpContext = $context; $this->currentUser = new PolicyAccount($permission);
    }
    public function executeNative(): Drupal\tool\ExecutableResult { return $this->doExecute([]); }
    protected function runProtectedOperation(array $values): array {
      $this->operations++;
      if ($this->fail) throw new RuntimeException('private database credential must not leak');
      return ['revision_id' => 41];
    }
  }
  $cases = [];
  $denials = [
    'missing MCP integration' => [new ToolDefinition(ToolOperation::Write), NULL, TRUE],
    'missing READ on writer' => [new ToolDefinition(ToolOperation::Write), new AccessManager(['write']), TRUE],
    'missing WRITE on writer' => [new ToolDefinition(ToolOperation::Write), new AccessManager(['read']), TRUE],
    'read-only site' => [new ToolDefinition(ToolOperation::Write), new AccessManager(['read','write'], TRUE), TRUE],
    'content kind disabled' => [new ToolDefinition(ToolOperation::Write), new AccessManager(['read','write'], FALSE, FALSE), TRUE],
    'account category denied' => [new ToolDefinition(ToolOperation::Write), new AccessManager(), FALSE],
    'missing READ on reader' => [new ToolDefinition(ToolOperation::Read), new AccessManager(['write']), TRUE],
    'unknown operation metadata' => [new ToolDefinition(ToolOperation::Unknown), new AccessManager(), TRUE],
    'missing definition metadata' => [NULL, new AccessManager(), TRUE],
  ];
  foreach ($denials as $name => [$definition, $manager, $permission]) {
    $cases[$name] = static function () use ($definition, $manager, $permission): void {
      $context = new McpToolCallContext(); $tool = new NativePolicyTool($definition, $manager, $context, $permission);
      if ($tool->access() || $tool->executeNative()->ok || $tool->operations !== 0 || $context->enters !== 0) {
        throw new RuntimeException('Denied wrapper reached content operation or context.');
      }
    };
  }
  $cases['READ works without WRITE in read-only mode'] = static function (): void {
    $context = new McpToolCallContext(); $tool = new NativePolicyTool(new ToolDefinition(ToolOperation::Read), new AccessManager(['read'], TRUE, FALSE), $context);
    $result = $tool->executeNative();
    if (!$result->ok || $tool->operations !== 1 || $context->enters !== 1 || $context->leaves !== 1 || $result->data['contract'] !== ProtectedDraftToolBase::CONTRACT) {
      throw new RuntimeException('Read policy or balanced context failed.');
    }
  };
  $cases['authorized WRITE keeps both scopes and balanced context'] = static function (): void {
    $context = new McpToolCallContext(); $tool = new NativePolicyTool(new ToolDefinition(ToolOperation::Write), new AccessManager(), $context);
    if (!$tool->executeNative()->ok || $tool->operations !== 1 || $context->leaves !== 1) throw new RuntimeException('Write policy failed.');
  };
  $cases['provider error is private and context leaves'] = static function (): void {
    $context = new McpToolCallContext(); $tool = new NativePolicyTool(new ToolDefinition(ToolOperation::Write), new AccessManager(), $context, TRUE, TRUE);
    $result = $tool->executeNative();
    if ($result->ok || str_contains($result->message->message,'credential') || $context->leaves !== 1) throw new RuntimeException('Provider error leaked or context remained active.');
  };
  $failures = 0;
  foreach ($cases as $name => $case) {
    try { $case(); echo "PASS: $name\n"; }
    catch (Throwable $e) { $failures++; fwrite(STDERR,"FAIL: $name: {$e->getMessage()}\n"); }
  }
  echo count($cases) . " cases; $failures failures; 0 skipped (dependency doubles, not MCP runtime)\n";
  exit($failures ? 1 : 0);
}
