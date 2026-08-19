<?php

declare(strict_types=1);

namespace TimeFrontiers\Sms\Service;

use TimeFrontiers\Phone;
use TimeFrontiers\SQLDatabase;
use TimeFrontiers\Sms\Config\SmsConfig;
use TimeFrontiers\Sms\Driver\AfricasTalkingDriver;
use TimeFrontiers\Sms\Driver\SmsDriverInterface;
use TimeFrontiers\Sms\Driver\SmsStatusLookupInterface;
use TimeFrontiers\Sms\Driver\TwilioDriver;
use TimeFrontiers\Sms\Dto\ParsedDeliveryReport;
use TimeFrontiers\Sms\Dto\ProviderSendResult;
use TimeFrontiers\Sms\Dto\SendRequest;
use TimeFrontiers\Sms\Dto\WebhookRequest;
use TimeFrontiers\Sms\ErrorCode;
use TimeFrontiers\Sms\Exception\ProviderConfigurationException;
use TimeFrontiers\Sms\Exception\ProviderOutcomeUnknownException;
use TimeFrontiers\Sms\Exception\ProviderRejectedException;
use TimeFrontiers\Sms\Exception\SendValidationException;
use TimeFrontiers\Sms\Result\DeliveryReportResult;
use TimeFrontiers\Sms\Result\BackfillReport;
use TimeFrontiers\Sms\Result\PurgeReport;
use TimeFrontiers\Sms\Result\RecoveryReport;
use TimeFrontiers\Sms\Result\SendResult;
use TimeFrontiers\Sms\Sms;

final class SmsService
{
  /** @var array<string, SmsDriverInterface> */
  private array $drivers = [];

  public function __construct(
    private readonly SQLDatabase $connection,
    private readonly SmsConfig $config,
  ) {
    foreach ($config->driverNames() as $provider) {
      $this->driver($provider);
      $this->resolveSender($provider, null);
    }
  }

  public function connection(): SQLDatabase
  {
    return $this->connection;
  }

  public function config(): SmsConfig
  {
    return $this->config;
  }

  /** @param array<string, mixed> $data */
  public function send(array $data, ?string $driverOverride = null): SendResult
  {
    if ($this->connection->inTransaction()) {
      return $this->errorResult(
        SendResult::REJECTED,
        ErrorCode::CONFIGURATION_INVALID,
        'Sms::send() performs provider I/O and must not run inside a caller-owned transaction.',
      );
    }

    try {
      $request = SendRequest::fromArray($data, $this->config);
    } catch (SendValidationException $exception) {
      return new SendResult(SendResult::VALIDATION_FAILED, errors: $exception->errors());
    } catch (\Throwable $exception) {
      $this->config->log(ErrorCode::VALIDATION_FAILED, $exception);
      return $this->errorResult(SendResult::VALIDATION_FAILED, ErrorCode::VALIDATION_FAILED, 'SMS input could not be validated.');
    }

    try {
      $provider = $this->resolveProvider($driverOverride ?? $request->requestedDriver, $request->receiver);
      $driver = $this->driver($provider);
      $sender = $this->resolveSender($provider, $request->requestedSender);
    } catch (\Throwable $exception) {
      $this->config->log(ErrorCode::CONFIGURATION_INVALID, $exception);
      return $this->errorResult(SendResult::REJECTED, ErrorCode::CONFIGURATION_INVALID, 'SMS provider configuration is invalid.');
    }

    $pending = $this->createOrReplay($request, $provider, $sender);
    if ($pending instanceof SendResult) {
      return $pending;
    }
    if ($pending['replayed']) {
      return $this->replayed($pending['sms']);
    }

    $sms = $pending['sms'];
    $claim = $this->claimForDispatch($sms);
    if ($claim === 'failed') {
      return $this->errorResult(SendResult::INFRASTRUCTURE_FAILED, ErrorCode::PERSISTENCE_FAILED, 'SMS dispatch could not be claimed because the database operation failed.', $sms);
    }
    if ($claim === 'lost') {
      $current = $this->findById($sms->id());
      if ($current instanceof Sms) {
        return new SendResult(SendResult::IN_FLIGHT, $current);
      }
      return $this->errorResult(SendResult::INFRASTRUCTURE_FAILED, ErrorCode::PERSISTENCE_FAILED, 'SMS dispatch could not be claimed safely.');
    }

    $sms = $this->findById($sms->id());
    if (!$sms instanceof Sms) {
      return $this->errorResult(SendResult::INFRASTRUCTURE_FAILED, ErrorCode::PERSISTENCE_FAILED, 'SMS dispatch state could not be reloaded.');
    }

    try {
      $providerResult = $driver->send($sms);
      $this->assertProviderResult($providerResult, $provider);
    } catch (ProviderRejectedException $exception) {
      $this->config->log($exception->providerCode, $exception, ['provider' => $provider]);
      return $this->finishFailure($sms, $exception->providerCode);
    } catch (ProviderOutcomeUnknownException $exception) {
      $this->config->log($exception->providerCode, $exception, ['provider' => $provider]);
      return $this->finishUnknown($sms, $exception->providerCode);
    } catch (ProviderConfigurationException $exception) {
      $this->config->log(ErrorCode::CONFIGURATION_INVALID, $exception, ['provider' => $provider]);
      return $this->finishFailure($sms, 'provider_configuration_invalid');
    } catch (\Throwable $exception) {
      $this->config->log(ErrorCode::OUTCOME_UNKNOWN, $exception, ['provider' => $provider]);
      return $this->finishUnknown($sms, 'provider_contract_unknown');
    }

    if (!$this->persistAccepted($sms, $providerResult)) {
      return $this->errorResult(
        SendResult::UNKNOWN,
        ErrorCode::OUTCOME_UNKNOWN,
        'The provider accepted the SMS, but its durable database outcome is ambiguous. Reconcile before retrying.',
        $sms,
      );
    }

    $accepted = $this->findById($sms->id());
    if (!$accepted instanceof Sms) {
      return $this->errorResult(
        SendResult::UNKNOWN,
        ErrorCode::OUTCOME_UNKNOWN,
        'The accepted SMS state was persisted but could not be reloaded. Reconcile before retrying.',
        $sms,
      );
    }

    return new SendResult(SendResult::ACCEPTED, $accepted);
  }

