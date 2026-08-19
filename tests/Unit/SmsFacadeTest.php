<?php

declare(strict_types=1);

namespace TimeFrontiers\Sms\Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use TimeFrontiers\AccessRank;
use TimeFrontiers\Sms\Sms;
use TimeFrontiers\Sms\Tests\Support\FakeDatabase;
use TimeFrontiers\Sms\Tests\Support\FakeDriver;

final class SmsFacadeTest extends TestCase
{
  #[RunInSeparateProcess]
  #[PreserveGlobalState(false)]
  public function testCompatibilityErrorsResetBetweenOperations(): void
  {
    $driver = new FakeDriver();
    $database = new FakeDatabase();
    Sms::configure([
      'db_name' => 'messaging', 'default_driver' => 'fake', 'default_sender' => 'TEST',
      'drivers' => ['fake' => ['driver' => $driver, 'default_sender' => 'TEST']],
    ], $database);

    self::assertFalse(Sms::send([]));
    self::assertNotSame([], Sms::lastErrors());
    foreach (Sms::lastErrors()['send'] as $error) {
      self::assertSame(AccessRank::GUEST->value, $error[0]);
      self::assertSame(828100, $error[1]);
      self::assertStringContainsString('[SMS_VALIDATION_FAILED]', $error[2]);
    }

    self::assertInstanceOf(Sms::class, Sms::send([
      'receiver' => '+2348024296777', 'message' => 'hello', 'idempotency_key' => 'facade-key',
    ]));
    self::assertSame([], Sms::lastErrors());

    $database->failNext('WHERE `code` = ?');
    self::assertNull(Sms::findByCode('828000000000001'));
    self::assertNotSame([], Sms::lastErrors());
  }

  #[RunInSeparateProcess]
  #[PreserveGlobalState(false)]
  public function testConfigurationCannotMutateAfterBootstrap(): void
  {
    $options = [
      'db_name' => 'messaging', 'default_driver' => 'fake', 'default_sender' => 'TEST',
      'drivers' => ['fake' => ['driver' => new FakeDriver(), 'default_sender' => 'TEST']],
    ];
    Sms::configure($options, new FakeDatabase());

    $this->expectException(\LogicException::class);
    Sms::configure($options, new FakeDatabase());
  }
}
