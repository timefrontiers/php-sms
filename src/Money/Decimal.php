<?php

declare(strict_types=1);

namespace TimeFrontiers\Sms\Money;

final class Decimal
{
  public static function normalize(string|int|float|null $value, int $scale = 8): string
  {
    if ($value === null || $value === '') {
      return '0.' . \str_repeat('0', $scale);
    }

    $value = (string) $value;
    if (!\preg_match('/^(-?)(\d{1,10})(?:\.(\d{1,8}))?$/D', $value, $matches)) {
      throw new \InvalidArgumentException('Fee must be an exact decimal with at most 8 fractional digits.');
    }

    $fraction = $matches[3] ?? '';
    $integer = \ltrim($matches[2], '0');
    return $matches[1] . ($integer === '' ? '0' : $integer) . ($scale > 0 ? '.' . \str_pad($fraction, $scale, '0') : '');
  }

  private function __construct()
  {
  }
}