  /** @param WebhookRequest|array<string, mixed> $request */
  public function processDeliveryReport(string $driverName, WebhookRequest|array $request): DeliveryReportResult
  {
    try {
      $driver = $this->driver($driverName);
      if (\is_array($request)) {
        $driverConfig = $this->config->driver($driverName);
        $url = \is_array($driverConfig) && \is_string($driverConfig['webhook_url'] ?? null)
          ? $driverConfig['webhook_url']
          : 'https://invalid.local/legacy';
        $request = WebhookRequest::legacy($request, $url);
      }
    } catch (\Throwable $exception) {
      $this->config->log(ErrorCode::WEBHOOK_INVALID, $exception);
      return $this->deliveryError(DeliveryReportResult::INVALID, ErrorCode::WEBHOOK_INVALID, 'SMS webhook driver is invalid.');
    }

    if (!$driver->verifyDeliveryReport($request)) {
      return $this->deliveryError(DeliveryReportResult::INVALID, ErrorCode::WEBHOOK_UNAUTHENTICATED, 'SMS webhook authenticity could not be established.');
    }

    try {
      $report = $driver->parseDeliveryReport($request);
      if ($report->provider !== $driverName || $driver->getProviderName() !== $driverName) {
        throw new \InvalidArgumentException('Webhook provider identity does not match its route.');
      }
    } catch (\Throwable $exception) {
      $this->config->log(ErrorCode::WEBHOOK_INVALID, $exception, ['provider' => $driverName]);
      return $this->deliveryError(DeliveryReportResult::INVALID, ErrorCode::WEBHOOK_INVALID, 'SMS webhook payload is invalid.');
    }

    $lookup = $this->fetchOne(
      "SELECT * FROM {$this->table()} WHERE `provider` = ? AND `reference` = ? LIMIT 1",
      [$report->provider, $report->reference],
    );
    if ($lookup['failed']) {
      return $this->deliveryPersistenceError();
    }
    if ($lookup['row'] === null) {
      return new DeliveryReportResult(DeliveryReportResult::NOT_FOUND);
    }

    $sms = Sms::hydrate($lookup['row'], $this->connection);
    return $this->applyDeliveryReport($sms, $report, $request);
  }

