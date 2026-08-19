<?php

declare(strict_types=1);

namespace TimeFrontiers\Sms\Encoding;

final readonly class SegmentCount
{
  public function __construct(
    public string $message,
    public string $encoding,
    public int $units,
    public int $segments,
  ) {
    if (!\in_array($encoding, ['GSM-7', 'UCS-2'], true) || $units < 0 || $segments < 1) {
      throw new \InvalidArgumentException('Invalid SMS segment count.');
    }
  }
}
