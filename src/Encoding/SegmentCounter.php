<?php

declare(strict_types=1);

namespace TimeFrontiers\Sms\Encoding;

final class SegmentCounter
{
  private const GSM_DEFAULT = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";
  private const GSM_EXTENSION = "^{}\\[~]|€\f";

  public function count(string $message): SegmentCount
  {
    $message = \str_replace(["\r\n", "\r"], "\n", $message);
    if (!\mb_check_encoding($message, 'UTF-8')) {
      throw new \InvalidArgumentException('SMS message must be valid UTF-8.');
    }

    $default = \array_fill_keys($this->characters(self::GSM_DEFAULT), true);
    $extension = \array_fill_keys($this->characters(self::GSM_EXTENSION), true);
    $septets = 0;

    foreach ($this->characters($message) as $character) {
      if (isset($default[$character])) {
        ++$septets;
        continue;
      }
      if (isset($extension[$character])) {
        $septets += 2;
        continue;
      }

      $bytes = \mb_convert_encoding($message, 'UTF-16BE', 'UTF-8');
      $units = (int) (\strlen($bytes) / 2);
      return new SegmentCount($message, 'UCS-2', $units, $this->ucs2Segments($message, $units));
    }

    if ($septets <= 160) {
      return new SegmentCount($message, 'GSM-7', $septets, 1);
    }

    // An extension character is encoded as an escape pair. The pair cannot
    // straddle a concatenated-message boundary, so pack characters rather
    // than dividing only the aggregate septet count.
    $segments = 1;
    $used = 0;
    foreach ($this->characters($message) as $character) {
      $width = isset($extension[$character]) ? 2 : 1;
      if ($used + $width > 153) {
        ++$segments;
        $used = 0;
      }
      $used += $width;
    }

    return new SegmentCount($message, 'GSM-7', $septets, $segments);
  }

  /** @return list<string> */
  private function characters(string $value): array
  {
    if ($value === '') {
      return [];
    }
    $characters = \preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
    if ($characters === false) {
      throw new \InvalidArgumentException('SMS message must be valid UTF-8.');
    }
    return $characters;
  }

  private function ucs2Segments(string $message, int $units): int
  {
    if ($units <= 70) {
      return 1;
    }

    $segments = 1;
    $used = 0;
    foreach ($this->characters($message) as $character) {
      $width = (int) (\strlen(\mb_convert_encoding($character, 'UTF-16BE', 'UTF-8')) / 2);
      if ($used + $width > 67) {
        ++$segments;
        $used = 0;
      }
      $used += $width;
    }
    return $segments;
  }
}