  public function findProviderReference(string $provider, string $reference): ?Sms
  {
    $result = $this->fetchOne(
      "SELECT * FROM {$this->table()} WHERE `provider` = ? AND `reference` = ? LIMIT 1",
      [$provider, $reference],
    );
    if ($result['failed']) {
      throw new \RuntimeException('SMS provider reference lookup failed.');
    }
    return $result['row'] === null ? null : Sms::hydrate($result['row'], $this->connection);
  }

  public function driverForRecovery(string $provider): SmsDriverInterface
  {
    return $this->driver($provider);
  }

  public function recoverStale(bool $dryRun = true, int $limit = 100): RecoveryReport
  {
    if ($limit < 1 || $limit > 1000) {
      throw new \InvalidArgumentException('Recovery limit must be between 1 and 1000.');
    }

    $rows = $this->connection->fetchAll(
      "SELECT * FROM {$this->table()} WHERE (`status` = 'unknown' OR (`status` = 'dispatching' AND `dispatch_started_at` < CURRENT_TIMESTAMP(6) - INTERVAL ? SECOND)) ORDER BY `id` LIMIT {$limit}",
      [$this->config->staleDispatchSeconds],
    );
    if ($rows === false) {
      return new RecoveryReport($dryRun, [], ['Could not load stale SMS attempts.']);
    }

    $items = [];
    $errors = [];
    foreach ($rows as $row) {
      $sms = Sms::hydrate($row, $this->connection);
      $item = [
        'id' => (int) $sms->id(),
        'code' => (string) $sms->code(),
        'provider' => $sms->provider(),
        'status' => $sms->status(),
        'action' => 'manual_reconciliation_required',
      ];

      if ($sms->reference() === null) {
        $item['action'] = 'manual_missing_provider_reference';
        $items[] = $item;
        continue;
      }

      try {
        $driver = $this->driver($sms->provider());
      } catch (\Throwable $exception) {
        $this->config->log(ErrorCode::CONFIGURATION_INVALID, $exception, ['provider' => $sms->provider()]);
        $item['action'] = 'manual_provider_unavailable';
        $items[] = $item;
        continue;
      }

      if (!$driver instanceof SmsStatusLookupInterface) {
        $item['action'] = 'manual_status_lookup_unsupported';
        $items[] = $item;
        continue;
      }

      if ($dryRun) {
        $item['action'] = 'would_query_provider_status';
        $items[] = $item;
        continue;
      }

      try {
        $report = $driver->lookupStatus($sms->reference());
        if ($report === null) {
          $item['action'] = 'provider_still_unknown';
        } elseif ($report->provider !== $sms->provider() || $report->reference !== $sms->reference()) {
          $item['action'] = 'provider_lookup_identity_mismatch';
          $errors[] = "SMS {$sms->code()} received a mismatched provider lookup result.";
        } else {
          $request = new WebhookRequest(
            \json_encode(['recovery' => $sms->code(), 'status' => $report->status], JSON_THROW_ON_ERROR),
            ['content-type' => 'application/json'],
            'POST',
            'https://internal.invalid/sms-recovery',
            '127.0.0.1',
          );
          $outcome = $this->applyDeliveryReport($sms, $report, $request);
          $item['action'] = 'recovery_' . $outcome->outcome;
          if ($outcome->outcome === DeliveryReportResult::INFRASTRUCTURE_FAILED) {
            $errors[] = "SMS {$sms->code()} could not persist its recovery result.";
          }
        }
      } catch (\Throwable $exception) {
        $this->config->log(ErrorCode::OUTCOME_UNKNOWN, $exception, ['provider' => $sms->provider(), 'sms_id' => $sms->id()]);
        $item['action'] = 'provider_lookup_failed';
      }
      $items[] = $item;
    }

    return new RecoveryReport($dryRun, $items, $errors);
  }

