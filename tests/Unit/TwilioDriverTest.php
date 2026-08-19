<?php

declare(strict_types=1);

namespace TimeFrontiers\Sms\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TimeFrontiers\Sms\Driver\TwilioDriver;
use TimeFrontiers\Sms\Dto\WebhookRequest;
use TimeFrontiers\Sms\Sms;
use TimeFrontiers\Sms\Tests\Support\FakeDatabase;
use Twilio\Security\RequestValidator;

final class TwilioDriverTest extends TestCase
{
  public function testSendUsesInjectedClientAndReturnsNamedExactResult(): void
  {
    /** @param array{from: string, body: string} $options */
    $send = static function (string $receiver, array $options): object {
      TestCase::assertSame('+2348024296777', $receiver);
      TestCase::assertSame('TEST', $options['from']);
      return (object) ['sid' => 'SM123', 'price' => '-0.0075', 'priceUnit' => 'usd', 'status' => 'queued'];
    };
    $driver = new TwilioDriver(['sid' => 'AC123', 'token' => 'token', 'send_callable' => $send]);

    $result = $driver->send($this->sms());
    self::assertSame('twilio', $result->provider);
    self::assertSame('SM123', $result->reference);
    self::assertSame('-0.00750000', $result->feeAmount);
    self::assertSame('USD', $result->feeCurrency);
  }

  public function testOfficialStyleFormSignatureUsesHeaderAndCanonicalUrl(): void
  {
    $token = 'test-token';
    $url = 'https://example.com/webhooks/twilio?tenant=1';
    $params = ['SmsSid' => 'SM123', 'MessageStatus' => 'delivered'];
    $signature = (new RequestValidator($token))->computeSignature($url, $params);
    $driver = $this->driver($token, $url);
    $request = new WebhookRequest(
      http_build_query($params),
      ['X-Twilio-Signature' => $signature, 'Content-Type' => 'application/x-www-form-urlencoded'],
      'POST',
      $url,
      '203.0.113.1',
      $params,
    );

    self::assertTrue($driver->verifyDeliveryReport($request));
    self::assertSame('delivered', $driver->parseDeliveryReport($request)->status);
  }

  public function testOfficialStyleJsonSignatureValidatesRawBodyAndBodyHash(): void
  {
    $token = 'test-token';
    $body = '{"SmsSid":"SM123","MessageStatus":"failed"}';
    $configuredUrl = 'https://example.com/webhooks/twilio';
    $signedUrl = $configuredUrl . '?bodySHA256=' . RequestValidator::computeBodyHash($body);
    $signature = (new RequestValidator($token))->computeSignature($signedUrl);
    $driver = $this->driver($token, $configuredUrl);
    $request = new WebhookRequest(
      $body,
      ['X-Twilio-Signature' => $signature, 'Content-Type' => 'application/json'],
      'POST',
      $signedUrl,
      '203.0.113.1',
    );

    self::assertTrue($driver->verifyDeliveryReport($request));
    self::assertSame('failed', $driver->parseDeliveryReport($request)->status);
  }

  public function testJsonSignatureRejectsAnyNonBodyHashQueryDifference(): void
  {
    $token = 'test-token';
    $body = '{"SmsSid":"SM123","MessageStatus":"failed"}';
    $configuredUrl = 'https://example.com/webhooks/twilio?tenant=1';
    $signedUrl = 'https://example.com/webhooks/twilio?tenant=2&bodySHA256=' . RequestValidator::computeBodyHash($body);
    $signature = (new RequestValidator($token))->computeSignature($signedUrl);
    $request = new WebhookRequest($body, [
      'X-Twilio-Signature' => $signature,
      'Content-Type' => 'application/json',
    ], 'POST', $signedUrl, '203.0.113.1');

    self::assertFalse($this->driver($token, $configuredUrl)->verifyDeliveryReport($request));
  }

  public function testUnparseableFeeKeepsAcceptedReferenceAndLogsRawValue(): void
  {
    $logged = [];
    $driver = new TwilioDriver([
      'sid' => 'AC123',
      'token' => 'token',
      'logger' => static function (string $code, \Throwable $exception, array $context) use (&$logged): void {
        $logged[] = [$code, $context];
      },
      'send_callable' => static fn(string $receiver, array $options): object => (object) [
        'sid' => 'SM-FEE', 'price' => 7.5E-6, 'priceUnit' => 'USD', 'status' => 'queued',
      ],
    ]);

    $result = $driver->send($this->sms());

    self::assertSame('SM-FEE', $result->reference);
    self::assertSame('0.00000000', $result->feeAmount);
    self::assertTrue($result->meta['fee_parse_failed']);
    self::assertSame('twilio_fee_parse_failed', $logged[0][0]);
    self::assertSame(7.5E-6, $logged[0][1]['raw_fee']);
  }

  public function testPayloadSignatureAndAttackerControlledUrlAreRejected(): void
  {
    $url = 'https://example.com/webhooks/twilio';
    $driver = $this->driver('token', $url);
    $payloadSignature = new WebhookRequest('', [], 'POST', $url, '203.0.113.1', ['Signature' => 'forged']);
    self::assertFalse($driver->verifyDeliveryReport($payloadSignature));

    $wrongUrl = new WebhookRequest('', ['X-Twilio-Signature' => 'forged'], 'POST', 'https://attacker.example/webhooks/twilio', '203.0.113.1');
    self::assertFalse($driver->verifyDeliveryReport($wrongUrl));
  }

  private function driver(string $token, string $url): TwilioDriver
  {
    return new TwilioDriver([
      'sid' => 'AC123', 'token' => $token, 'webhook_url' => $url,
      'send_callable' => static fn(string $receiver, array $options): object => (object) [],
    ]);
  }

  private function sms(): Sms
  {
    return Sms::hydrate([
      'id' => 1, 'code' => '828000000000001', 'status' => 'dispatching', 'provider' => 'twilio',
      'idempotency_scope' => 'test', 'idempotency_key_hash' => str_repeat('x', 32), 'version' => 1,
      'message_id' => null, 'direction' => 'outbound', 'user' => 'SYSTEM', 'batch' => null,
      'sender' => 'TEST', 'receiver' => '+2348024296777', 'message' => 'hello',
      '_message_pages' => 1, 'message_encoding' => 'GSM-7', 'fees_currency' => null,
      'fees' => '0.00000000', 'reference' => null, 'meta' => null, 'last_error_code' => null,
      'dispatch_started_at' => null, 'provider_accepted_at' => null, 'reconciled_at' => null,
      'content_expires_at' => null, '_created' => '', '_updated' => '',
    ], new FakeDatabase());
  }
}
