<?php

declare(strict_types=1);

namespace TimeFrontiers\Sms\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TimeFrontiers\Sms\Money\Decimal;

final class DecimalTest extends TestCase
{
  public function testExactDecimalNormalizationNeverUsesBinaryFloatFormatting(): void
  {
    self::assertSame('0.10000000', Decimal::normalize('0.1'));
    self::assertSame('-0.00750000', Decimal::normalize('-0.0075'));
    self::assertSame('1234567890.12345678', Decimal::normalize('1234567890.12345678'));
  }

  public function testUnsupportedPrecisionIsRejected(): void
  {
    $this->expectException(\InvalidArgumentException::class);
    Decimal::normalize('0.123456789');
  }
}
