<?php

declare(strict_types=1);

namespace TimeFrontiers\Sms\Exception;

final class SendValidationException extends \InvalidArgumentException
{
  /** @param array<string, list<string>> $errors */
  public function __construct(private readonly array $errors)
  {
    parent::__construct('SMS input validation failed.');
  }

  /** @return array<string, list<string>> */
  public function errors(): array
  {
    return $this->errors;
  }
}
