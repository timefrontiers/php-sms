<?php

declare(strict_types=1);

namespace TimeFrontiers\Sms\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TimeFrontiers\Sms\Config\SmsConfig;
use TimeFrontiers\Sms\Dto\WebhookRequest;
use TimeFrontiers\Sms\ErrorCode;
use TimeFrontiers\Sms\Exception\ProviderOutcomeUnknownException;
use TimeFrontiers\Sms\Exception\ProviderRejectedException;
use TimeFrontiers\Sms\Result\DeliveryReportResult;
use TimeFrontiers\Sms\Result\SendResult;
use TimeFrontiers\Sms\Service\SmsService;
use TimeFrontiers\Sms\Tests\Support\FakeDatabase;
use TimeFrontiers\Sms\Tests\Support\FakeDriver;

final class SmsServiceTest extends TestCase
{
  public function testMissingInputIsStableValidationFailureWithoutDatabaseIo(): void
  {
    [$service] = $this->service();
    $result = $service->send([]);
    self::assertSame(SendResult::VALIDATION_FAILED, $result->outcome);
    self::assertArrayHasKey('receiver', $result->errors);
  }

  public function testSendRefusesCallerOwnedTransactionWithoutPoisoningIt(): void
  {
    [$service, $database, $driver] = $this->service();
    self::assertTrue($database->beginTransaction());

    $result = $service->send($this->request());

    self::assertSame(SendResult::REJECTED, $result->outcome);
    self::assertArrayHasKey(ErrorCode::CONFIGURATION_INVALID, $result->errors);
    self::assertSame(0, $driver->sendCount);
    self::assertTrue($database->commit());
  }

  public function testAcceptedSendPersistsProviderExactFeeAndOneAttempt(): void
  {
    [$service, $database, $driver] = $this->service();
    $result = $service->send($this->request());

    self::assertSame(SendResult::ACCEPTED, $result->outcome);
    self::assertSame(1, $driver->sendCount);
    self::assertNotNull($result->sms);
    self::assertSame('fake', $result->sms->provider());
    self::assertSame('0.12500000', $result->sms->feesExact());
    self::assertCount(1, $database->smsRows());
  }

  public function testIdempotencyReplayDoesNotCallProviderAgain(): void
  {
    [$service, , $driver] = $this->service();
    self::assertSame(SendResult::ACCEPTED, $service->send($this->request())->outcome);
    $replay = $service->send($this->request());

    self::assertSame(SendResult::REPLAYED, $replay->outcome);
    self::assertSame(1, $driver->sendCount);
    self::assertTrue($replay->succeeded());
  }

  public function testSameIdempotencyKeyWithDifferentPayloadIsRejected(): void
  {
    [$service, , $driver] = $this->service();
    $service->send($this->request());
    $changed = $this->request();
    $changed['message'] = 'different';
    $result = $service->send($changed);

    self::assertSame(SendResult::REJECTED, $result->outcome);
    self::assertArrayHasKey(ErrorCode::IDEMPOTENCY_CONFLICT, $result->errors);
    self::assertSame(1, $driver->sendCount);
  }

  public function testProviderTimeoutBecomesUnknownAndNeverAutoRetries(): void
  {
    [$service, , $driver] = $this->service();
    $driver->sendException = new ProviderOutcomeUnknownException('timeout');
    $unknown = $service->send($this->request());
    self::assertSame(SendResult::UNKNOWN, $unknown->outcome);
    self::assertSame('unknown', $unknown->sms?->status());

    $replay = $service->send($this->request());
    self::assertSame(SendResult::UNKNOWN, $replay->outcome);
    self::assertSame(1, $driver->sendCount);
  }

  public function testAcceptedProviderResultWithFailedPersistenceIsAmbiguous(): void
  {
    [$service, $database] = $this->service();
    $database->failNext("SET `status` = 'sent'");
    $result = $service->send($this->request());

    self::assertSame(SendResult::UNKNOWN, $result->outcome);
    self::assertArrayHasKey(ErrorCode::OUTCOME_UNKNOWN, $result->errors);
    self::assertSame('dispatching', $result->sms?->status());
  }

  public function testPendingInsertAndDispatchClaimFalsePathsDoNotCallProvider(): void
  {
    [$service, $database, $driver] = $this->service();
    $database->failNext('INSERT INTO `messaging`.`sms`');
    $insertFailure = $service->send($this->request());
    self::assertSame(SendResult::INFRASTRUCTURE_FAILED, $insertFailure->outcome);
    self::assertSame(0, $driver->sendCount);

    [$service, $database, $driver] = $this->service();
    $database->failNext("SET `status` = 'dispatching'");
    $claimFailure = $service->send($this->request());
    self::assertSame(SendResult::INFRASTRUCTURE_FAILED, $claimFailure->outcome);
    self::assertSame(0, $driver->sendCount);
  }

