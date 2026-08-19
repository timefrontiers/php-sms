<?php

declare(strict_types=1);

namespace TimeFrontiers\Sms\Config;

final readonly class SmsConfig
{
  /**
   * @param array<string, string> $continentMapping
   * @param array<string, array<string, mixed>|object> $drivers
   */
  private function __construct(
    public string $dbName,
    public string $defaultDriver,
    public ?string $defaultSender,
    public string $regionStrategy,
    public array $continentMapping,
    private array $drivers,
    public string $idempotencyScope,
    public int $maxSegments,
    public int $staleDispatchSeconds,
    public ?string $defaultRegion,
    public ?int $contentRetentionDays,
    private mixed $logger,
  ) {
  }

  /** @param array<string, mixed> $options */
  public static function fromArray(array $options): self
  {
    $dbName = self::requiredString($options, 'db_name', 64);
    if (!\preg_match('/^[A-Za-z0-9_]+$/D', $dbName)) {
      throw new \InvalidArgumentException('SMS db_name must contain only letters, digits, and underscores.');
    }

    $drivers = $options['drivers'] ?? null;
    if (!\is_array($drivers) || $drivers === []) {
      throw new \InvalidArgumentException('At least one SMS driver must be configured.');
    }

    foreach ($drivers as $name => $driver) {
      if (!\is_string($name) || !\preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/D', $name)) {
        throw new \InvalidArgumentException('SMS driver names must be bounded lowercase identifiers.');
      }
      if (!\is_array($driver) && !\is_object($driver)) {
        throw new \InvalidArgumentException("SMS driver '{$name}' must be an options array or driver instance.");
      }
      if (\is_array($driver)) {
        foreach (['default_sender', 'sender_id', 'sender_phone'] as $senderKey) {
          $sender = $driver[$senderKey] ?? null;
          if ($sender !== null && (!\is_string($sender) || $sender === '' || \mb_strlen($sender, 'UTF-8') > 16)) {
            throw new \InvalidArgumentException("SMS driver '{$name}' has an invalid {$senderKey}.");
          }
        }
        $allowed = $driver['allowed_senders'] ?? [];
        if (!\is_array($allowed)) {
          throw new \InvalidArgumentException("SMS driver '{$name}' allowed_senders must be an array.");
        }
        foreach ($allowed as $sender) {
          if (!\is_string($sender) || $sender === '' || \mb_strlen($sender, 'UTF-8') > 16) {
            throw new \InvalidArgumentException("SMS driver '{$name}' contains an invalid allowed sender.");
          }
        }
      }
    }

    $defaultDriver = self::optionalString($options, 'default_driver', 32) ?? 'twilio';
    if (!\array_key_exists($defaultDriver, $drivers)) {
      throw new \InvalidArgumentException('The default SMS driver is not configured.');
    }

    $regionStrategy = self::optionalString($options, 'region_strategy', 32) ?? 'auto';
    if ($regionStrategy !== 'auto' && !\array_key_exists($regionStrategy, $drivers)) {
      throw new \InvalidArgumentException('SMS region_strategy must be auto or a configured driver.');
    }

    $mapping = $options['continent_mapping'] ?? (isset($drivers['africastalking']) ? ['Africa' => 'africastalking'] : []);
    if (!\is_array($mapping)) {
      throw new \InvalidArgumentException('SMS continent_mapping must be an array.');
    }
    foreach ($mapping as $continent => $driverName) {
      if (!\is_string($continent) || $continent === '' || !\is_string($driverName) || !isset($drivers[$driverName])) {
        throw new \InvalidArgumentException('Every SMS continent mapping must target a configured driver.');
      }
    }

    $maxSegments = $options['max_segments'] ?? 5;
    if (!\is_int($maxSegments) || $maxSegments < 1 || $maxSegments > 20) {
      throw new \InvalidArgumentException('SMS max_segments must be an integer between 1 and 20.');
    }

    $staleSeconds = $options['stale_dispatch_seconds'] ?? 300;
    if (!\is_int($staleSeconds) || $staleSeconds < 30 || $staleSeconds > 86400) {
      throw new \InvalidArgumentException('SMS stale_dispatch_seconds must be between 30 and 86400.');
    }

    $scope = self::optionalString($options, 'idempotency_scope', 64) ?? 'default';
    if (!\preg_match('/^[A-Za-z0-9_.:-]+$/D', $scope)) {
      throw new \InvalidArgumentException('SMS idempotency_scope contains unsupported characters.');
    }

    $defaultRegion = self::optionalString($options, 'default_region', 2);
    if ($defaultRegion !== null && !\preg_match('/^[A-Za-z]{2}$/D', $defaultRegion)) {
      throw new \InvalidArgumentException('SMS default_region must be an ISO alpha-2 code.');
    }

    $retentionDays = $options['content_retention_days'] ?? null;
    if ($retentionDays !== null && (!\is_int($retentionDays) || $retentionDays < 1 || $retentionDays > 3650)) {
      throw new \InvalidArgumentException('SMS content_retention_days must be null or an integer between 1 and 3650.');
    }

    $logger = $options['logger'] ?? null;
    if ($logger !== null && !\is_callable($logger)) {
      throw new \InvalidArgumentException('SMS logger must be callable.');
    }

    return new self(
      $dbName,
      $defaultDriver,
      self::optionalString($options, 'default_sender', 16),
      $regionStrategy,
      $mapping,
      $drivers,
      $scope,
      $maxSegments,
      $staleSeconds,
      $defaultRegion === null ? null : \strtoupper($defaultRegion),
      $retentionDays,
      $logger,
    );
  }

  public function hasDriver(string $name): bool
  {
    return \array_key_exists($name, $this->drivers);
  }

  /** @return array<string, mixed>|object */
  public function driver(string $name): array|object
  {
    if (!$this->hasDriver($name)) {
      throw new \InvalidArgumentException('Unknown SMS driver.');
    }

    return $this->drivers[$name];
  }

  /** @return list<string> */
  public function driverNames(): array
  {
    return \array_keys($this->drivers);
  }

  /**
   * Resolve an authorized sender. Configured driver senders are implicitly
   * trusted; request-selected senders must be explicitly allowlisted.
   *
   * @param array<string, mixed> $driverConfig
   */
  public function resolveSender(array $driverConfig, ?string $requested): string
  {
    $allowed = $driverConfig['allowed_senders'] ?? [];
    if (!\is_array($allowed)) {
      throw new \InvalidArgumentException('Driver allowed_senders must be an array.');
    }
    $allowed = \array_values(\array_filter($allowed, static fn(mixed $value): bool => \is_string($value) && $value !== ''));

    if ($requested !== null && $requested !== '') {
      if (!\in_array($requested, $allowed, true)) {
        throw new \InvalidArgumentException('The requested SMS sender is not authorized for this driver.');
      }
      return $requested;
    }

    foreach (['default_sender', 'sender_id', 'sender_phone'] as $key) {
      $candidate = $driverConfig[$key] ?? null;
      if (\is_string($candidate) && $candidate !== '') {
        return $candidate;
      }
    }

    if ($this->defaultSender !== null && \in_array($this->defaultSender, $allowed, true)) {
      return $this->defaultSender;
    }

    throw new \InvalidArgumentException('No authorized SMS sender is configured for this driver.');
  }

  /** @param array<string, scalar|null> $context */
  public function log(string $code, \Throwable $exception, array $context = []): void
  {
    if ($this->logger === null) {
      return;
    }
    ($this->logger)($code, $exception, $context);
  }

  /** @param array<string, mixed> $options */
  private static function requiredString(array $options, string $key, int $max): string
  {
    $value = self::optionalString($options, $key, $max);
    if ($value === null) {
      throw new \InvalidArgumentException("SMS {$key} is required.");
    }
    return $value;
  }

  /** @param array<string, mixed> $options */
  private static function optionalString(array $options, string $key, int $max): ?string
  {
    $value = $options[$key] ?? null;
    if ($value === null) {
      return null;
    }
    if (!\is_string($value) || $value === '' || \mb_strlen($value, 'UTF-8') > $max) {
      throw new \InvalidArgumentException("SMS {$key} must be a non-empty string no longer than {$max} characters.");
    }
    return $value;
  }
}