  public function purgeExpiredContent(bool $dryRun = true, int $limit = 500): PurgeReport
  {
    if ($limit < 1 || $limit > 5000) {
      throw new \InvalidArgumentException('Purge limit must be between 1 and 5000.');
    }

    $rows = $this->connection->fetchAll(
      "SELECT `id`, `code`, `version` FROM {$this->table()} WHERE `content_expires_at` IS NOT NULL AND `content_expires_at` <= CURRENT_TIMESTAMP() AND `content_redacted_at` IS NULL ORDER BY `id` LIMIT {$limit}",
    );
    if ($rows === false) {
      return new PurgeReport($dryRun, [], ['Could not load expired SMS content.']);
    }

    $items = [];
    $errors = [];
    foreach ($rows as $row) {
      $item = ['id' => (int) $row['id'], 'code' => (string) $row['code'], 'action' => 'would_redact'];
      if (!$dryRun) {
        $updated = $this->connection->execute(
          "UPDATE {$this->table()} SET `receiver` = '', `message` = '', `content_redacted_at` = CURRENT_TIMESTAMP(6), `version` = `version` + 1 WHERE `id` = ? AND `version` = ? AND `content_redacted_at` IS NULL",
          [(int) $row['id'], (int) $row['version']],
        );
        if ($updated === false || $this->connection->affectedRows() !== 1) {
          $item['action'] = 'redaction_failed';
          $errors[] = "SMS {$item['code']} could not be redacted safely.";
        } else {
          $item['action'] = 'redacted';
        }
      }
      $items[] = $item;
    }

    return new PurgeReport($dryRun, $items, $errors);
  }

  public function backfillMissingCodes(bool $dryRun = true, int $limit = 500): BackfillReport
  {
    if ($limit < 1 || $limit > 5000) {
      throw new \InvalidArgumentException('Backfill limit must be between 1 and 5000.');
    }

    $rows = $this->connection->fetchAll(
      "SELECT `id` FROM {$this->table()} WHERE `code` IS NULL ORDER BY `id` LIMIT {$limit}",
    );
    if ($rows === false) {
      return new BackfillReport($dryRun, [], ['Could not load SMS rows with missing public codes.']);
    }

    $items = [];
    $errors = [];
    foreach ($rows as $row) {
      $id = (int) $row['id'];
      $item = ['id' => $id, 'action' => 'would_assign'];
      if (!$dryRun) {
        $assigned = false;
        for ($attempt = 0; $attempt < 5; ++$attempt) {
          $code = Sms::CODE_PREFIX . \str_pad((string) \random_int(0, 999999999999), 12, '0', STR_PAD_LEFT);
          $updated = $this->connection->execute(
            "UPDATE {$this->table()} SET `code` = ? WHERE `id` = ? AND `code` IS NULL",
            [$code, $id],
          );
          if ($updated !== false) {
            $item['action'] = $this->connection->affectedRows() === 1 ? 'assigned' : 'already_assigned';
            if ($item['action'] === 'assigned') $item['code'] = $code;
            $assigned = true;
            break;
          }
          if (!$this->isDuplicateError()) break;
        }
        if (!$assigned) {
          $item['action'] = 'assignment_failed';
          $errors[] = "SMS row {$id} could not receive a unique public code.";
        }
      }
      $items[] = $item;
    }

    return new BackfillReport($dryRun, $items, $errors);
  }