  public function testFailedStateUpdateAndWebhookUpdateFalsePathsAreReported(): void
  {
    [$service, $database, $driver] = $this->service();
    $driver->sendException = new ProviderRejectedException('definite_rejection');
    $database->failNext('SET `status` = ?, `last_error_code` = ?');
    $failureUpdate = $service->send($this->request());
    self::assertSame(SendResult::INFRASTRUCTURE_FAILED, $failureUpdate->outcome);

    [$service, $database] = $this->service();
    $sent = $service->send($this->request());
    $database->failNext('SET `status` = ?, `meta` = ?');
    $webhookUpdate = $service->processDeliveryReport('fake', new WebhookRequest('{}', [], 'POST', 'https://example.com/hook', '192.0.2.1'));
    self::assertSame(DeliveryReportResult::INFRASTRUCTURE_FAILED, $webhookUpdate->outcome);
    self::assertSame('sent', $database->smsRows()[(int) $sent->sms?->id()]['status']);
  }

  public function testRawProviderThrowableIsNotStoredOrReturned(): void
  {
    [$service, $database, $driver] = $this->service();
    $secret = 'token=super-secret phone=+2348024296777';
    $driver->sendException = new ProviderOutcomeUnknownException('timeout', new \RuntimeException($secret));
    $result = $service->send($this->request());

    self::assertStringNotContainsString($secret, json_encode($result->errors, JSON_THROW_ON_ERROR));
    self::assertStringNotContainsString($secret, serialize($database->smsRows()));
  }

  public function testAuthenticatedDeliveryIsScopedVersionedAndReplaySafe(): void
  {
    [$service] = $this->service();
    $sent = $service->send($this->request());
    self::assertSame('sent', $sent->sms?->status());
    $request = new WebhookRequest('{}', ['content-type' => 'application/json'], 'POST', 'https://example.com/hook', '192.0.2.1');

    $updated = $service->processDeliveryReport('fake', $request);
    self::assertSame(DeliveryReportResult::UPDATED, $updated->outcome);
    self::assertSame('delivered', $updated->sms?->status());

    $duplicate = $service->processDeliveryReport('fake', $request);
    self::assertSame(DeliveryReportResult::DUPLICATE, $duplicate->outcome);

    $driver = $service->driverForRecovery('fake');
    self::assertInstanceOf(FakeDriver::class, $driver);
    $driver->deliveryReport = new \TimeFrontiers\Sms\Dto\ParsedDeliveryReport('fake', 'REF-1', 'failed', 'EV-2');
    $lateFailure = $service->processDeliveryReport('fake', $request);
    self::assertSame(DeliveryReportResult::IGNORED, $lateFailure->outcome);
    self::assertSame('delivered', $lateFailure->sms?->status());
  }

  public function testProviderReferenceLookupIsScoped(): void
  {
    $database = new FakeDatabase();
    $fake = new FakeDriver('fake');
    $other = new FakeDriver('other');
    $other->sendResult = new \TimeFrontiers\Sms\Dto\ProviderSendResult('other', 'REF-1', 'TEST', '0.10000000', 'USD');
    $config = SmsConfig::fromArray([
      'db_name' => 'messaging', 'default_driver' => 'fake', 'default_sender' => 'TEST',
      'drivers' => [
        'fake' => ['driver' => $fake, 'default_sender' => 'TEST'],
        'other' => ['driver' => $other, 'default_sender' => 'TEST'],
      ],
    ]);
    $service = new SmsService($database, $config);
    $service->send($this->request(), 'fake');
    $second = $this->request();
    $second['idempotency_key'] = 'other-key';
    $service->send($second, 'other');

    self::assertSame('fake', $service->findProviderReference('fake', 'REF-1')?->provider());
    self::assertSame('other', $service->findProviderReference('other', 'REF-1')?->provider());
  }

  public function testUnauthenticatedWebhookFailsClosed(): void
  {
    [$service, , $driver] = $this->service();
    $driver->webhookValid = false;
    $result = $service->processDeliveryReport('fake', []);
    self::assertSame(DeliveryReportResult::INVALID, $result->outcome);
    self::assertArrayHasKey(ErrorCode::WEBHOOK_UNAUTHENTICATED, $result->errors);
  }

  public function testMalformedStoredMetadataDoesNotGetSilentlyOverwritten(): void
  {
    [$service, $database] = $this->service();
    $sent = $service->send($this->request());
    $id = (int) $sent->sms?->id();
    $row = $database->smsRows()[$id];
    $row['meta'] = '42';
    $database->replaceSmsRow($id, $row);

    $result = $service->processDeliveryReport('fake', new WebhookRequest('{}', [], 'POST', 'https://example.com/hook', '192.0.2.1'));
    self::assertSame(DeliveryReportResult::INFRASTRUCTURE_FAILED, $result->outcome);
    self::assertArrayHasKey(ErrorCode::CORRUPT_METADATA, $result->errors);
  }

