<?php

declare(strict_types=1);

namespace TimeFrontiers\Sms\Driver;

use TimeFrontiers\Sms\Dto\ParsedDeliveryReport;
use TimeFrontiers\Sms\Dto\ProviderSendResult;
use TimeFrontiers\Sms\Dto\WebhookRequest;
use TimeFrontiers\Sms\Sms;

interface SmsDriverInterface
{
  /**
   * Send the SMS message through the provider.
   *
   * @param Sms $sms The message entity (receiver, message, sender already set).
   * @throws \TimeFrontiers\Sms\Exception\ProviderRejectedException
   * @throws \TimeFrontiers\Sms\Exception\ProviderOutcomeUnknownException
   */
  public function send(Sms $sms): ProviderSendResult;

  /**
   * Verify the authenticity of an incoming delivery report webhook.
   */
  public function verifyDeliveryReport(WebhookRequest $request): bool;

  /**
   * Parse the delivery report payload into a normalised structure.
   *
   * Authentication must be completed before this method is called.
   */
  public function parseDeliveryReport(WebhookRequest $request): ParsedDeliveryReport;

  /**
   * Driver identifier matching the configuration key (e.g. 'twilio').
   */
  public function getProviderName(): string;
}
