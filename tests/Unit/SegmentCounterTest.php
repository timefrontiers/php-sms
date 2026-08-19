<?php

declare(strict_types=1);

namespace TimeFrontiers\Sms\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TimeFrontiers\Sms\Encoding\SegmentCounter;

final class SegmentCounterTest extends TestCase
{
  #[DataProvider('vectors')]
  public function testBoundaries(string $message, string $encoding, int $units, int $segments): void
  {
    $result = (new SegmentCounter())->count($message);
    self::assertSame($encoding, $result->encoding);
    self::assertSame($units, $result->units);
    self::assertSame($segments, $result->segments);
  }

  /** @return iterable<string, array{string, string, int, int}> */
  public static function vectors(): iterable
  {
    yield 'gsm 160' => [str_repeat('a', 160), 'GSM-7', 160, 1];
    yield 'gsm 161' => [str_repeat('a', 161), 'GSM-7', 161, 2];
    yield 'extension 80' => [str_repeat('^', 80), 'GSM-7', 160, 1];
    yield 'extension 81' => [str_repeat('^', 81), 'GSM-7', 162, 2];
    yield 'extension pair does not straddle multipart boundary' => [str_repeat('a', 152) . '^' . str_repeat('a', 152), 'GSM-7', 306, 3];
    yield 'euro extension' => ['€', 'GSM-7', 2, 1];
    yield 'ucs2 70' => [str_repeat('漢', 70), 'UCS-2', 70, 1];
    yield 'ucs2 71' => [str_repeat('漢', 71), 'UCS-2', 71, 2];
    yield 'non bmp surrogate pair' => ['😀', 'UCS-2', 2, 1];
    yield 'surrogate pair does not straddle multipart boundary' => [str_repeat('漢', 66) . '😀' . str_repeat('漢', 66), 'UCS-2', 134, 3];
    yield 'newline normalization' => ["a\r\nb\rc", 'GSM-7', 5, 1];
  }
}
