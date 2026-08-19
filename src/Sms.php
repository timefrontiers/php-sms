<?php

declare(strict_types=1);

namespace TimeFrontiers\Sms;

use TimeFrontiers\AccessRank;
use TimeFrontiers\Helper\DatabaseObject;
use TimeFrontiers\Helper\Pagination;
use TimeFrontiers\SQLDatabase;
use TimeFrontiers\Sms\Config\SmsConfig;
use TimeFrontiers\Sms\Dto\WebhookRequest;
use TimeFrontiers\Sms\Money\Decimal;
use TimeFrontiers\Sms\Result\DeliveryReportResult;
use TimeFrontiers\Sms\Result\SendResult;
use TimeFrontiers\Sms\Service\SmsService;

/**
 * Persisted SMS entity and backwards-compatible static facade.
 *
 * New applications should prefer sendResult() and deliveryReportResult(), which
 * retain the entity and a stable outcome when an operation is ambiguous.
 */
class Sms
{
  use Pagination;
  use DatabaseObject;

  public const STATUS_PENDING = 'pending';
  public const STATUS_DISPATCHING = 'dispatching';
  public const STATUS_QUEUED = 'queued';
  public const STATUS_SENT = 'sent';
  public const STATUS_FAILED = 'failed';
  public const STATUS_UNKNOWN = 'unknown';
  public const STATUS_DELIVERED = 'delivered';

  public const DIRECTION_OUTBOUND = 'outbound';
  public const DIRECTION_INBOUND = 'inbound';

  public const CODE_PREFIX = '828';
  public const CODE_LENGTH = 15;

  protected static string $_db_name;
  protected static string $_table_name = 'sms';
  protected static string $_primary_key = 'id';
  /** @var list<string> */
  protected static array $_db_fields = [
    'id', 'code', 'status', 'provider', 'idempotency_scope', 'idempotency_key_hash', 'version',
    'message_id', 'direction', 'user', 'batch', 'sender', 'receiver', 'message',
    '_message_pages', 'message_encoding', 'fees_currency', 'fees', 'reference', 'meta',
    'last_error_code', 'dispatch_started_at', 'provider_accepted_at', 'reconciled_at',
    'content_expires_at', 'content_redacted_at', '_created', '_updated',
  ];

  protected ?int $id = null;
  protected ?string $code = null;
  protected string $status = self::STATUS_PENDING;
  protected string $provider = '';
  protected string $idempotency_scope = '';
  protected string $idempotency_key_hash = '';
  protected int $version = 0;
  protected ?int $message_id = null;
  protected string $direction = self::DIRECTION_OUTBOUND;
  protected string $user = 'SYSTEM';
  protected ?string $batch = null;
  protected string $sender = '';
  protected string $receiver = '';
  protected string $message = '';
  protected int $_message_pages = 1;
  protected string $message_encoding = 'GSM-7';
  protected ?string $fees_currency = null;
  protected string $fees = '0.00000000';
  protected ?string $reference = null;
  protected ?string $meta = null;
  protected ?string $last_error_code = null;
  protected ?string $dispatch_started_at = null;
  protected ?string $provider_accepted_at = null;
  protected ?string $reconciled_at = null;
  protected ?string $content_expires_at = null;
  protected ?string $content_redacted_at = null;
  protected string $_created = '';
  protected string $_updated = '';

  private static ?SmsConfig $config = null;
  private static ?SmsService $service = null;

  /** @var array<string, list<array{int, int, string, string, int}>> */
  private static array $_last_errors = [];

  final public function __construct()
  {
  }

  /**
   * Configure once during application bootstrap. The resolved connection and
   * options are frozen for the lifetime of this process.
   *
   * @param array<string, mixed> $options
   */
  public static function configure(array $options, ?SQLDatabase $conn = null): void
  {
    if (self::$config !== null) {
      throw new \LogicException('Sms::configure() may only be called once per process.');
    }

    $config = SmsConfig::fromArray($options);
    if ($conn === null) {
      global $database;
      $conn = $database instanceof SQLDatabase ? $database : null;
    }
    if (!$conn instanceof SQLDatabase) {
      throw new \InvalidArgumentException('Sms::configure() requires an explicit SQLDatabase connection.');
    }

    static::$_db_name = $config->dbName;
    static::useConnection($conn);
    self::$config = $config;
    self::$service = new SmsService($conn, $config);
  }

  public static function service(): SmsService
  {
    return self::$service ?? throw new \LogicException('SMS is not configured. Call Sms::configure() during bootstrap.');
  }

  /** @param array<string, mixed> $data */
  public static function sendResult(array $data, ?string $driver = null): SendResult
  {
    self::$_last_errors = [];
    $result = self::service()->send($data, $driver);
    self::$_last_errors = self::compatibilityErrors(
      'send',
      $result->errors,
      $result->outcome === SendResult::VALIDATION_FAILED,
    );
    return $result;
  }

