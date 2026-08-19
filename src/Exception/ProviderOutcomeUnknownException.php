<?php

declare(strict_types=1);

namespace TimeFrontiers\Sms\Exception;

final class ProviderOutcomeUnknownException extends \RuntimeException
{
  public function __construct(public readonly string $providerCode = 'provider_outcome_unknown', ?\Throwable $previous = null)
  {
    parent::__construct('The SMS provider outcome is unknown and must be reconciled.', 0, $previous);
  }
}
