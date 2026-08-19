<?php

declare(strict_types=1);

namespace TimeFrontiers\Sms\Result;

final readonly class PurgeReport
{
  /**
   * @param list<array{id: int, code: string, action: string}> $items
   * @param list<string> $errors
   */
  public function __construct(
    public bool $dryRun,
    public array $items,
    public array $errors = [],
  ) {
  }

  public function succeeded(): bool
  {
    return $this->errors === [];
  }
}
