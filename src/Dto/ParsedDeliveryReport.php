<?php

declare(strict_types=1);

namespace TimeFrontiers\Sms\Dto;

final readonly class ParsedDeliveryReport
{
  /** @param array<string, scalar|null> $meta */
  public function __construct(
    public string $provider,
    public string $reference,
    public string $status,
    public ?string $eventId,
    public array $meta = [],
  ) {
    if (!\preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/D', $provider)) {
      throw new \InvalidArgumentException('Delivery report provider is invalid.');
    }
    if ($reference === '' || \strlen($reference) > 128) {
      throw new \InvalidArgumentException('Delivery report reference is invalid.');
    }
    if (!\in_array($status, ['sent', 'failed', 'delivered'], true)) {
      throw new \InvalidArgumentException('Delivery report status is unsupported.');
    }
    if ($eventId !== null && ($eventId === '' || \strlen($eventId) > 128)) {
      throw new \InvalidArgumentException('Delivery report event identifier is invalid.');
    }
    if (\strlen((string) \json_encode($meta, JSON_THROW_ON_ERROR)) > 4096) {
      throw new \InvalidArgumentException('Delivery report metadata exceeds the safe limit.');
    }
  }
}
