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

  use Symfony\Component\DependencyInjection\ContainerInterface;

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

    /**
     * Creates the plugin without its constructor, like a container factory.
     */
    public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
      $instance = (new \ReflectionClass(static::class))->newInstanceWithoutConstructor();
      $instance->definition = $plugin_definition;
      return $instance;
    }

  }
}

namespace Symfony\Component\DependencyInjection {

  /**
   * Provides the service container dependency double.
   */
  interface ContainerInterface {

    /**
     * Reports whether a service is defined.
     */
    public function has(string $id): bool;

    /**
     * Returns a service.
     */
    public function get(string $id): ?object;

  }

}

namespace Drupal\cinatra\ProtectedDraft {

  /**
   * Provides the protected draft service dependency double.
   */
  final class ProtectedDraftService {}

}

namespace Psr\Log {

  /**
   * Provides the PSR-3 logger interface dependency double.
   */
  interface LoggerInterface {

    /**
     * Records an emergency.
     */
    public function emergency(string|\Stringable $message, array $context = []): void;

    /**
     * Records an alert.
     */
    public function alert(string|\Stringable $message, array $context = []): void;

    /**
     * Records a critical condition.
     */
    public function critical(string|\Stringable $message, array $context = []): void;

    /**
     * Records an error.
     */
    public function error(string|\Stringable $message, array $context = []): void;

    /**
     * Records a warning.
     */
    public function warning(string|\Stringable $message, array $context = []): void;

    /**
     * Records a notice.
     */
    public function notice(string|\Stringable $message, array $context = []): void;

    /**
     * Records an informational message.
     */
    public function info(string|\Stringable $message, array $context = []): void;

    /**
     * Records a debug message.
     */
    public function debug(string|\Stringable $message, array $context = []): void;

    /**
     * Records a message at any level.
     */
    public function log($level, string|\Stringable $message, array $context = []): void;

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
  use Drupal\cinatra\ProtectedDraft\ProtectedDraftService;
  use Psr\Log\LoggerInterface;
  use Symfony\Component\DependencyInjection\ContainerInterface;

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
   * Implements every PSR-3 level except error(), which the tool alone uses.
   */
  abstract class CinatraPolicyLoggerBase implements LoggerInterface {

    /**
     * Records an emergency.
     */
    public function emergency(string|\Stringable $message, array $context = []): void {
      $this->log('emergency', $message, $context);
    }

    /**
     * Records an alert.
     */
    public function alert(string|\Stringable $message, array $context = []): void {
      $this->log('alert', $message, $context);
    }

    /**
     * Records a critical condition.
     */
    public function critical(string|\Stringable $message, array $context = []): void {
      $this->log('critical', $message, $context);
    }

    /**
     * Records a warning.
     */
    public function warning(string|\Stringable $message, array $context = []): void {
      $this->log('warning', $message, $context);
    }

    /**
     * Records a notice.
     */
    public function notice(string|\Stringable $message, array $context = []): void {
      $this->log('notice', $message, $context);
    }

    /**
     * Records an informational message.
     */
    public function info(string|\Stringable $message, array $context = []): void {
      $this->log('info', $message, $context);
    }

    /**
     * Records a debug message.
     */
    public function debug(string|\Stringable $message, array $context = []): void {
      $this->log('debug', $message, $context);
    }

    /**
     * Refuses every level the tool does not use.
     */
    public function log($level, string|\Stringable $message, array $context = []): void {
      throw new LogicException('The protected draft tool logged at an unexpected level.');
    }

  }

  /**
   * Records logger calls of the native policy tool.
   */
  final class CinatraPolicyLogger extends CinatraPolicyLoggerBase {

    /**
     * Recorded calls, each a message and its context.
     *
     * @var array
     */
    public array $records = [];

    /**
     * Records an error.
     */
    public function error(string|\Stringable $message, array $context = []): void {
      $this->records[] = [(string) $message, $context];
    }

  }

  /**
   * Provides a service container with fixed services.
   */
  final class CinatraPolicyContainer implements ContainerInterface {

    public function __construct(private array $services) {}

    /**
     * Reports whether a service is defined.
     */
    public function has(string $id): bool {
      return array_key_exists($id, $this->services);
    }

    /**
     * Returns a service.
     */
    public function get(string $id): ?object {
      if (!array_key_exists($id, $this->services)) {
        throw new LogicException('Unknown service requested.');
      }
      return $this->services[$id];
    }

  }

  /**
   * Provides a logger factory whose channel is fixed or whose get() throws.
   */
  final class CinatraPolicyLoggerFactory {

    /**
     * Channel names requested from this factory.
     *
     * @var string[]
     */
    public array $requested = [];

    public function __construct(private mixed $channel, private bool $throws = FALSE) {}

