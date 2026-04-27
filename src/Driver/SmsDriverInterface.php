<?php

declare(strict_types=1);

namespace TimeFrontiers\Sms\Driver;

use TimeFrontiers\Sms\Sms;

interface SmsDriverInterface
{
  /**
   * Send the SMS message through the provider.
   *
   * @param Sms $sms The message entity (receiver, message, sender already set).
   * @return array{0: float, 1: string, 2: string, 3: string}
   *         [cost, costCurrency, reference, senderUsed]
   * @throws \RuntimeException On communication / API failure.
   */
  public function send(Sms $sms): array;

  /**
   * Verify the authenticity of an incoming delivery report webhook.
   */
  public function verifyDeliveryReport(array $payload): bool;

  /**
   * Parse the delivery report payload into a normalised structure.
   *
   * @return array{reference: string, status: string, meta: array}
   *         - status: 'delivered' or 'failed'
   */
  public function parseDeliveryReport(array $payload): array;

  /**
   * Driver identifier matching the configuration key (e.g. 'twilio').
   */
  public function getProviderName(): string;
}