  /** @return array{sms: Sms, replayed: bool}|SendResult */
  private function createOrReplay(SendRequest $request, string $provider, string $sender): array|SendResult
  {
    for ($attempt = 0; $attempt < 5; ++$attempt) {
      $code = Sms::CODE_PREFIX . \str_pad((string) \random_int(0, 999999999999), 12, '0', STR_PAD_LEFT);
      $sql = "INSERT INTO {$this->table()} (`code`, `status`, `provider`, `idempotency_scope`, `idempotency_key_hash`, `version`, `message_id`, `direction`, `user`, `batch`, `sender`, `receiver`, `message`, `_message_pages`, `message_encoding`, `fees`, `content_expires_at`) VALUES (?, 'pending', ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, '0.00000000', CURRENT_TIMESTAMP(6) + INTERVAL ? DAY)";
      $result = $this->connection->execute($sql, [
        $code,
        $provider,
        $request->idempotencyScope,
        $request->idempotencyKeyHash,
        $request->messageId,
        $request->direction,
        $request->user,
        $request->batch,
        $sender,
        $request->receiver,
        $request->message,
        $request->messageSegments,
        $request->messageEncoding,
        $this->config->contentRetentionDays,
      ]);

      if ($result !== false) {
        $sms = $this->findById((int) $this->connection->insertId());
        if ($sms instanceof Sms) {
          return ['sms' => $sms, 'replayed' => false];
        }
        return $this->errorResult(SendResult::INFRASTRUCTURE_FAILED, ErrorCode::PERSISTENCE_FAILED, 'Pending SMS was inserted but could not be reloaded.');
      }

      if (!$this->isDuplicateError()) {
        return $this->errorResult(SendResult::INFRASTRUCTURE_FAILED, ErrorCode::PERSISTENCE_FAILED, 'Pending SMS could not be persisted.');
      }

      $existing = $this->fetchOne(
        "SELECT * FROM {$this->table()} WHERE `idempotency_scope` = ? AND `idempotency_key_hash` = ? LIMIT 1",
        [$request->idempotencyScope, $request->idempotencyKeyHash],
      );
      if ($existing['failed']) {
        return $this->errorResult(SendResult::INFRASTRUCTURE_FAILED, ErrorCode::PERSISTENCE_FAILED, 'An idempotent SMS attempt could not be reconciled.');
      }
      if ($existing['row'] !== null) {
        if (!$this->requestMatches($existing['row'], $request, $provider, $sender)) {
          return $this->errorResult(
            SendResult::REJECTED,
            ErrorCode::IDEMPOTENCY_CONFLICT,
            'The idempotency key is already bound to a different SMS request.',
          );
        }
        $existingSms = Sms::hydrate($existing['row'], $this->connection);
        return ['sms' => $existingSms, 'replayed' => $existingSms->status() !== Sms::STATUS_PENDING];
      }
      // The duplicate was the public code; retry with a new bounded random code.
    }

    return $this->errorResult(SendResult::INFRASTRUCTURE_FAILED, ErrorCode::PERSISTENCE_FAILED, 'A unique public SMS code could not be allocated.');
  }

  /** @return 'claimed'|'lost'|'failed' */
  private function claimForDispatch(Sms $sms): string
  {
    $result = $this->connection->execute(
      "UPDATE {$this->table()} SET `status` = 'dispatching', `dispatch_started_at` = CURRENT_TIMESTAMP(6), `version` = `version` + 1 WHERE `id` = ? AND `status` = 'pending' AND `version` = ?",
      [$sms->id(), $sms->version()],
    );
    if ($result === false) {
      return 'failed';
    }
    return $this->connection->affectedRows() === 1 ? 'claimed' : 'lost';
  }

  private function persistAccepted(Sms $sms, ProviderSendResult $result): bool
  {
    $meta = $result->meta === [] ? null : \json_encode($result->meta, JSON_THROW_ON_ERROR);
    $updated = $this->connection->execute(
      "UPDATE {$this->table()} SET `status` = 'sent', `reference` = ?, `sender` = ?, `fees` = ?, `fees_currency` = ?, `meta` = ?, `last_error_code` = NULL, `provider_accepted_at` = CURRENT_TIMESTAMP(6), `version` = `version` + 1 WHERE `id` = ? AND `status` = 'dispatching' AND `version` = ?",
      [$result->reference, $result->sender, $result->feeAmount, $result->feeCurrency, $meta, $sms->id(), $sms->version()],
    );
    return $updated !== false && $this->connection->affectedRows() === 1;
  }

  private function finishFailure(Sms $sms, string $providerCode): SendResult
  {
    if (!$this->persistOutcome($sms, Sms::STATUS_FAILED, $providerCode)) {
      return $this->errorResult(SendResult::INFRASTRUCTURE_FAILED, ErrorCode::PERSISTENCE_FAILED, 'Provider rejection could not be persisted safely.', $sms);
    }
    $failed = $this->findById($sms->id()) ?? $sms;
    return $this->errorResult(SendResult::REJECTED, ErrorCode::PROVIDER_REJECTED, 'The SMS provider rejected the request.', $failed);
  }

  private function finishUnknown(Sms $sms, string $providerCode): SendResult
  {
    if (!$this->persistOutcome($sms, Sms::STATUS_UNKNOWN, $providerCode)) {
      return $this->errorResult(SendResult::UNKNOWN, ErrorCode::OUTCOME_UNKNOWN, 'The provider outcome and database state are both ambiguous. Do not retry automatically.', $sms);
    }
    $unknown = $this->findById($sms->id()) ?? $sms;
    return $this->errorResult(SendResult::UNKNOWN, ErrorCode::OUTCOME_UNKNOWN, 'The provider outcome is unknown. Reconcile before retrying.', $unknown);
  }

