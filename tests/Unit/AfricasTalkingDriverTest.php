<?php

declare(strict_types=1);

namespace TimeFrontiers\Sms\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TimeFrontiers\Sms\Config\SmsConfig;
use TimeFrontiers\Sms\Driver\AfricasTalkingDriver;
use TimeFrontiers\Sms\Dto\WebhookRequest;
use TimeFrontiers\Sms\Exception\ProviderRejectedException;
use TimeFrontiers\Sms\Result\SendResult;
use TimeFrontiers\Sms\Service\SmsService;
use TimeFrontiers\Sms\Sms;
use TimeFrontiers\Sms\Tests\Support\FakeDatabase;

final class AfricasTalkingDriverTest extends TestCase
{
  public function testAcceptedRecipientReturnsProviderReference(): void
  {
    $driver = $this->driver(['send_callable' => static fn(array $data): object => (object) [
      'status' => 'success',
      'data' => ['SMSMessageData' => ['Recipients' => [[
        'number' => '+2348024296777', 'statusCode' => 101, 'status' => 'Success',
        'messageId' => 'AT-123', 'cost' => 'KES 0.25000000',
      ]]]],
    ]]);

    $result = $driver->send($this->sms());

    self::assertSame('AT-123', $result->reference);
    self::assertSame('0.25000000', $result->feeAmount);
  }

  public function testRecipientRejectionAndNoneReferenceAreDefiniteFailures(): void
  {
    $driver = $this->driver(['send_callable' => static fn(array $data): object => (object) [
      'status' => 'success',
      'data' => ['SMSMessageData' => ['Recipients' => [[
        'number' => '+2348024296777', 'statusCode' => 403, 'status' => 'UserInBlacklist',
        'messageId' => 'None', 'cost' => 'KES 0.00000000',
      ]]]],
    ]]);

    try {
      $driver->send($this->sms());
      self::fail('A refused recipient must not be accepted.');
    } catch (ProviderRejectedException $exception) {
      self::assertSame('africastalking_recipient_403', $exception->providerCode);
    }

    $none = $this->driver(['send_callable' => static fn(array $data): object => (object) [
      'status' => 'success',
      'data' => ['SMSMessageData' => ['Recipients' => [[
        'number' => '+2348024296777', 'statusCode' => 101, 'status' => 'Success',
        'messageId' => 'None', 'cost' => 'KES 0.00000000',
      ]]]],
    ]]);
    try {
      $none->send($this->sms());
      self::fail('The None sentinel must not be stored as a provider reference.');
    } catch (ProviderRejectedException $exception) {
      self::assertSame('africastalking_no_message_id', $exception->providerCode);
    }
  }

  public function testRecipientRejectionPersistsFailedWithoutNoneReference(): void
  {
    $driver = $this->driver(['send_callable' => static fn(array $data): object => (object) [
      'status' => 'success',
      'data' => ['SMSMessageData' => ['Recipients' => [[
        'number' => '+2348024296777', 'statusCode' => 403, 'status' => 'UserInBlacklist',
        'messageId' => 'None', 'cost' => 'KES 0.00000000',
      ]]]],
    ]]);
    $database = new FakeDatabase();
    $service = new SmsService($database, SmsConfig::fromArray([
      'db_name' => 'messaging', 'default_driver' => 'africastalking',
      'drivers' => ['africastalking' => ['driver' => $driver, 'default_sender' => 'TEST']],
    ]));

    $result = $service->send([
      'receiver' => '+2348024296777', 'message' => 'hello', 'idempotency_key' => 'at-rejected',
    ]);

    self::assertSame(SendResult::REJECTED, $result->outcome);
    self::assertSame('failed', $result->sms?->status());
    self::assertNull($result->sms->reference());
  }

  public function testMixedResponseUsesTheIntendedRecipient(): void
  {
    $driver = $this->driver(['send_callable' => static fn(array $data): object => (object) [
      'status' => 'success',
      'data' => ['SMSMessageData' => ['Recipients' => [
        ['number' => '+12025550123', 'statusCode' => 101, 'status' => 'Success', 'messageId' => 'WRONG', 'cost' => 'USD 0.1'],
        ['number' => '+2348024296777', 'statusCode' => 402, 'status' => 'InsufficientBalance', 'messageId' => 'None', 'cost' => 'KES 0'],
      ]]],
    ]]);

    $this->expectException(ProviderRejectedException::class);
    $driver->send($this->sms());
  }

  public function testUnparseableFeeKeepsAcceptedReference(): void
  {
    $driver = $this->driver(['send_callable' => static fn(array $data): object => (object) [
      'status' => 'success',
      'data' => ['SMSMessageData' => ['Recipients' => [[
        'number' => '+2348024296777', 'statusCode' => 101, 'status' => 'Success',
        'messageId' => 'AT-FEE', 'cost' => 'KES 0.000001234',
      ]]]],
    ]]);

    $result = $driver->send($this->sms());
    self::assertSame('AT-FEE', $result->reference);
    self::assertSame('0.00000000', $result->feeAmount);
    self::assertTrue($result->meta['fee_parse_failed']);
  }

  public function testWebhookFailsClosedWithoutApplicationVerifier(): void
  {
    $driver = $this->driver([]);
    self::assertFalse($driver->verifyDeliveryReport($this->request()));
  }

  public function testConfiguredVerifierAuthenticatesAndParserAllowlistsMetadata(): void
  {
    $driver = $this->driver(['webhook_verifier' => static fn(WebhookRequest $request): bool => $request->header('x-gateway-auth') === 'trusted']);
    $request = new WebhookRequest('', ['X-Gateway-Auth' => 'trusted'], 'POST', 'https://example.com/webhooks/at', '192.0.2.1', [
      'id' => 'AT123', 'status' => 'Delivered', 'phoneNumber' => '+2348000000000',
      'failureReason' => 'none', 'credential' => 'must-not-be-stored',
    ]);

    self::assertTrue($driver->verifyDeliveryReport($request));
    $report = $driver->parseDeliveryReport($request);
    self::assertSame('delivered', $report->status);
    self::assertArrayNotHasKey('credential', $report->meta);
    self::assertArrayNotHasKey('phoneNumber', $report->meta);
  }

  /** @param array<string, mixed> $extra */
  private function driver(array $extra): AfricasTalkingDriver
  {
    return new AfricasTalkingDriver($extra + [
      'app_id' => 'sandbox', 'api_key' => 'secret',
      'send_callable' => static fn(array $data): object => (object) [],
    ]);
  }

  private function request(): WebhookRequest
  {
    return new WebhookRequest('', [], 'POST', 'https://example.com/webhooks/at', '192.0.2.1', ['id' => 'AT123', 'status' => 'Delivered']);
  }

  private function sms(): Sms
  {
    return Sms::hydrate([
      'id' => 1, 'code' => '828000000000001', 'status' => 'dispatching', 'provider' => 'africastalking',
      'idempotency_scope' => 'test', 'idempotency_key_hash' => str_repeat('x', 32), 'version' => 1,
      'message_id' => null, 'direction' => 'outbound', 'user' => 'SYSTEM', 'batch' => null,
      'sender' => 'TEST', 'receiver' => '+2348024296777', 'message' => 'hello',
      '_message_pages' => 1, 'message_encoding' => 'GSM-7', 'fees_currency' => null,
      'fees' => '0.00000000', 'reference' => null, 'meta' => null, 'last_error_code' => null,
      'dispatch_started_at' => null, 'provider_accepted_at' => null, 'reconciled_at' => null,
      'content_expires_at' => null, '_created' => '', '_updated' => '',
    ], new FakeDatabase());
  }
}
