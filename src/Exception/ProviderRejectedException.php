<?php

declare(strict_types=1);

namespace TimeFrontiers\Sms\Exception;

final class ProviderRejectedException extends \RuntimeException
{
  public function __construct(public readonly string $providerCode = 'provider_rejected')
  {
    parent::__construct('The SMS provider rejected the request.');
  }
}
