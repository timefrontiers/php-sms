<?php

declare(strict_types=1);

namespace TimeFrontiers\Sms\Result;

use TimeFrontiers\Sms\Sms;

final readonly class DeliveryReportResult
{
  public const UPDATED = 'updated';
  public const DUPLICATE = 'duplicate';
  public const IGNORED = 'ignored';
  public const NOT_FOUND = 'not_found';
  public const INVALID = 'invalid';
  public const INFRASTRUCTURE_FAILED = 'infrastructure_failed';

  /** @param array<string, list<string>> $errors */
  public function __construct(
    public string $outcome,
    public ?Sms $sms = null,
    public array $errors = [],
  ) {
    if (!\in_array($outcome, [self::UPDATED, self::DUPLICATE, self::IGNORED, self::NOT_FOUND, self::INVALID, self::INFRASTRUCTURE_FAILED], true)) {
      throw new \InvalidArgumentException('Unknown SMS delivery report result.');
    }
  }
}
