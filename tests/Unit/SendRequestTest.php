<?php

declare(strict_types=1);

namespace TimeFrontiers\Sms\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TimeFrontiers\Sms\Config\SmsConfig;
use TimeFrontiers\Sms\Dto\SendRequest;
use TimeFrontiers\Sms\Exception\SendValidationException;

final class SendRequestTest extends TestCase
{
  public function testMissingInputReturnsValidationErrorsWithoutTypedAssignment(): void
  {
    try {
      SendRequest::fromArray([], $this->config());
      self::fail('Expected validation failure.');
    } catch (SendValidationException $exception) {
      self::assertArrayHasKey('receiver', $exception->errors());
      self::assertArrayHasKey('message', $exception->errors());
    }
  }

  public function testWrongTypesAndIdentifierBoundsAreRejected(): void
  {
    try {
      SendRequest::fromArray([
        'receiver' => 2348000000000,
        'message' => [],
        'user' => str_repeat('U', 16),
        'batch' => str_repeat('B', 16),
        'message_id' => '7',
      ], $this->config());
      self::fail('Expected validation failure.');
    } catch (SendValidationException $exception) {
      foreach (['receiver', 'message', 'user', 'batch', 'message_id'] as $field) {
        self::assertArrayHasKey($field, $exception->errors());
      }
    }
  }

  public function testReceiverAndMessageAreNormalizedBeforePersistence(): void
  {
    $request = SendRequest::fromArray([
      'receiver' => '+234 802 429 6777',
      'message' => "hello\r\nworld",
      'idempotency_key' => 'stable-key',
    ], $this->config());

    self::assertSame('+2348024296777', $request->receiver);
    self::assertSame("hello\nworld", $request->message);
    self::assertSame(hash('sha256', 'stable-key', true), $request->idempotencyKeyHash);
  }

  public function testTransportSegmentLimitReplacesLegacyCharacterLimit(): void
  {
    $request = SendRequest::fromArray([
      'receiver' => '+2348024296777',
      'message' => str_repeat('a', 251),
    ], $this->config());
    self::assertSame(2, $request->messageSegments);
  }

  private function config(): SmsConfig
  {
    return SmsConfig::fromArray([
      'db_name' => 'messaging',
      'default_driver' => 'fake',
      'drivers' => ['fake' => new \stdClass()],
      'default_sender' => 'TEST',
    ]);
  }
}
