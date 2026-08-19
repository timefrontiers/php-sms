<?php

declare(strict_types=1);

namespace TimeFrontiers\Sms\Tests\Support;

use TimeFrontiers\SQLDatabase;

final class FakeDatabase extends SQLDatabase
{
  /** @var array<int, array<string, mixed>> */
  private array $sms = [];
  /** @var array<int, array<string, mixed>> */
  private array $events = [];
  private int $nextSmsId = 1;
  private int $nextEventId = 1;
  private int $lastInsertId = 0;
  private int $affected = 0;
  private string|null $errorCode = null;
  private ?string $sqlState = null;
  /** @var array<string, list<array{int, int, string, string, int}>> */
  private array $errors = [];
  /** @var list<string> */
  private array $failPatterns = [];
  /** @var array{array<int, array<string, mixed>>, array<int, array<string, mixed>>}|null */
  private ?array $snapshot = null;

  public function __construct()
  {
  }

  public function failNext(string $sqlFragment): void
  {
    $this->failPatterns[] = $sqlFragment;
  }

  /** @return array<int, array<string, mixed>> */
  public function smsRows(): array
  {
    return $this->sms;
  }

  /** @param array<string, mixed> $row */
  public function replaceSmsRow(int $id, array $row): void
  {
    $this->sms[$id] = $row;
  }

