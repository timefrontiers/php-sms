<?php

declare(strict_types=1);

namespace TimeFrontiers\Sms\Driver;

use TimeFrontiers\Sms\Dto\ParsedDeliveryReport;

interface SmsStatusLookupInterface
{
  /** Return null when the provider still cannot prove the outcome. */
  public function lookupStatus(string $reference): ?ParsedDeliveryReport;
}
