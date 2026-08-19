<?php

declare(strict_types=1);

namespace TimeFrontiers\Sms\Dto;

final readonly class ProviderSendResult
{
  /** @param array<string, scalar|null> $meta */
  public function __construct(
    public string $provider,
    public string $reference,
    public string $sender,
    public string $feeAmount,
    public string $feeCurrency,
    public array $meta = [],
  ) {
    if (!\preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/D', $provider)) {
      throw new \InvalidArgumentException('Provider result contains an invalid provider.');
    }
    if ($reference === '' || \strlen($reference) > 128) {
      throw new \InvalidArgumentException('Provider result contains an invalid reference.');
    }
    if ($sender === '' || \mb_strlen($sender, 'UTF-8') > 16) {
      throw new \InvalidArgumentException('Provider result contains an invalid sender.');
    }
    if (!\preg_match('/^-?\d{1,10}(?:\.\d{1,8})?$/D', $feeAmount)) {
      throw new \InvalidArgumentException('Provider result contains an invalid exact fee.');
    }
    if (!\preg_match('/^[A-Z][A-Z0-9]{2,7}$/D', $feeCurrency)) {
      throw new \InvalidArgumentException('Provider result contains an invalid fee currency.');
    }
    if (\strlen((string) \json_encode($meta, JSON_THROW_ON_ERROR)) > 4096) {
      throw new \InvalidArgumentException('Provider result metadata exceeds the safe limit.');
    }
  }
}