  /** @param list<mixed> $params */
  public function execute(string $sql, array $params = []): bool
  {
    $this->resetStatement();
    foreach ($this->failPatterns as $index => $fragment) {
      if (\str_contains($sql, $fragment)) {
        \array_splice($this->failPatterns, $index, 1);
        return $this->failure('9001', 'HY000');
      }
    }

    if (\str_starts_with($sql, 'INSERT INTO') && \str_contains($sql, '`sms_delivery_event`')) {
      [$smsId, $provider, $eventId, $fingerprint, $status, $meta] = $params;
      foreach ($this->events as $event) {
        if ($event['provider'] === $provider && ($event['event_fingerprint'] === $fingerprint || ($eventId !== null && $event['event_id'] === $eventId))) {
          return $this->failure('1062', '23000');
        }
      }
      $id = $this->nextEventId++;
      $this->events[$id] = [
        'id' => $id, 'sms_id' => $smsId, 'provider' => $provider,
        'event_id' => $eventId, 'event_fingerprint' => $fingerprint,
        'status' => $status, 'outcome' => 'processing', 'safe_meta' => $meta,
      ];
      $this->lastInsertId = $id;
      $this->affected = 1;
      return true;
    }

    if (\str_starts_with($sql, 'INSERT INTO') && \str_contains($sql, '`.`sms`')) {
      [$code, $provider, $scope, $hash, $messageId, $direction, $user, $batch, $sender, $receiver, $message, $pages, $encoding, $retentionDays] = $params;
      foreach ($this->sms as $row) {
        if ($row['code'] === $code || ($row['idempotency_scope'] === $scope && $row['idempotency_key_hash'] === $hash)) {
          return $this->failure('1062', '23000');
        }
      }
      $id = $this->nextSmsId++;
      $this->sms[$id] = [
        'id' => $id, 'code' => $code, 'status' => 'pending', 'provider' => $provider,
        'idempotency_scope' => $scope, 'idempotency_key_hash' => $hash, 'version' => 0,
        'message_id' => $messageId, 'direction' => $direction, 'user' => $user, 'batch' => $batch,
        'sender' => $sender, 'receiver' => $receiver, 'message' => $message,
        '_message_pages' => $pages, 'message_encoding' => $encoding,
        'fees_currency' => null, 'fees' => '0.00000000', 'reference' => null, 'meta' => null,
        'last_error_code' => null, 'dispatch_started_at' => null, 'provider_accepted_at' => null,
        'reconciled_at' => null, 'content_expires_at' => $retentionDays === null ? null : '2099-01-01 00:00:00',
        'content_redacted_at' => null,
        '_created' => '2026-08-13 00:00:00', '_updated' => '2026-08-13 00:00:00',
      ];
      $this->lastInsertId = $id;
      $this->affected = 1;
      return true;
    }

    if (\str_contains($sql, "SET `status` = 'dispatching'")) {
      [$id, $version] = $params;
      if (isset($this->sms[$id]) && $this->sms[$id]['status'] === 'pending' && $this->sms[$id]['version'] === $version) {
        $this->sms[$id]['status'] = 'dispatching';
        $this->sms[$id]['dispatch_started_at'] = '2026-08-13 00:00:00';
        ++$this->sms[$id]['version'];
        $this->affected = 1;
      }
      return true;
    }

    if (\str_contains($sql, "SET `status` = 'sent'")) {
      [$reference, $sender, $fees, $currency, $meta, $id, $version] = $params;
      if (isset($this->sms[$id]) && $this->sms[$id]['status'] === 'dispatching' && $this->sms[$id]['version'] === $version) {
        foreach ($this->sms as $otherId => $row) {
          if ($otherId !== $id && $row['provider'] === $this->sms[$id]['provider'] && $row['reference'] === $reference) {
            return $this->failure('1062', '23000');
          }
        }
        $this->sms[$id]['status'] = 'sent';
        $this->sms[$id]['reference'] = $reference;
        $this->sms[$id]['sender'] = $sender;
        $this->sms[$id]['fees'] = $fees;
        $this->sms[$id]['fees_currency'] = $currency;
        $this->sms[$id]['meta'] = $meta;
        $this->sms[$id]['provider_accepted_at'] = '2026-08-13 00:00:01';
        $this->sms[$id]['last_error_code'] = null;
        ++$this->sms[$id]['version'];
        $this->affected = 1;
      }
      return true;
    }

    if (\str_contains($sql, 'SET `status` = ?, `last_error_code` = ?')) {
      [$status, $errorCode, $id, $version] = $params;
      if (isset($this->sms[$id]) && $this->sms[$id]['status'] === 'dispatching' && $this->sms[$id]['version'] === $version) {
        $this->sms[$id]['status'] = $status;
        $this->sms[$id]['last_error_code'] = $errorCode;
        ++$this->sms[$id]['version'];
        $this->affected = 1;
      }
      return true;
    }

    if (\str_contains($sql, 'SET `status` = ?, `meta` = ?')) {
      [$status, $meta, $errorCode, $id, $version, $oldStatus] = $params;
      if (isset($this->sms[$id]) && $this->sms[$id]['status'] === $oldStatus && $this->sms[$id]['version'] === $version) {
        $this->sms[$id]['status'] = $status;
        $this->sms[$id]['meta'] = $meta;
        $this->sms[$id]['last_error_code'] = $errorCode;
        $this->sms[$id]['reconciled_at'] = '2026-08-13 00:00:02';
        ++$this->sms[$id]['version'];
        $this->affected = 1;
      }
      return true;
    }

    if (\str_contains($sql, 'UPDATE') && \str_contains($sql, '`sms_delivery_event`')) {
      [$outcome, $id] = $params;
      if (isset($this->events[$id]) && $this->events[$id]['outcome'] === 'processing') {
        $this->events[$id]['outcome'] = $outcome;
        $this->affected = 1;
      }
      return true;
    }

    if (\str_contains($sql, "SET `receiver` = '', `message` = ''")) {
      [$id, $version] = $params;
      if (isset($this->sms[$id]) && $this->sms[$id]['version'] === $version && $this->sms[$id]['content_redacted_at'] === null) {
        $this->sms[$id]['receiver'] = '';
        $this->sms[$id]['message'] = '';
        $this->sms[$id]['content_redacted_at'] = '2026-08-13 00:00:03';
        ++$this->sms[$id]['version'];
        $this->affected = 1;
      }
      return true;
    }

    if (\str_contains($sql, 'SET `code` = ?') && \str_contains($sql, '`code` IS NULL')) {
      [$code, $id] = $params;
      foreach ($this->sms as $otherId => $row) {
        if ($otherId !== $id && $row['code'] === $code) return $this->failure('1062', '23000');
      }
      if (isset($this->sms[$id]) && $this->sms[$id]['code'] === null) {
        $this->sms[$id]['code'] = $code;
        $this->affected = 1;
      }
      return true;
    }

    return $this->failure('9002', 'HY000');
  }

