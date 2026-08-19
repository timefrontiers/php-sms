<?php

declare(strict_types=1);

namespace TimeFrontiers\Sms\Result;

final readonly class BackfillReport
{
  /**
   * @param list<array{id: int, action: string, code?: string}> $items
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
