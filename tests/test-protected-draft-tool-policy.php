<?php

// Multiple dependency doubles deliberately share this standalone harness.
// phpcs:disable Squiz.Classes.ClassFileName.NoMatch


declare(strict_types=1);

/**
 * Native wrapper-policy cases using dependency doubles, not Drupal/MCP proof.
 */

namespace Drupal\Core\Session {

  /**
   * Provides the native account interface dependency double.
   */
  interface AccountInterface {

    /**
     * Reports has permission.
     */
    public function hasPermission(string $permission): bool;

  }

}

namespace Drupal\Core\Access {

  /**
   * Provides the native access result interface dependency double.
   */
  interface AccessResultInterface {

    /**
     * Reports is allowed.
     */
    public function isAllowed(): bool;

  }

  /**
   * Provides the native access result dependency double.
   */
  final class AccessResult implements AccessResultInterface {

    public function __construct(private bool $allowed) {}

    /**
     * Exercises allowed.
     */
    public static function allowed(): self {
      return new self(TRUE);
    }

    /**
     * Exercises forbidden.
     */
    public static function forbidden(): self {
      return new self(FALSE);
    }

    /**
     * Exercises allowed if has permission.
     */
    public static function allowedIfHasPermission($account, string $permission): self {
      return new self($account->hasPermission($permission));
    }

    /**
     * Exercises and if.
     */
    public function andIf(self $other): self {
      return new self($this->allowed && $other->allowed);
    }

    /**
     * Reports is allowed.
     */
    public function isAllowed(): bool {
      return $this->allowed;
    }

  }
}

namespace Drupal\Core\StringTranslation {

  /**
   * Provides the native translatable markup dependency double.
   */
  final class TranslatableMarkup {

    public function __construct(public string $message, public array $arguments = []) {}

  }
}

namespace Drupal\mcp_tools\Service {

  /**
   * Provides the native access manager dependency double.
   */
  final class AccessManager {
    public const SCOPE_READ = 'read';
    public const SCOPE_WRITE = 'write';
    public const WRITE_KIND_CONTENT = 'content';

    public function __construct(
      public array $scopes = [
        'read',
        'write',
      ],
      public bool $readOnly = FALSE,
      public bool $contentWrite = TRUE,
    ) {}

    /**
     * Reports has scope.
     */
    public function hasScope(string $scope): bool {
      return in_array($scope, $this->scopes, TRUE);
    }

    /**
     * Reports is read only mode.
     */
    public function isReadOnlyMode(): bool {
      return $this->readOnly;
    }

    /**
     * Reports is write kind allowed.
     */
    public function isWriteKindAllowed(string $kind): bool {
      return $kind === self::WRITE_KIND_CONTENT && $this->contentWrite;
    }

  }

  /**
   * Provides the native mcp tool call context dependency double.
   */
  final class McpToolCallContext {
    /**
     * Number of tool-call context entries observed.
     *
     * @var int
     */
    public int $enters = 0;
    /**
     * Number of tool-call context exits observed.
     *
     * @var int
     */
    public int $leaves = 0;

    /**
     * Exercises enter.
     */
    public function enter(): void {
      $this->enters++;
    }

    /**
     * Exercises leave.
     */
    public function leave(): void {
      $this->leaves++;
    }

  }
}

namespace Drupal\tool {

  /**
   * Provides the native executable result dependency double.
   */
  final class ExecutableResult {

    public function __construct(public bool $ok, public $message, public array $data = []) {}

    /**
     * Exercises success.
     */
    public static function success($message, array $data): self {
      return new self(TRUE, $message, $data);
    }

    /**
     * Exercises failure.
     */
    public static function failure($message): self {
      return new self(FALSE, $message);
    }

  }
}

namespace Drupal\tool\Tool {

  /**
   * Provides the native tool operation dependency double.
   */
  enum ToolOperation {
    case Read;
    case Write;
    case Unknown;
  }

  /**
   * Provides the native tool definition dependency double.
   */
  final class ToolDefinition {

    public function __construct(private ToolOperation $operation) {}

    /**
     * Reports get operation.
     */
    public function getOperation(): ToolOperation {
      return $this->operation;
    }

  }

  /**
   * Provides the native tool base dependency double.
   */
  abstract class ToolBase {
    /**
     * Native fixture current user.
     *
     * @var mixed
     */
    protected $currentUser;
    /**
     * Native fixture definition.
     *
     * @var mixed
     */
    protected $definition;

    /**
     * Reports get plugin definition.
     */
    public function getPluginDefinition() {
      return $this->definition;
    }

  }
}

namespace {
  require_once dirname(__DIR__) . '/src/ProtectedDraft/ProtectedDraftRefusal.php';
  require_once dirname(__DIR__) . '/src/Tool/ProtectedDraftToolBase.php';
  use Drupal\cinatra\Tool\ProtectedDraftToolBase;
  use Drupal\Core\Session\AccountInterface;
  use Drupal\mcp_tools\Service\AccessManager;
  use Drupal\mcp_tools\Service\McpToolCallContext;
  use Drupal\tool\ExecutableResult;
  use Drupal\tool\Tool\ToolDefinition;
  use Drupal\tool\Tool\ToolOperation;

