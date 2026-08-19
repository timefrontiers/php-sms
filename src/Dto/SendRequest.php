<?php

declare(strict_types=1);

namespace TimeFrontiers\Sms\Dto;

use TimeFrontiers\Phone;
use TimeFrontiers\Sms\Config\SmsConfig;
use TimeFrontiers\Sms\Encoding\SegmentCounter;
use TimeFrontiers\Sms\Exception\SendValidationException;
use TimeFrontiers\Validation\Validator;

final readonly class SendRequest
{
  private function __construct(
    public string $receiver,
    public string $message,
    public ?string $requestedSender,
    public ?string $requestedDriver,
    public string $user,
    public ?string $batch,
    public ?int $messageId,
    public string $direction,
    public string $idempotencyScope,
    public string $idempotencyKeyHash,
    public int $messageSegments,
    public string $messageEncoding,
  ) {
  }

  /** @param array<string, mixed> $data */
  public static function fromArray(array $data, SmsConfig $config): self
  {
    $errors = [];

    $receiver = self::string($data, 'receiver', 32, $errors, required: true);
    $message = self::string($data, 'message', null, $errors, required: true, trim: false);
    $sender = self::string($data, 'sender', 16, $errors);
    $driver = self::string($data, 'driver', 32, $errors);
    $user = self::string($data, 'user', 15, $errors) ?? 'SYSTEM';
    $batch = self::string($data, 'batch', 15, $errors);
    $direction = self::string($data, 'direction', 16, $errors) ?? 'outbound';
    $scope = self::string($data, 'idempotency_scope', 64, $errors) ?? $config->idempotencyScope;
    $idempotencyKey = self::string($data, 'idempotency_key', 128, $errors);

    $messageId = $data['message_id'] ?? null;
    if ($messageId !== null && (!\is_int($messageId) || $messageId < 1)) {
      $errors['message_id'][] = 'Must be a positive integer or null.';
      $messageId = null;
    }

    if ($direction !== 'outbound') {
      $errors['direction'][] = 'Sms::send() accepts outbound messages only.';
    }

    if (!\preg_match('/^[A-Za-z0-9_.:-]+$/D', $scope)) {
      $errors['idempotency_scope'][] = 'Contains unsupported characters.';
    }

    $normalizedReceiver = null;
    if ($receiver !== null) {
      $normalizedReceiver = Phone::toE164($receiver, $config->defaultRegion);
      if ($normalizedReceiver === null) {
        $errors['receiver'][] = 'Could not be normalized to a valid E.164 number.';
      } else {
        $validation = Validator::make(['receiver' => $normalizedReceiver], ['receiver' => 'required|phone']);
        if ($validation->fails()) {
          $errors['receiver'] = \array_merge($errors['receiver'] ?? [], $validation->errorsFor('receiver'));
        } else {
          $normalizedReceiver = (string) $validation->get('receiver');
        }
      }
    }

    $segmentCount = null;
    if ($message !== null) {
      if (\trim($message) === '') {
        $errors['message'][] = 'Must not be empty.';
      } else {
        try {
          $segmentCount = (new SegmentCounter())->count($message);
          if ($segmentCount->segments > $config->maxSegments) {
            $errors['message'][] = "Exceeds the configured {$config->maxSegments}-segment transport limit.";
          }
        } catch (\InvalidArgumentException $exception) {
          $errors['message'][] = $exception->getMessage();
        }
      }
    }

    if ($errors !== []) {
      throw new SendValidationException($errors);
    }

    if ($idempotencyKey === null) {
      $idempotencyKey = \bin2hex(\random_bytes(32));
    }

    if ($normalizedReceiver === null || $message === null || $segmentCount === null) {
      throw new \LogicException('Validated SMS input is incomplete.');
    }

    return new self(
      $normalizedReceiver,
      $segmentCount->message,
      $sender,
      $driver,
      $user,
      $batch,
      $messageId,
      $direction,
      $scope,
      \hash('sha256', $idempotencyKey, true),
      $segmentCount->segments,
      $segmentCount->encoding,
    );
  }

  /**
   * @param array<string, mixed> $data
   * @param array<string, list<string>> $errors
   */
  private static function string(
    array $data,
    string $field,
    ?int $max,
    array &$errors,
    bool $required = false,
    bool $trim = true,
  ): ?string {
    $value = $data[$field] ?? null;
    if ($value === null) {
      if ($required) {
        $errors[$field][] = 'Is required.';
      }
      return null;
    }
    if (!\is_string($value)) {
      $errors[$field][] = 'Must be a string.';
      return null;
    }

    $value = $trim ? \trim($value) : $value;
    if ($value === '') {
      if ($required) {
        $errors[$field][] = 'Must not be empty.';
      }
      return null;
    }
    if ($max !== null && \mb_strlen($value, 'UTF-8') > $max) {
      $errors[$field][] = "Must not exceed {$max} characters.";
      return null;
    }
    return $value;
  }
}