  public function testOversizedMergedMetadataKeepsValidWebhookOutcome(): void
  {
    [$service, $database] = $this->service();
    $sent = $service->send($this->request());
    $id = (int) $sent->sms?->id();
    $row = $database->smsRows()[$id];
    $row['meta'] = json_encode(['existing' => str_repeat('x', 4000)], JSON_THROW_ON_ERROR);
    $database->replaceSmsRow($id, $row);
    $driver = $service->driverForRecovery('fake');
    self::assertInstanceOf(FakeDriver::class, $driver);
    $driver->deliveryReport = new \TimeFrontiers\Sms\Dto\ParsedDeliveryReport(
      'fake', 'REF-1', 'delivered', 'EV-LARGE', ['provider_status' => str_repeat('y', 200)]
    );

    $result = $service->processDeliveryReport('fake', new WebhookRequest('{}', [], 'POST', 'https://example.com/hook', '192.0.2.1'));

    self::assertSame(DeliveryReportResult::UPDATED, $result->outcome);
    self::assertSame(str_repeat('y', 200), $result->sms?->meta()['provider_status'] ?? null);
  }

  public function testFailedDeliveryCanBeCorrectedAndKeepsRecordedErrorCode(): void
  {
    [$service, $database] = $this->service();
    $sent = $service->send($this->request());
    $id = (int) $sent->sms?->id();
    $row = $database->smsRows()[$id];
    $row['status'] = 'failed';
    $row['last_error_code'] = 'provider_delivery_failed';
    $database->replaceSmsRow($id, $row);

    $result = $service->processDeliveryReport('fake', new WebhookRequest('{}', [], 'POST', 'https://example.com/hook', '192.0.2.1'));

    self::assertSame(DeliveryReportResult::UPDATED, $result->outcome);
    self::assertSame('delivered', $result->sms?->status());
    self::assertSame('provider_delivery_failed', $result->sms->lastErrorCode());
  }

  public function testRetentionPurgeDefaultsToDryRunAndUsesVersionedRedaction(): void
  {
    [$service, $database] = $this->service(retentionDays: 1);
    $sent = $service->send($this->request());
    $id = (int) $sent->sms?->id();
    $row = $database->smsRows()[$id];
    $row['content_expires_at'] = '2020-01-01 00:00:00';
    $database->replaceSmsRow($id, $row);

    $dryRun = $service->purgeExpiredContent();
    self::assertTrue($dryRun->dryRun);
    self::assertSame('would_redact', $dryRun->items[0]['action']);
    self::assertSame('hello', $database->smsRows()[$id]['message']);

    $applied = $service->purgeExpiredContent(false);
    self::assertSame('redacted', $applied->items[0]['action']);
    self::assertSame('', $database->smsRows()[$id]['message']);
    self::assertSame('', $database->smsRows()[$id]['receiver']);

    $replay = $service->send($this->request());
    self::assertSame(SendResult::REPLAYED, $replay->outcome);
  }

  public function testMissingCodeBackfillIsBoundedAndDryRunByDefault(): void
  {
    [$service, $database] = $this->service();
    $sent = $service->send($this->request());
    $id = (int) $sent->sms?->id();
    $row = $database->smsRows()[$id];
    $row['code'] = null;
    $database->replaceSmsRow($id, $row);

    self::assertSame('would_assign', $service->backfillMissingCodes()->items[0]['action']);
    $applied = $service->backfillMissingCodes(false);
    self::assertSame('assigned', $applied->items[0]['action']);
    self::assertMatchesRegularExpression('/^828[0-9]{12}$/D', $database->smsRows()[$id]['code']);
  }

  /** @return array{SmsService, FakeDatabase, FakeDriver} */
  private function service(?int $retentionDays = null): array
  {
    $database = new FakeDatabase();
    $driver = new FakeDriver();
    $options = [
      'db_name' => 'messaging', 'default_driver' => 'fake', 'default_sender' => 'TEST',
      'drivers' => ['fake' => ['driver' => $driver, 'default_sender' => 'TEST']],
    ];
    if ($retentionDays !== null) $options['content_retention_days'] = $retentionDays;
    $config = SmsConfig::fromArray($options);
    return [new SmsService($database, $config), $database, $driver];
  }

  /** @return array<string, mixed> */
  private function request(): array
  {
    return [
      'receiver' => '+2348024296777', 'message' => 'hello',
      'idempotency_key' => 'stable-key',
    ];
  }
}
