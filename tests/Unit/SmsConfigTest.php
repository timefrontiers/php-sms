<?php

declare(strict_types=1);

namespace TimeFrontiers\Sms\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TimeFrontiers\Sms\Config\SmsConfig;

final class SmsConfigTest extends TestCase
{
  public function testConfigurationAndSenderPolicyAreValidated(): void
  {
    $config = SmsConfig::fromArray([
      'db_name' => 'messaging',
      'default_driver' => 'fake',
      'drivers' => [
        'fake' => ['default_sender' => 'DEFAULT', 'allowed_senders' => ['ALLOWED']],
      ],
    ]);

    $driverConfig = $config->driver('fake');
    if (!is_array($driverConfig)) self::fail('Expected array configuration.');
    self::assertSame('DEFAULT', $config->resolveSender($driverConfig, null));
    self::assertSame('ALLOWED', $config->resolveSender($driverConfig, 'ALLOWED'));

    $this->expectException(\InvalidArgumentException::class);
    $config->resolveSender($driverConfig, 'ARBITRARY');
  }

  public function testEmptyDriverConfigurationFailsAtBootstrap(): void
  {
    $this->expectException(\InvalidArgumentException::class);
    SmsConfig::fromArray(['db_name' => 'messaging', 'drivers' => []]);
  }
}
