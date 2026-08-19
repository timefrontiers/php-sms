<?php

declare(strict_types=1);

namespace TimeFrontiers\Sms\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TimeFrontiers\Sms\Dto\WebhookRequest;

final class WebhookRequestTest extends TestCase
{
  public function testHeaderLookupIsCaseInsensitiveAndContextIsRetained(): void
  {
    $request = new WebhookRequest(
      '{"ok":true}',
      ['X-Twilio-Signature' => 'signature', 'Content-Type' => 'application/json; charset=utf-8'],
      'post',
      'https://example.com/hooks/twilio?x=1',
      '203.0.113.7',
    );

    self::assertSame('signature', $request->header('x-twilio-signature'));
    self::assertSame('application/json', $request->contentType());
    self::assertSame('POST', $request->method);
  }

  public function testNonCanonicalRelativeUrlIsRejected(): void
  {
    $this->expectException(\InvalidArgumentException::class);
    new WebhookRequest('', [], 'POST', '/hooks/twilio', '127.0.0.1');
  }

  public function testUrlWithAnyUserInfoIsRejected(): void
  {
    $this->expectException(\InvalidArgumentException::class);
    new WebhookRequest('', [], 'POST', 'https://attacker@example.com/hooks/twilio', '127.0.0.1');
  }
}
