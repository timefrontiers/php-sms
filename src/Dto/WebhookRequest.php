<?php

declare(strict_types=1);

namespace TimeFrontiers\Sms\Dto;

final readonly class WebhookRequest
{
  /**
   * @param array<array-key, mixed> $headers
   * @param array<string, mixed> $parameters
   */
  public function __construct(
    public string $rawBody,
    array $headers,
    string $method,
    public string $canonicalUrl,
    public string $trustedRemoteIp,
    public array $parameters = [],
  ) {
    $method = \strtoupper($method);
    if (!\in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
      throw new \InvalidArgumentException('Webhook method is not supported.');
    }
    $url = \parse_url($canonicalUrl);
    if (!\is_array($url) || !\in_array($url['scheme'] ?? null, ['http', 'https'], true) || empty($url['host']) || isset($url['user']) || isset($url['pass']) || isset($url['fragment'])) {
      throw new \InvalidArgumentException('Webhook canonical URL must be an absolute HTTP(S) URL.');
    }

    $normalized = [];
    foreach ($headers as $name => $value) {
      if (!\is_string($name) || (!\is_string($value) && !\is_array($value))) {
        throw new \InvalidArgumentException('Webhook headers must contain string values.');
      }
      if (\is_array($value)) {
        foreach ($value as $entry) {
          if (!\is_string($entry)) {
            throw new \InvalidArgumentException('Webhook headers must contain string values.');
          }
        }
        $value = \implode(',', $value);
      }
      $normalized[\strtolower($name)] = $value;
    }

    $this->headers = $normalized;
    $this->method = $method;
  }

  /** @var array<string, string> */
  public array $headers;
  public string $method;

  public function header(string $name): ?string
  {
    return $this->headers[\strtolower($name)] ?? null;
  }

  public function contentType(): string
  {
    return \strtolower(\trim(\explode(';', $this->header('content-type') ?? '')[0]));
  }

  /** @param array<string, mixed> $payload */
  public static function legacy(array $payload, string $canonicalUrl = 'https://invalid.local/legacy'): self
  {
    return new self('', [], 'POST', $canonicalUrl, '', $payload);
  }
}