  /**
   * @param list<mixed> $params
   * @return array<string, mixed>|false
   */
  public function fetchOne(string $sql, array $params = []): array|false
  {
    $this->resetStatement();
    foreach ($this->failPatterns as $index => $fragment) {
      if (\str_contains($sql, $fragment)) {
        \array_splice($this->failPatterns, $index, 1);
        $this->failure('9001', 'HY000');
        return false;
      }
    }

    if (\str_contains($sql, 'WHERE `id` = ?')) {
      return $this->sms[(int) $params[0]] ?? false;
    }
    if (\str_contains($sql, '`idempotency_scope` = ?')) {
      foreach ($this->sms as $row) {
        if ($row['idempotency_scope'] === $params[0] && $row['idempotency_key_hash'] === $params[1]) return $row;
      }
      return false;
    }
    if (\str_contains($sql, '`provider` = ?') && \str_contains($sql, '`reference` = ?')) {
      foreach ($this->sms as $row) {
        if ($row['provider'] === $params[0] && $row['reference'] === $params[1]) return $row;
      }
      return false;
    }
    if (\str_contains($sql, '`code` = ?')) {
      foreach ($this->sms as $row) if ($row['code'] === $params[0]) return $row;
      return false;
    }
    $this->failure('9003', 'HY000');
    return false;
  }

  /**
   * @param list<mixed> $params
   * @return list<array<string, mixed>>|false
   */
  public function fetchAll(string $sql, array $params = []): array|false
  {
    $this->resetStatement();
    foreach ($this->failPatterns as $index => $fragment) {
      if (\str_contains($sql, $fragment)) {
        \array_splice($this->failPatterns, $index, 1);
        $this->failure('9001', 'HY000');
        return false;
      }
    }
    if (\str_contains($sql, "`status` = 'unknown'")) {
      return \array_values(\array_filter($this->sms, static fn(array $row): bool => \in_array($row['status'], ['unknown', 'dispatching'], true)));
    }
    if (\str_contains($sql, '`content_expires_at` IS NOT NULL')) {
      return \array_values(\array_map(
        static fn(array $row): array => ['id' => $row['id'], 'code' => $row['code'], 'version' => $row['version']],
        \array_filter($this->sms, static fn(array $row): bool => $row['content_expires_at'] !== null && $row['content_redacted_at'] === null),
      ));
    }
    if (\str_contains($sql, 'WHERE `code` IS NULL')) {
      return \array_values(\array_map(
        static fn(array $row): array => ['id' => $row['id']],
        \array_filter($this->sms, static fn(array $row): bool => $row['code'] === null),
      ));
    }
    if (\str_contains($sql, 'WHERE `reference` = ?')) {
      return \array_values(\array_filter($this->sms, static fn(array $row): bool => $row['reference'] === $params[0]));
    }
    return [];
  }

  public function beginTransaction(): bool
  {
    $this->snapshot = [$this->sms, $this->events];
    return true;
  }

  public function commit(): bool
  {
    $this->snapshot = null;
    return true;
  }

  public function rollBack(): bool
  {
    if ($this->snapshot !== null) {
      [$this->sms, $this->events] = $this->snapshot;
    }
    $this->snapshot = null;
    return true;
  }

  public function inTransaction(): bool { return $this->snapshot !== null; }
  public function transactionDepth(): int { return $this->snapshot === null ? 0 : 1; }
  public function affectedRows(): int { return $this->affected; }
  public function insertId(): int { return $this->lastInsertId; }
  public function lastErrorCode(): string|null { return $this->errorCode; }
  public function lastSqlState(): ?string { return $this->sqlState; }
  /** @return array<string, list<array{int, int, string, string, int}>> */
  public function getErrors(): array { return $this->errors; }

  private function resetStatement(): void
  {
    $this->affected = 0;
    $this->errorCode = null;
    $this->sqlState = null;
  }

  private function failure(string $code, string $state): false
  {
    $this->errorCode = $code;
    $this->sqlState = $state;
    $this->errors['database'][] = [7, 256, 'Simulated database failure.', __FILE__, __LINE__];
    return false;
  }
}
