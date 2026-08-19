<?php

declare(strict_types=1);

namespace TimeFrontiers\Sms\Tests\Support;

use TimeFrontiers\Sms\Driver\SmsDriverInterface;
use TimeFrontiers\Sms\Dto\ParsedDeliveryReport;
use TimeFrontiers\Sms\Dto\ProviderSendResult;
use TimeFrontiers\Sms\Dto\WebhookRequest;
use TimeFrontiers\Sms\Sms;

final class FakeDriver implements SmsDriverInterface
{
  public int $sendCount = 0;
  public bool $webhookValid = true;
  public ?\Throwable $sendException = null;
  public ProviderSendResult $sendResult;
  public ParsedDeliveryReport $deliveryReport;

  public function __construct(private readonly string $provider = 'fake')
  {
    $this->sendResult = new ProviderSendResult($provider, 'REF-1', 'TEST', '0.12500000', 'USD');
    $this->deliveryReport = new ParsedDeliveryReport($provider, 'REF-1', 'delivered', 'EV-1', ['provider_status' => 'delivered']);
  }

  public function send(Sms $sms): ProviderSendResult
  {
    ++$this->sendCount;
    if ($this->sendException !== null) throw $this->sendException;
    return $this->sendResult;
  }

  public function verifyDeliveryReport(WebhookRequest $request): bool
  {
    return $this->webhookValid;
  }

  public function parseDeliveryReport(WebhookRequest $request): ParsedDeliveryReport
  {
    return $this->deliveryReport;
  }

  public function getProviderName(): string
  {
    return $this->provider;
  }
}