  private function persistOutcome(Sms $sms, string $status, string $providerCode): bool
  {
    $updated = $this->connection->execute(
      "UPDATE {$this->table()} SET `status` = ?, `last_error_code` = ?, `version` = `version` + 1 WHERE `id` = ? AND `status` = 'dispatching' AND `version` = ?",
      [$status, \substr($providerCode, 0, 64), $sms->id(), $sms->version()],
    );
    return $updated !== false && $this->connection->affectedRows() === 1;
  }

  private function applyDeliveryReport(Sms $sms, ParsedDeliveryReport $report, WebhookRequest $request): DeliveryReportResult
  {
    $fingerprintMaterial = $report->eventId !== null
      ? $report->provider . "\0" . $report->eventId
      : $report->provider . "\0" . ($request->rawBody !== '' ? $request->rawBody : (string) \json_encode($request->parameters, JSON_THROW_ON_ERROR));
    $fingerprint = \hash('sha256', $fingerprintMaterial, true);
    $safeMeta = $report->meta === [] ? null : \json_encode($report->meta, JSON_THROW_ON_ERROR);

    if (!$this->connection->beginTransaction()) {
      return $this->deliveryPersistenceError();
    }

    $inserted = $this->connection->execute(
      "INSERT INTO {$this->eventTable()} (`sms_id`, `provider`, `event_id`, `event_fingerprint`, `status`, `outcome`, `safe_meta`) VALUES (?, ?, ?, ?, ?, 'processing', ?)",
      [$sms->id(), $report->provider, $report->eventId, $fingerprint, $report->status, $safeMeta],
    );
    if ($inserted === false) {
      $duplicate = $this->isDuplicateError();
      if (!$this->connection->rollBack()) {
        return $this->deliveryPersistenceError();
      }
      return $duplicate
        ? new DeliveryReportResult(DeliveryReportResult::DUPLICATE, $sms)
        : $this->deliveryPersistenceError();
    }
    $eventId = (int) $this->connection->insertId();

    if (!$this->isTransitionAllowed($sms->status(), $report->status)) {
      if (!$this->finishEvent($eventId, DeliveryReportResult::IGNORED)) {
        if (!$this->connection->rollBack()) {
          return $this->deliveryPersistenceError();
        }
        return $this->deliveryPersistenceError();
      }
      if (!$this->connection->commit()) {
        return $this->deliveryPersistenceError();
      }
      return new DeliveryReportResult(DeliveryReportResult::IGNORED, $sms);
    }

    $meta = $this->mergeMeta($sms, $report->meta);
    if ($meta === false) {
      if (!$this->connection->rollBack()) {
        return $this->deliveryPersistenceError();
      }
      return $this->deliveryError(DeliveryReportResult::INFRASTRUCTURE_FAILED, ErrorCode::CORRUPT_METADATA, 'Stored SMS metadata is corrupt.');
    }

    $updated = $this->connection->execute(
      "UPDATE {$this->table()} SET `status` = ?, `meta` = ?, `last_error_code` = ?, `version` = `version` + 1, `reconciled_at` = CURRENT_TIMESTAMP(6) WHERE `id` = ? AND `version` = ? AND `status` = ?",
      [
        $report->status,
        $meta === [] ? null : \json_encode($meta, JSON_THROW_ON_ERROR),
        $report->status === Sms::STATUS_FAILED ? 'provider_delivery_failed' : $sms->lastErrorCode(),
        $sms->id(),
        $sms->version(),
        $sms->status(),
      ],
    );
    if ($updated === false || $this->connection->affectedRows() !== 1) {
      if (!$this->connection->rollBack()) {
        return $this->deliveryPersistenceError();
      }
      return $this->deliveryPersistenceError();
    }

    if (!$this->finishEvent($eventId, DeliveryReportResult::UPDATED)) {
      if (!$this->connection->rollBack()) {
        return $this->deliveryPersistenceError();
      }
      return $this->deliveryPersistenceError();
    }
    if (!$this->connection->commit()) {
      return $this->deliveryPersistenceError();
    }

    $current = $this->findById($sms->id());
    return $current instanceof Sms
      ? new DeliveryReportResult(DeliveryReportResult::UPDATED, $current)
      : $this->deliveryPersistenceError();
  }