    /**
     * Returns the channel or throws like a broken factory.
     */
    public function get(string $channel): mixed {
      $this->requested[] = $channel;
      if ($this->throws) {
        throw new RuntimeException('logger factory down');
      }
      return $this->channel;
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

    public function __construct($definition, ?AccessManager $manager, public McpToolCallContext $context, bool $permission = TRUE, private bool $fail = FALSE, ?LoggerInterface $logger = NULL, private ?\Throwable $failure = NULL) {
      $this->protectedDraftLogger = $logger;
      $this->definition = $definition;
      $this->mcpAccess = $manager;
      $this->mcpContext = $context;
      $this->currentUser = new CinatraPolicyAccount($permission);
    }

    /**
     * Reports the log channel that create() acquired.
     */
    public function acquiredLogger(): ?LoggerInterface {
      return $this->protectedDraftLogger;
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
      if ($this->failure !== NULL) {
        throw $this->failure;
      }
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
  $cases['provider error is logged by class and location only'] = static function (): void {
    $logger = new CinatraPolicyLogger();
    $tool = new CinatraNativePolicyTool(new ToolDefinition(ToolOperation::Read), new AccessManager(), new McpToolCallContext(), TRUE, TRUE, $logger);
    $result = $tool->executeNative();
    if ($result->ok || count($logger->records) !== 1) {
      throw new RuntimeException('A provider error was not logged exactly once.');
    }
    [$message, $context] = $logger->records[0];
    $logged = $message . ' ' . json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    if (($context['@class'] ?? NULL) !== RuntimeException::class
      || ($context['@file'] ?? NULL) !== basename(__FILE__)
      || !is_int($context['@line'] ?? NULL)
      || str_contains($logged, 'credential')
      || str_contains($logged, dirname(__FILE__))) {
      throw new RuntimeException('The provider error log line is incomplete or leaks the message or a path.');
    }
  };
  $cases['an anonymous exception class is logged without its defining path'] = static function (): void {
    $logger = new CinatraPolicyLogger();
    $failure = new class('private database credential must not leak') extends RuntimeException {};
    if (!str_contains($failure::class, __FILE__)) {
      throw new RuntimeException('The runtime anonymous class name no longer carries its defining path; this case is vacuous.');
    }
    $tool = new CinatraNativePolicyTool(new ToolDefinition(ToolOperation::Read), new AccessManager(), new McpToolCallContext(), TRUE, FALSE, $logger, $failure);
    $result = $tool->executeNative();
    if ($result->ok || count($logger->records) !== 1) {
      throw new RuntimeException('An anonymous provider error was not logged exactly once.');
    }
    [$message, $context] = $logger->records[0];
    $logged = $message . ' ' . json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    if (($context['@class'] ?? NULL) !== 'RuntimeException@anonymous'
      || ($context['@file'] ?? NULL) !== basename(__FILE__)
      || str_contains($logged, 'credential')
      || str_contains($logged, dirname(__FILE__))
      || str_contains($logged, '\\u0000')) {
      throw new RuntimeException('The anonymous provider error log line leaks its defining path or the message.');
    }
  };
  $cases['a failing logger keeps the private failure result'] = static function (): void {
    $logger = new class() extends CinatraPolicyLoggerBase {

      /**
       * Fails like a broken log channel.
       */
      public function error(string|\Stringable $message, array $context = []): void {
        throw new RuntimeException('log channel down');
      }

    };
    $tool = new CinatraNativePolicyTool(new ToolDefinition(ToolOperation::Read), new AccessManager(), new McpToolCallContext(), TRUE, TRUE, $logger);
    $result = $tool->executeNative();
    if ($result->ok || str_contains($result->message->message, 'credential')) {
      throw new RuntimeException('A failing logger changed the failure result.');
    }
  };
  $create_tool = static function (array $services): CinatraNativePolicyTool {
    $services['cinatra.protected_draft'] = new ProtectedDraftService();
    $tool = CinatraNativePolicyTool::create(new CinatraPolicyContainer($services), [], 'cinatra_policy', new ToolDefinition(ToolOperation::Read));
    if (!$tool instanceof CinatraNativePolicyTool) {
      throw new RuntimeException('create() did not return the tool.');
    }
    return $tool;
  };
  $cases['create without a logger factory leaves the logger unset'] = static function () use ($create_tool): void {
    if ($create_tool([])->acquiredLogger() !== NULL) {
      throw new RuntimeException('A logger was set without a logger factory.');
    }
  };
  $cases['create survives a logger factory that throws'] = static function () use ($create_tool): void {
    $tool = $create_tool(['logger.factory' => new CinatraPolicyLoggerFactory(NULL, TRUE)]);
    if ($tool->acquiredLogger() !== NULL) {
      throw new RuntimeException('A logger was set although the logger factory threw.');
    }
  };
  $cases['create ignores a logger channel that is not a PSR logger'] = static function () use ($create_tool): void {
    $tool = $create_tool(['logger.factory' => new CinatraPolicyLoggerFactory(new stdClass())]);
    if ($tool->acquiredLogger() !== NULL) {
      throw new RuntimeException('A non-PSR logger channel was set.');
    }
  };
  $cases['create ignores a logger factory that is not an object'] = static function () use ($create_tool): void {
    $tool = $create_tool(['logger.factory' => NULL]);
    if ($tool->acquiredLogger() !== NULL) {
      throw new RuntimeException('A logger was set from a missing logger factory object.');
    }
  };
  $cases['create sets the PSR logger of the cinatra channel'] = static function () use ($create_tool): void {
    $logger = new CinatraPolicyLogger();
    $factory = new CinatraPolicyLoggerFactory($logger);
    $tool = $create_tool(['logger.factory' => $factory]);
    if ($tool->acquiredLogger() !== $logger || $factory->requested !== ['cinatra']) {
      throw new RuntimeException('The PSR logger of the logger factory was not set.');
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
