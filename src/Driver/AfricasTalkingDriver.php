<?php

declare(strict_types=1);

namespace TimeFrontiers\Sms\Driver;

use AfricasTalking\SDK\AfricasTalking;
use TimeFrontiers\Sms\Dto\ParsedDeliveryReport;
use TimeFrontiers\Sms\Dto\ProviderSendResult;
use TimeFrontiers\Sms\Dto\WebhookRequest;
use TimeFrontiers\Sms\Exception\ProviderConfigurationException;
use TimeFrontiers\Sms\Exception\ProviderOutcomeUnknownException;
use TimeFrontiers\Sms\Exception\ProviderRejectedException;
use TimeFrontiers\Sms\Money\Decimal;
use TimeFrontiers\Sms\Sms;

final class AfricasTalkingDriver implements SmsDriverInterface
{
  private const PROVIDER = 'africastalking';
  /** @var \Closure(array{to: string, message: string, from: string|null}): object */
  private \Closure $sendMessage;

  /** @param array<string, mixed> $config */
  public function __construct(private readonly array $config)
  {
    $appId = $config['app_id'] ?? null;
    $apiKey = $config['api_key'] ?? null;
    if (!\is_string($appId) || $appId === '' || !\is_string($apiKey) || $apiKey === '') {
      throw new ProviderConfigurationException('AfricasTalking credentials are incomplete.');
    }

    $sender = $config['send_callable'] ?? null;
    if ($sender !== null && !\is_callable($sender)) {
      throw new ProviderConfigurationException('AfricasTalking send_callable must be callable.');
    }
    if ($sender === null) {
      $client = new AfricasTalking($appId, $apiKey);
      $service = $client->sms();
      $sender = static fn(array $payload): object => $service->send($payload);
    }
    $this->sendMessage = \Closure::fromCallable($sender);
  }

  public function send(Sms $sms): ProviderSendResult
  {
    try {
      $result = ($this->sendMessage)([
        'to' => $sms->receiver(),
        'message' => $sms->message(),
        'from' => $sms->sender() === '' ? null : $sms->sender(),
      ]);
    } catch (\Throwable $exception) {
      throw new ProviderOutcomeUnknownException('africastalking_transport_unknown', $exception);
    }

    $result = \json_decode(\json_encode($result, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    if (!\is_array($result)) {
      throw new ProviderOutcomeUnknownException('africastalking_invalid_response');
    }
    if (\strtolower((string) ($result['status'] ?? '')) !== 'success') {
      throw new ProviderRejectedException('africastalking_rejected');
    }

    $smsData = $result['data']['SMSMessageData'] ?? $result['SMSMessageData'] ?? [];
    $recipients = $smsData['Recipients'] ?? $result['data']['Recipients'] ?? $result['Recipients'] ?? [];
    $first = \is_array($recipients) ? self::recipient($recipients, $sms->receiver()) : null;
    if (!\is_array($first)) {
      throw new ProviderRejectedException('africastalking_no_recipient');
    }

    $statusCode = \filter_var($first['statusCode'] ?? null, FILTER_VALIDATE_INT);
    if (!\is_int($statusCode) || !\in_array($statusCode, [100, 101, 102], true)) {
      throw new ProviderRejectedException('africastalking_recipient_' . ($statusCode === false ? 'invalid' : $statusCode));
    }

    $reference = $first['messageId'] ?? null;
    if (!\is_string($reference) || $reference === '' || \strcasecmp(\trim($reference), 'None') === 0) {
      if (\is_string($reference) && \strcasecmp(\trim($reference), 'None') === 0) {
        throw new ProviderRejectedException('africastalking_no_message_id');
      }
      throw new ProviderOutcomeUnknownException('africastalking_missing_reference');
    }

    [$currency, $amount, $feeParseFailed] = $this->cost($first['cost'] ?? null);
    $providerStatus = $first['status'] ?? null;
    $meta = ['provider_status' => \is_string($providerStatus) ? \substr($providerStatus, 0, 32) : null];
    if ($feeParseFailed) {
      $meta['fee_parse_failed'] = true;
    }
    return new ProviderSendResult(
      self::PROVIDER,
      $reference,
      $sms->sender(),
      $amount,
      $currency,
      $meta,
    );
  }

  public function verifyDeliveryReport(WebhookRequest $request): bool
  {
    $verifier = $this->config['webhook_verifier'] ?? null;
    if (!\is_callable($verifier)) {
      return false;
    }
    try {
      return $verifier($request) === true;
    } catch (\Throwable) {
      return false;
    }
  }

  public function parseDeliveryReport(WebhookRequest $request): ParsedDeliveryReport
  {
    $payload = $request->parameters;
    $reference = $payload['id'] ?? null;
    $rawStatus = $payload['status'] ?? null;
    if (!\is_string($reference) || !\is_string($rawStatus)) {
      throw new \InvalidArgumentException('AfricasTalking delivery report is missing required fields.');
    }

    $status = match (\strtolower($rawStatus)) {
      'sent', 'submitted', 'buffered' => 'sent',
      'success', 'delivered' => 'delivered',
      'failed', 'rejected', 'expired', 'undeliverable' => 'failed',
      default => throw new \InvalidArgumentException('AfricasTalking delivery status is unsupported.'),
    };
    $eventId = $payload['eventId'] ?? $payload['event_id'] ?? null;

    return new ParsedDeliveryReport(
      self::PROVIDER,
      $reference,
      $status,
      \is_string($eventId) ? $eventId : null,
      [
        'provider_status' => \substr($rawStatus, 0, 32),
        'failure_reason' => self::safeString($payload['failureReason'] ?? null, 64),
      ],
    );
  }

  public function getProviderName(): string
  {
    return self::PROVIDER;
  }

  /**
   * @param array<array-key, mixed> $recipients
   * @return array<string, mixed>|null
   */
  private static function recipient(array $recipients, string $receiver): ?array
  {
    if (\count($recipients) === 1) {
      $recipient = \reset($recipients);
      return \is_array($recipient) ? $recipient : null;
    }

    $matches = [];
    foreach ($recipients as $recipient) {
      if (\is_array($recipient) && ($recipient['number'] ?? null) === $receiver) {
        $matches[] = $recipient;
      }
    }
    return \count($matches) === 1 ? $matches[0] : null;
  }

  /** @return array{string, string, bool} */
  private function cost(mixed $cost): array
  {
    if (!\is_string($cost) || !\preg_match('/^([A-Za-z][A-Za-z0-9]{2,7})\s+(-?\d+(?:\.\d+)?)$/D', \trim($cost), $matches)) {
      $this->logFeeFailure(new \InvalidArgumentException('AfricasTalking returned an invalid fee.'), $cost);
      return ['KES', '0.00000000', true];
    }
    try {
      return [\strtoupper($matches[1]), Decimal::normalize($matches[2]), false];
    } catch (\InvalidArgumentException $exception) {
      $this->logFeeFailure($exception, $cost);
      return [\strtoupper($matches[1]), '0.00000000', true];
    }
  }

  private function logFeeFailure(\Throwable $exception, mixed $value): void
  {
    $logger = $this->config['logger'] ?? null;
    if (!\is_callable($logger)) {
      return;
    }
    $logger('africastalking_fee_parse_failed', $exception, [
      'provider' => self::PROVIDER,
      'raw_fee' => \is_scalar($value) || $value === null ? $value : \get_debug_type($value),
    ]);
  }

  private static function safeString(mixed $value, int $max): ?string
  {
    return \is_string($value) ? \substr($value, 0, $max) : null;
  }
}
