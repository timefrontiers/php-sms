<?php

declare(strict_types=1);

namespace TimeFrontiers\Sms\Driver;

use TimeFrontiers\Sms\Sms;
use AfricasTalking\SDK\AfricasTalking;

/**
 * AfricasTalking SMS driver for timefrontiers/php-sms.
 *
 * Requires `africastalking/africastalking`.
 * Credentials are injected via constructor from the configuration array.
 */
class AfricasTalkingDriver implements SmsDriverInterface
{
  private string $providerName = 'africastalking';

  /**
   * @param array{
   *   app_id: string,
   *   api_key: string,
   *   sender_id?: string
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
    $appId  = $this->config['app_id']  ?? throw new \RuntimeException('AfricasTalking app_id not configured.');
    $apiKey = $this->config['api_key'] ?? throw new \RuntimeException('AfricasTalking api_key not configured.');

    $at      = new AfricasTalking($appId, $apiKey);
    $service = $at->sms();

    // Priority: driver sender_id → per-message sender → global default_sender
    $from = $this->config['sender_id']
        ?? ($sms->sender() !== '' ? $sms->sender() : null)
        ?? null;

    $result = $service->send([
      'to'      => $sms->receiver(),
      'message' => $sms->message(),
      'from'    => $from,
    ]);

    // The SDK returns an object; normalise to array
    $result = json_decode(json_encode($result), true);

    if (strtolower($result['status'] ?? '') !== 'success') {
      throw new \RuntimeException(
        'AfricasTalking send failed: ' . ($result['data'] ?? $result['message'] ?? 'Unknown error')
      );
    }

    // SDK versions differ in nesting — normalise to SMSMessageData array
    $smsData    = $result['data']['SMSMessageData'] ?? $result['SMSMessageData'] ?? [];
    $atMessage  = $smsData['Message'] ?? null;  // AT puts error reason here
    $recipients = $smsData['Recipients']
               ?? $result['data']['Recipients']
               ?? $result['Recipients']
               ?? [];

    $first = $recipients[0] ?? null;

    if (!$first) {
      throw new \RuntimeException(
        'AfricasTalking delivery failed: ' . ($atMessage ?? ('Raw: ' . json_encode($result)))
      );
    }

    // Cost is returned as "CURRENCY AMOUNT" (e.g. "KES 0.50")
    $costData = explode(' ', $first['cost'] ?? '', 2);
    $cost     = (float) ($costData[1] ?? 0);
    $currency = $costData[0] ?? 'KES';

    return [
      $cost,
      $currency,
      $first['messageId'] ?? '',
      $from ?: ($first['from'] ?? '')
    ];
  }

  public function verifyDeliveryReport(array $payload): bool
  {
    // AfricasTalking delivery reports include at least `id` and `status`
    return !empty($payload['id']) && !empty($payload['status']);
  }

  public function parseDeliveryReport(array $payload): array
  {
    $reference = $payload['id'] ?? '';
    $rawStatus = strtolower($payload['status'] ?? '');

    $status = match ($rawStatus) {
      'success', 'delivered' => 'delivered',
      'failed', 'rejected'   => 'failed',
      default                => null
    };

    return [
      'reference' => $reference,
      'status'    => $status,
      'meta'      => $payload,   // entire payload for full audit
    ];
  }

  public function getProviderName(): string
  {
    return $this->providerName;
  }
}