  private function finishEvent(int $eventId, string $outcome): bool
  {
    $result = $this->connection->execute(
      "UPDATE {$this->eventTable()} SET `outcome` = ?, `processed_at` = CURRENT_TIMESTAMP(6) WHERE `id` = ? AND `outcome` = 'processing'",
      [$outcome, $eventId],
    );
    return $result !== false && $this->connection->affectedRows() === 1;
  }

  /**
   * @param array<string, scalar|null> $incoming
   * @return array<array-key, mixed>|false
   */
  private function mergeMeta(Sms $sms, array $incoming): array|false
  {
    try {
      $existing = $sms->meta();
      if ($sms->hasErrors('meta')) {
        return false;
      }
      $merged = \array_merge($existing ?? [], $incoming);
      if (\strlen((string) \json_encode($merged, JSON_THROW_ON_ERROR)) > 4096) {
        $this->config->log(
          ErrorCode::METADATA_LIMIT,
          new \LengthException('Merged SMS metadata exceeded the safe limit; prior metadata was not carried forward.'),
          ['sms_id' => $sms->id()],
        );
        return $incoming;
      }
      return $merged;
    } catch (\Throwable $exception) {
      $this->config->log(ErrorCode::CORRUPT_METADATA, $exception, ['sms_id' => $sms->id()]);
      return false;
    }
  }

  private function isTransitionAllowed(string $current, string $target): bool
  {
    if ($current === Sms::STATUS_DELIVERED) {
      return false;
    }
    if ($current === $target) {
      return false;
    }
    return match ($current) {
      Sms::STATUS_PENDING, Sms::STATUS_DISPATCHING, Sms::STATUS_QUEUED, Sms::STATUS_UNKNOWN => \in_array($target, [Sms::STATUS_SENT, Sms::STATUS_FAILED, Sms::STATUS_DELIVERED], true),
      Sms::STATUS_SENT => \in_array($target, [Sms::STATUS_FAILED, Sms::STATUS_DELIVERED], true),
      Sms::STATUS_FAILED => $target === Sms::STATUS_DELIVERED,
      default => false,
    };
  }

  private function replayed(Sms $sms): SendResult
  {
    if ($sms->status() === Sms::STATUS_DISPATCHING) {
      return new SendResult(SendResult::IN_FLIGHT, $sms);
    }
    if ($sms->status() === Sms::STATUS_FAILED) {
      return $this->errorResult(
        SendResult::REJECTED,
        ErrorCode::PROVIDER_REJECTED,
        'The existing idempotent SMS attempt failed and was not resent.',
        $sms,
      );
    }
    if (!\in_array($sms->status(), [Sms::STATUS_SENT, Sms::STATUS_DELIVERED], true)) {
      return $this->errorResult(
        SendResult::UNKNOWN,
        ErrorCode::OUTCOME_UNKNOWN,
        'The existing idempotent SMS attempt is not safely accepted and was not resent.',
        $sms,
      );
    }
    return new SendResult(SendResult::REPLAYED, $sms);
  }

  private function findById(?int $id): ?Sms
  {
    if ($id === null) {
      return null;
    }
    $result = $this->fetchOne("SELECT * FROM {$this->table()} WHERE `id` = ? LIMIT 1", [$id]);
    return $result['failed'] || $result['row'] === null ? null : Sms::hydrate($result['row'], $this->connection);
  }

  /**
   * @param list<mixed> $params
   * @return array{row: array<string, mixed>|null, failed: bool}
   */
  private function fetchOne(string $sql, array $params): array
  {
    $row = $this->connection->fetchOne($sql, $params);
    if ($row === false) {
      return ['row' => null, 'failed' => $this->connection->lastErrorCode() !== null || $this->connection->lastSqlState() !== null];
    }
    return ['row' => $row, 'failed' => false];
  }

  private function isDuplicateError(): bool
  {
    $code = (string) ($this->connection->lastErrorCode() ?? '');
    return $code === '1062';
  }

