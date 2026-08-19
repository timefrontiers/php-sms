<?php

declare(strict_types=1);

namespace TimeFrontiers\Sms\Result;

use TimeFrontiers\Sms\Sms;

final readonly class SendResult
{
  public const ACCEPTED = 'accepted';
  public const REPLAYED = 'replayed';
  public const IN_FLIGHT = 'in_flight';
  public const VALIDATION_FAILED = 'validation_failed';
  public const REJECTED = 'rejected';
  public const UNKNOWN = 'unknown';
  public const INFRASTRUCTURE_FAILED = 'infrastructure_failed';

  /**
   * @param array<string, list<string>> $errors
   */
  public function __construct(
    public string $outcome,
    public ?Sms $sms = null,
    public array $errors = [],
  ) {
    if (!\in_array($outcome, [self::ACCEPTED, self::REPLAYED, self::IN_FLIGHT, self::VALIDATION_FAILED, self::REJECTED, self::UNKNOWN, self::INFRASTRUCTURE_FAILED], true)) {
      throw new \InvalidArgumentException('Unknown SMS send result.');
    }
  }

  public function succeeded(): bool
  {
    return \in_array($this->outcome, [self::ACCEPTED, self::REPLAYED], true)
      && $this->sms !== null
      && \in_array($this->sms->status(), [Sms::STATUS_SENT, Sms::STATUS_DELIVERED], true);
  }
}