  /**
   * Compatibility wrapper. Unknown and rejected outcomes return false; inspect
   * lastErrors(), or use sendResult() to retain the associated entity.
   *
   * @param array<string, mixed> $data
   * @return static|false
   */
  public static function send(array $data, ?string $driver = null): static|false
  {
    $result = self::sendResult($data, $driver);
    return $result->succeeded() && $result->sms instanceof static ? $result->sms : false;
  }

  /** @param array<string, mixed> $data */
  public static function sendAndWait(array $data, ?string $driver = null): static|false
  {
    return self::send($data, $driver);
  }

  /** @param WebhookRequest|array<string, mixed> $request */
  public static function deliveryReportResult(string $driverName, WebhookRequest|array $request): DeliveryReportResult
  {
    self::$_last_errors = [];
    $result = self::service()->processDeliveryReport($driverName, $request);
    self::$_last_errors = self::compatibilityErrors('delivery_report', $result->errors);
    return $result;
  }

  /**
   * Compatibility wrapper. Passing only an array is intentionally fail-closed
   * unless the configured application verifier authenticates that context.
   */
  /** @param WebhookRequest|array<string, mixed> $payload */
  public static function processDeliveryReport(string $driverName, WebhookRequest|array $payload): ?static
  {
    $result = self::deliveryReportResult($driverName, $payload);
    return $result->sms instanceof static ? $result->sms : null;
  }

  public static function findByCode(string $code): ?static
  {
    self::$_last_errors = [];
    if (!\preg_match('/^828[0-9]{8,12}$/D', $code)) {
      return null;
    }
    $connection = self::service()->connection();
    $row = $connection->fetchOne(
      'SELECT * FROM `' . static::$_db_name . '`.`sms` WHERE `code` = ? LIMIT 1',
      [$code],
    );
    if ($row === false) {
      if ($connection->lastErrorCode() !== null || $connection->lastSqlState() !== null) self::setLookupFailure('find_by_code');
      return null;
    }
    return static::hydrate($row, $connection);
  }

  /**
   * The provider argument is additive. Without it, an ambiguous cross-provider
   * reference returns null rather than selecting an arbitrary row.
   */
  public static function findByReference(string $reference, ?string $provider = null): ?static
  {
    self::$_last_errors = [];
    if ($reference === '' || \strlen($reference) > 128) {
      return null;
    }
    if ($provider !== null) {
      return static::findByProviderReference($provider, $reference);
    }

    $connection = self::service()->connection();
    $rows = $connection->fetchAll(
      'SELECT * FROM `' . static::$_db_name . '`.`sms` WHERE `reference` = ? LIMIT 2',
      [$reference],
    );
    if ($rows === false) {
      if ($connection->lastErrorCode() !== null || $connection->lastSqlState() !== null) self::setLookupFailure('find_by_reference');
      return null;
    }
    return \count($rows) !== 1
      ? null
      : static::hydrate($rows[0], $connection);
  }

  public static function findByProviderReference(string $provider, string $reference): ?static
  {
    if (!\preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/D', $provider) || $reference === '' || \strlen($reference) > 128) {
      return null;
    }
    try {
      $sms = self::service()->findProviderReference($provider, $reference);
    } catch (\RuntimeException) {
      self::setLookupFailure('find_by_provider_reference');
      return null;
    }
    return $sms instanceof static ? $sms : null;
  }

  /**
   * @deprecated Use the versioned migration CLI. This compatibility helper now
   *             fails loudly on any SQL ambiguity.
   */
  public static function populateMissingCodes(SQLDatabase $conn): void
  {
    $rows = $conn->fetchAll('SELECT `id` FROM `' . static::$_db_name . '`.`sms` WHERE `code` IS NULL ORDER BY `id`');
    if ($rows === false) {
      throw new \RuntimeException('Could not read SMS rows requiring public codes.');
    }

    foreach ($rows as $row) {
      $updated = false;
      for ($attempt = 0; $attempt < 5; ++$attempt) {
        $code = self::CODE_PREFIX . \str_pad((string) \random_int(0, 999999999999), 12, '0', STR_PAD_LEFT);
        $result = $conn->execute(
          'UPDATE `' . static::$_db_name . '`.`sms` SET `code` = ? WHERE `id` = ? AND `code` IS NULL',
          [$code, $row['id']],
        );
        if ($result !== false && $conn->affectedRows() === 1) {
          $updated = true;
          break;
        }
        if ((string) ($conn->lastErrorCode() ?? '') !== '1062' && $conn->lastSqlState() !== '23000') {
          break;
        }
      }
      if (!$updated) {
        throw new \RuntimeException('Could not safely assign every missing SMS public code.');
      }
    }
  }

  /** @return array<string, list<array{int, int, string, string, int}>> */
  public static function lastErrors(): array
  {
    return self::$_last_errors;
  }

  /** @param array<string, mixed> $row */
  public static function hydrate(array $row, SQLDatabase $conn): static
  {
    $instance = new static();
    $instance->setConnection($conn);

    foreach (static::$_db_fields as $field) {
      if (!\array_key_exists($field, $row)) {
        continue;
      }
      $value = $row[$field];
      $instance->$field = match ($field) {
        'id', 'message_id' => $value === null ? null : (int) $value,
        'version', '_message_pages' => (int) $value,
        'fees' => self::normalizeDecimal($value),
        'meta' => $value === null ? null : (string) $value,
        default => $value === null ? null : (string) $value,
      };
    }

    return $instance;
  }

