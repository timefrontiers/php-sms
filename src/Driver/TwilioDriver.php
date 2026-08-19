<?php

declare(strict_types=1);

namespace TimeFrontiers\Sms\Driver;

use TimeFrontiers\Sms\Dto\ParsedDeliveryReport;
use TimeFrontiers\Sms\Dto\ProviderSendResult;
use TimeFrontiers\Sms\Dto\WebhookRequest;
use TimeFrontiers\Sms\Exception\ProviderConfigurationException;
use TimeFrontiers\Sms\Exception\ProviderOutcomeUnknownException;
use TimeFrontiers\Sms\Money\Decimal;
use TimeFrontiers\Sms\Sms;
use Twilio\Rest\Client;
use Twilio\Security\RequestValidator;

final class TwilioDriver implements SmsDriverInterface
{
  private const PROVIDER = 'twilio';
  /** @var \Closure(string, array{from: string, body: string}): object */
  private \Closure $sendMessage;

  /** @param array<string, mixed> $config */
  public function __construct(private readonly array $config)
  {
    $sid = $config['sid'] ?? null;
    $token = $config['token'] ?? null;
    if (!\is_string($sid) || $sid === '' || !\is_string($token) || $token === '') {
      throw new ProviderConfigurationException('Twilio credentials are incomplete.');
    }

    $sender = $config['send_callable'] ?? null;
    if ($sender !== null && !\is_callable($sender)) {
      throw new ProviderConfigurationException('Twilio send_callable must be callable.');
    }
    if ($sender === null) {
      $client = new Client($sid, $token);
      $sender = static fn(string $receiver, array $options): object => $client->messages->create($receiver, $options);
    }
    $this->sendMessage = \Closure::fromCallable($sender);
  }

  public function send(Sms $sms): ProviderSendResult
  {
    if ($sms->sender() === '') {
      throw new ProviderConfigurationException('Twilio sender was not resolved before dispatch.');
    }

    try {
      $message = ($this->sendMessage)(
        $sms->receiver(),
        ['from' => $sms->sender(), 'body' => $sms->message()]
      );
    } catch (\Throwable $exception) {
      throw new ProviderOutcomeUnknownException('twilio_transport_unknown', $exception);
    }

    $reference = $message->sid ?? null;
    if (!\is_string($reference) || $reference === '') {
      throw new ProviderOutcomeUnknownException('twilio_missing_reference');
    }

    $status = $message->status ?? null;
    [$fee, $feeParseFailed] = $this->fee($message->price ?? null);
    $meta = ['provider_status' => \is_string($status) ? \substr($status, 0, 32) : null];
    if ($feeParseFailed) {
      $meta['fee_parse_failed'] = true;
    }
    return new ProviderSendResult(
      self::PROVIDER,
      $reference,
      $sms->sender(),
      $fee,
      self::currency($message->priceUnit ?? 'USD'),
      $meta,
    );
  }

  public function verifyDeliveryReport(WebhookRequest $request): bool
  {
    $signature = $request->header('x-twilio-signature');
    $configuredUrl = $this->config['webhook_url'] ?? null;
    if (!\is_string($signature) || $signature === '' || !\is_string($configuredUrl) || $configuredUrl === '') {
      return false;
    }
    $identityUrl = self::withoutBodyHash($request->canonicalUrl);
    if ($identityUrl === null || !\hash_equals($configuredUrl, $identityUrl)) {
      return false;
    }

    $token = $this->config['token'];
    \assert(\is_string($token));
    $validator = new RequestValidator($token);
    $data = $request->contentType() === 'application/json' ? $request->rawBody : $request->parameters;

    try {
      return $validator->validate($signature, $request->canonicalUrl, $data);
    } catch (\Throwable) {
      return false;
    }
  }

  public function parseDeliveryReport(WebhookRequest $request): ParsedDeliveryReport
  {
    $payload = $this->payload($request);
    $reference = $payload['SmsSid'] ?? $payload['MessageSid'] ?? null;
    $rawStatus = $payload['MessageStatus'] ?? $payload['SmsStatus'] ?? null;
    if (!\is_string($reference) || !\is_string($rawStatus)) {
      throw new \InvalidArgumentException('Twilio delivery report is missing required fields.');
    }

    $status = match (\strtolower($rawStatus)) {
      'accepted', 'queued', 'sending', 'sent' => 'sent',
      'delivered', 'read' => 'delivered',
      'failed', 'undelivered', 'canceled' => 'failed',
      default => throw new \InvalidArgumentException('Twilio delivery status is unsupported.'),
    };

    $eventId = $payload['EventSid'] ?? $payload['EventId'] ?? null;
    return new ParsedDeliveryReport(
      self::PROVIDER,
      $reference,
      $status,
      \is_string($eventId) ? $eventId : null,
      [
        'provider_status' => \substr($rawStatus, 0, 32),
        'error_code' => self::safeScalar($payload['ErrorCode'] ?? null, 32),
      ],
    );
  }

  public function getProviderName(): string
  {
    return self::PROVIDER;
  }

  /** @return array<string, mixed> */
  private function payload(WebhookRequest $request): array
  {
    if ($request->contentType() !== 'application/json') {
      return $request->parameters;
    }
    $decoded = \json_decode($request->rawBody, true, flags: JSON_THROW_ON_ERROR);
    if (!\is_array($decoded)) {
      throw new \InvalidArgumentException('Twilio JSON webhook body must be an object.');
    }
    return $decoded;
  }

  private static function currency(mixed $value): string
  {
    $value = \is_string($value) ? \strtoupper($value) : 'USD';
    return \preg_match('/^[A-Z][A-Z0-9]{2,7}$/D', $value) ? $value : 'USD';
  }

  /** @return array{string, bool} */
  private function fee(mixed $value): array
  {
    try {
      if (!\is_string($value) && !\is_int($value) && !\is_float($value) && $value !== null) {
        throw new \InvalidArgumentException('Twilio returned a non-scalar fee.');
      }
      return [Decimal::normalize($value), false];
    } catch (\InvalidArgumentException $exception) {
      $this->logFeeFailure($exception, $value);
      return ['0.00000000', true];
    }
  }

  private function logFeeFailure(\Throwable $exception, mixed $value): void
  {
    $logger = $this->config['logger'] ?? null;
    if (!\is_callable($logger)) {
      return;
    }
    $logger('twilio_fee_parse_failed', $exception, [
      'provider' => self::PROVIDER,
      'raw_fee' => \is_scalar($value) || $value === null ? $value : \get_debug_type($value),
    ]);
  }

  private static function withoutBodyHash(string $url): ?string
  {
    $parts = \parse_url($url);
    if (!\is_array($parts) || !isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
      return null;
    }

    $query = [];
    if (isset($parts['query'])) {
      \parse_str($parts['query'], $query);
      unset($query['bodySHA256']);
    }

    $identity = $parts['scheme'] . '://' . $parts['host'];
    if (isset($parts['port'])) {
      $identity .= ':' . $parts['port'];
    }
    $identity .= $parts['path'] ?? '';
    if ($query !== []) {
      $identity .= '?' . \http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
    return $identity;
  }

  private static function safeScalar(mixed $value, int $max): string|int|float|bool|null
  {
    if (!\is_scalar($value)) {
      return null;
    }
    return \is_string($value) ? \substr($value, 0, $max) : $value;
  }
}
