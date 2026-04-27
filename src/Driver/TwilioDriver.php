<?php

declare(strict_types=1);

namespace TimeFrontiers\Sms\Driver;

use TimeFrontiers\Sms\Sms;
use Twilio\Rest\Client;
use Twilio\Security\RequestValidator;

/**
 * Twilio SMS driver for timefrontiers/php-sms.
 *
 * Requires `twilio/sdk`.
 * Credentials are injected via constructor from the configuration array.
 */
class TwilioDriver implements SmsDriverInterface
{
  private string $providerName = 'twilio';

  /**
   * @param array{
   *   sid: string,
   *   token: string,
   *   sender_id?: string,
   *   sender_phone?: string
   * } $config
   */
  public function __construct(private array $config)
  {
  }

  // ---------------------------------------------------------------------------
  // SmsDriverInterface
  // ---------------------------------------------------------------------------

  public function send(Sms $sms): array
  {
    $sid   = $this->config['sid']   ?? throw new \RuntimeException('Twilio SID not configured.');
    $token = $this->config['token'] ?? throw new \RuntimeException('Twilio token not configured.');

    $client = new Client($sid, $token);

    // Resolve sender: prefer the per‑message sender, then configured sender_id, then sender_phone
    $from = $sms->sender ?: ($this->config['sender_id'] ?? $this->config['sender_phone'] ?? null);
    if (!$from) {
      throw new \RuntimeException('Twilio sender not configured or provided.');
    }

    $message = $client->messages->create(
      $sms->receiver,
      [
        'from' => $from,
        'body' => $sms->message,
      ]
    );

    if (empty($message->sid)) {
      throw new \RuntimeException('Twilio API did not return a message SID.');
    }

    return [
      (float) ($message->price ?? 0),
      $message->priceUnit ?? 'USD',
      $message->sid,
      $from
    ];
  }

  public function verifyDeliveryReport(array $payload): bool
  {
    $authToken = $this->config['token'] ?? '';
    $validator = new RequestValidator($authToken);
    $signature = $payload['Signature'] ?? '';

    $url = $this->buildWebhookUrl();

    return $validator->validate($signature, $url, $payload);
  }

  public function parseDeliveryReport(array $payload): array
  {
    $reference = $payload['SmsSid'] ?? '';
    $rawStatus = strtolower($payload['MessageStatus'] ?? '');

    $status = match ($rawStatus) {
      'delivered' => 'delivered',
      'failed', 'undelivered' => 'failed',
      default => null
    };

    return [
      'reference' => $reference,
      'status'    => $status,
      'meta'      => [
        'raw_status'    => $payload['MessageStatus'] ?? null,
        'error_code'    => $payload['ErrorCode'] ?? null,
        'error_message' => $payload['ErrorMessage'] ?? null,
      ],
    ];
  }

  public function getProviderName(): string
  {
    return $this->providerName;
  }

  // ---------------------------------------------------------------------------
  // Internal
  // ---------------------------------------------------------------------------

  /**
   * Reconstruct the full webhook URL from server environment.
   * Twilio signature validation requires the exact URL including protocol and query string.
   */
  private function buildWebhookUrl(): string
  {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST']   ?? 'localhost';
    $uri    = $_SERVER['REQUEST_URI'] ?? '/';

    return "{$scheme}://{$host}{$uri}";
  }
}