  /** @param array<string, mixed> $row */
  private function requestMatches(array $row, SendRequest $request, string $provider, string $sender): bool
  {
    $contentWasRedacted = ($row['content_redacted_at'] ?? null) !== null;
    return (string) ($row['provider'] ?? '') === $provider
      && ($contentWasRedacted || (string) ($row['receiver'] ?? '') === $request->receiver)
      && ($contentWasRedacted || (string) ($row['message'] ?? '') === $request->message)
      && (string) ($row['sender'] ?? '') === $sender
      && (string) ($row['user'] ?? '') === $request->user
      && (($row['batch'] ?? null) === $request->batch)
      && (($row['message_id'] ?? null) === null ? $request->messageId === null : (int) $row['message_id'] === $request->messageId)
      && (string) ($row['direction'] ?? '') === $request->direction;
  }

  private function resolveProvider(?string $requested, string $receiver): string
  {
    if ($requested !== null) {
      if (!$this->config->hasDriver($requested)) {
        throw new \InvalidArgumentException('Requested SMS driver is not configured.');
      }
      return $requested;
    }
    if ($this->config->regionStrategy !== 'auto') {
      return $this->config->regionStrategy;
    }

    try {
      $continent = Phone::continent($receiver);
      if ($continent !== null && isset($this->config->continentMapping[$continent])) {
        return $this->config->continentMapping[$continent];
      }
    } catch (\Throwable) {
      // A validated receiver may still have no geography; use the configured fallback.
    }
    return $this->config->defaultDriver;
  }

  private function resolveSender(string $provider, ?string $requested): string
  {
    $driverConfig = $this->config->driver($provider);
    if (!\is_array($driverConfig)) {
      if ($requested !== null) {
        throw new \InvalidArgumentException('Custom driver sender selection requires an options wrapper and allowlist.');
      }
      return $this->config->defaultSender ?? throw new \InvalidArgumentException('Custom driver sender is not configured.');
    }
    return $this->config->resolveSender($driverConfig, $requested);
  }

  private function driver(string $name): SmsDriverInterface
  {
    if (isset($this->drivers[$name])) {
      return $this->drivers[$name];
    }

    $config = $this->config->driver($name);
    if ($config instanceof SmsDriverInterface) {
      $driver = $config;
    } elseif (\is_array($config) && ($config['driver'] ?? null) instanceof SmsDriverInterface) {
      $driver = $config['driver'];
    } elseif (\is_array($config)) {
      $config['logger'] ??= function (string $code, \Throwable $exception, array $context = []): void {
        $this->config->log($code, $exception, $context);
      };
      $driver = match ($name) {
        'twilio' => new TwilioDriver($config),
        'africastalking' => new AfricasTalkingDriver($config),
        default => throw new ProviderConfigurationException('Custom SMS driver instance is missing.'),
      };
    } else {
      throw new ProviderConfigurationException('SMS driver configuration is invalid.');
    }

    if ($driver->getProviderName() !== $name) {
      throw new ProviderConfigurationException('SMS driver identity does not match its configuration key.');
    }
    return $this->drivers[$name] = $driver;
  }

  private function assertProviderResult(ProviderSendResult $result, string $provider): void
  {
    if ($result->provider !== $provider) {
      throw new ProviderOutcomeUnknownException('provider_identity_mismatch');
    }
  }

  private function table(): string
  {
    return '`' . $this->config->dbName . '`.`sms`';
  }

  private function eventTable(): string
  {
    return '`' . $this->config->dbName . '`.`sms_delivery_event`';
  }

  private function errorResult(string $outcome, string $code, string $message, ?Sms $sms = null): SendResult
  {
    return new SendResult($outcome, $sms, [$code => [$message]]);
  }

  private function deliveryError(string $outcome, string $code, string $message): DeliveryReportResult
  {
    return new DeliveryReportResult($outcome, errors: [$code => [$message]]);
  }

  private function deliveryPersistenceError(): DeliveryReportResult
  {
    return $this->deliveryError(DeliveryReportResult::INFRASTRUCTURE_FAILED, ErrorCode::WEBHOOK_PERSISTENCE_FAILED, 'SMS webhook state could not be persisted safely.');
  }
}