  /** @param array<string, mixed> $row */
  public static function _instantiateFromRow(array $row, ?SQLDatabase $conn = null): static
  {
    return static::hydrate($row, $conn ?? self::service()->connection());
  }

  /**
   * SMS state changes require version predicates and therefore go through
   * SmsService. Direct Active Record writes are deliberately rejected.
   */
  public function save(): bool
  {
    $this->_systemError('save', '[' . ErrorCode::PERSISTENCE_FAILED . '] Direct Sms persistence is disabled; use SmsService.', 828200);
    return false;
  }

  public function id(): ?int { return $this->id; }
  public function code(): ?string { return $this->code; }
  public function status(): string { return $this->status; }
  public function provider(): string { return $this->provider; }
  public function version(): int { return $this->version; }
  public function messageId(): ?int { return $this->message_id; }
  public function direction(): string { return $this->direction; }
  public function user(): string { return $this->user; }
  public function batch(): ?string { return $this->batch; }
  public function sender(): string { return $this->sender; }
  public function receiver(): string { return $this->receiver; }
  public function message(): string { return $this->message; }
  public function messagePages(): int { return $this->_message_pages; }
  public function messageEncoding(): string { return $this->message_encoding; }
  public function fees(): float { return (float) $this->fees; }
  public function feesExact(): string { return $this->fees; }
  public function feesCurrency(): ?string { return $this->fees_currency; }
  public function reference(): ?string { return $this->reference; }
  public function lastErrorCode(): ?string { return $this->last_error_code; }
  public function dispatchStartedAt(): ?string { return $this->dispatch_started_at; }
  public function providerAcceptedAt(): ?string { return $this->provider_accepted_at; }
  public function reconciledAt(): ?string { return $this->reconciled_at; }
  public function contentExpiresAt(): ?string { return $this->content_expires_at; }
  public function contentRedactedAt(): ?string { return $this->content_redacted_at; }

  /** @return array<array-key, mixed>|null */
  public function meta(): ?array
  {
    if ($this->meta === null || $this->meta === '') {
      return null;
    }
    try {
      $decoded = \json_decode($this->meta, true, flags: JSON_THROW_ON_ERROR);
      if (!\is_array($decoded)) {
        throw new \JsonException('SMS metadata must decode to an object or array.');
      }
      return $decoded;
    } catch (\JsonException) {
      $this->_systemError('meta', '[' . ErrorCode::CORRUPT_METADATA . '] Stored SMS metadata is corrupt.', 828206);
      return null;
    }
  }

  /**
   * @param array<string, list<string>> $errors
   * @return array<string, list<array{int, int, string, string, int}>>
   */
  private static function compatibilityErrors(string $context, array $errors, bool $validationFailure = false): array
  {
    $result = [];
    foreach ($errors as $code => $messages) {
      $rank = $validationFailure || $code === ErrorCode::VALIDATION_FAILED
        ? AccessRank::GUEST->value
        : AccessRank::DEVELOPER->value;
      foreach ($messages as $message) {
        $publicCode = $validationFailure ? ErrorCode::VALIDATION_FAILED : $code;
        $field = $validationFailure ? '[' . $code . '] ' : '';
        $result[$context][] = [$rank, self::numericErrorCode($publicCode), '[' . $publicCode . '] ' . $field . $message, '', 0];
      }
    }
    return $result;
  }

  private static function numericErrorCode(string $code): int
  {
    return match ($code) {
      ErrorCode::VALIDATION_FAILED => 828100,
      ErrorCode::CONFIGURATION_INVALID => 828101,
      ErrorCode::PERSISTENCE_FAILED => 828200,
      ErrorCode::IDEMPOTENCY_CONFLICT => 828207,
      ErrorCode::PROVIDER_REJECTED => 828201,
      ErrorCode::OUTCOME_UNKNOWN => 828202,
      ErrorCode::WEBHOOK_UNAUTHENTICATED => 828203,
      ErrorCode::WEBHOOK_INVALID => 828204,
      ErrorCode::WEBHOOK_PERSISTENCE_FAILED => 828205,
      ErrorCode::CORRUPT_METADATA => 828206,
      default => 828299,
    };
  }

  private static function setLookupFailure(string $context): void
  {
    self::$_last_errors[$context][] = [
      AccessRank::DEVELOPER->value,
      828200,
      '[' . ErrorCode::PERSISTENCE_FAILED . '] SMS lookup failed.',
      '',
      0,
    ];
  }

  private static function normalizeDecimal(mixed $value): string
  {
    if (!\is_string($value) && !\is_int($value) && !\is_float($value) && $value !== null) {
      return '0.00000000';
    }
    try {
      return Decimal::normalize($value);
    } catch (\InvalidArgumentException) {
      return '0.00000000';
    }
  }
}