  /**
   * Provides the native cinatra policy account dependency double.
   */
  final class CinatraPolicyAccount implements AccountInterface {

    public function __construct(private bool $permitted) {}

    /**
     * Reports has permission.
     */
    public function hasPermission(string $permission): bool {
      return $this->permitted && $permission === 'mcp_tools use content';
    }

  }

  /**
   * Provides the native cinatra native policy tool dependency double.
   */
  final class CinatraNativePolicyTool extends ProtectedDraftToolBase {
    /**
     * Number of protected operations executed by the native double.
     *
     * @var int
     */
    public int $operations = 0;

    public function __construct($definition, ?AccessManager $manager, public McpToolCallContext $context, bool $permission = TRUE, private bool $fail = FALSE) {
      $this->definition = $definition;
      $this->mcpAccess = $manager;
      $this->mcpContext = $context;
      $this->currentUser = new CinatraPolicyAccount($permission);
    }

    /**
     * Exercises execute native.
     */
    public function executeNative(): ExecutableResult {
      return $this->doExecute([]);
    }

    /**
     * Runs the requested protected revision operation.
     */
    protected function runProtectedOperation(array $values): array {
      $this->operations++;
      if ($this->fail) {
        throw new RuntimeException('private database credential must not leak');
      }
      return ['revision_id' => 41];
    }

  }
  $cases = [];
  $denials = [
    'missing MCP integration' => [new ToolDefinition(ToolOperation::Write), NULL, TRUE],
    'missing READ on writer' => [new ToolDefinition(ToolOperation::Write), new AccessManager(['write']), TRUE],
    'missing WRITE on writer' => [new ToolDefinition(ToolOperation::Write), new AccessManager(['read']), TRUE],
    'read-only site' => [new ToolDefinition(ToolOperation::Write), new AccessManager(['read', 'write'], TRUE), TRUE],
    'content kind disabled' => [
      new ToolDefinition(ToolOperation::Write),
      new AccessManager([
        'read',
        'write',
      ], FALSE, FALSE),
      TRUE,
    ],
    'account category denied' => [new ToolDefinition(ToolOperation::Write), new AccessManager(), FALSE],
    'missing READ on reader' => [new ToolDefinition(ToolOperation::Read), new AccessManager(['write']), TRUE],
    'unknown operation metadata' => [new ToolDefinition(ToolOperation::Unknown), new AccessManager(), TRUE],
    'missing definition metadata' => [NULL, new AccessManager(), TRUE],
  ];
  foreach ($denials as $name => [$definition, $manager, $permission]) {
    $cases[$name] = static function () use ($definition, $manager, $permission): void {
      $context = new McpToolCallContext();
      $tool = new CinatraNativePolicyTool($definition, $manager, $context, $permission);
      if ($tool->access() || $tool->executeNative()->ok || $tool->operations !== 0 || $context->enters !== 0) {
        throw new RuntimeException('Denied wrapper reached content operation or context.');
      }
    };
  }
  $cases['READ works without WRITE in read-only mode'] = static function (): void {
    $context = new McpToolCallContext();
    $tool = new CinatraNativePolicyTool(new ToolDefinition(ToolOperation::Read), new AccessManager([
      'read',
    ], TRUE, FALSE), $context);
    $result = $tool->executeNative();
    if (!$result->ok || $tool->operations !== 1 || $context->enters !== 1 || $context->leaves !== 1 || $result->data['contract'] !== ProtectedDraftToolBase::CONTRACT) {
      throw new RuntimeException('Read policy or balanced context failed.');
    }
  };
  $cases['authorized WRITE keeps both scopes and balanced context'] = static function (): void {
    $context = new McpToolCallContext();
    $tool = new CinatraNativePolicyTool(new ToolDefinition(ToolOperation::Write), new AccessManager(), $context);
    if (!$tool->executeNative()->ok || $tool->operations !== 1 || $context->leaves !== 1) {
      throw new RuntimeException('Write policy failed.');
    }
  };
  $cases['provider error is private and context leaves'] = static function (): void {
    $context = new McpToolCallContext();
    $tool = new CinatraNativePolicyTool(new ToolDefinition(ToolOperation::Write), new AccessManager(), $context, TRUE, TRUE);
    $result = $tool->executeNative();
    if ($result->ok || str_contains($result->message->message, 'credential') || $context->leaves !== 1) {
      throw new RuntimeException('Provider error leaked or context remained active.');
    }
  };
  $failures = 0;
  foreach ($cases as $name => $case) {
    try {
      $case();
      echo "PASS: $name\n";
    }
    catch (Throwable $e) {
      $failures++;
      fwrite(STDERR, "FAIL: $name: {$e->getMessage()}\n");
    }
  }
  echo count($cases) . " cases; $failures failures; 0 skipped (dependency doubles, not MCP runtime)\n";
  exit($failures ? 1 : 0);
}
