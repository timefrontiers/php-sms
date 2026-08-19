# timefrontiers/php-sms

Multi-provider SMS dispatch for PHP 8.5 with durable idempotency, explicit
database connections, authenticated delivery webhooks, exact fee storage, and
automatic continent-based provider routing.

This worktree contains the unreleased v1.1 upgrade. It must complete the
independent audit described in `AUDIT.md` before it is tagged or published.

## Requirements

- PHP 8.5 with `mbstring`
- MySQL 8.0+ or MariaDB 10.4+
- SQL Database 1.1.1+, DatabaseObject 1.1, and Validator 1.1
- Twilio SDK 8.x and AfricasTalking SDK 3.x

## Installation and schema

```bash
composer require timefrontiers/php-sms
```

Fresh installations use `sql/install.sql`. Existing 1.0 installations must
follow `UPGRADING.md` and run the staged files under `sql/migrations/`.

## Configuration

Call `Sms::configure()` exactly once during bootstrap. Pass the application’s
resolved SQL Database facade; sends retain this connection and an immutable
configuration snapshot.

```php
use TimeFrontiers\SQLDatabase;
use TimeFrontiers\Sms\Dto\WebhookRequest;
use TimeFrontiers\Sms\Sms;

/** @var SQLDatabase $database */
Sms::configure([
    'db_name' => 'messaging',
    'default_driver' => 'twilio',
    'default_sender' => 'MyApp',
    'region_strategy' => 'auto',
    'continent_mapping' => [
        'Africa' => 'africastalking',
    ],
    'idempotency_scope' => 'production',
    'max_segments' => 5,
    'stale_dispatch_seconds' => 300,
    'default_region' => 'NG',
    'content_retention_days' => 30,
    'logger' => static function (string $code, Throwable $error, array $context): void {
        // Send only to a trusted logger that redacts credentials and PII.
    },
    'drivers' => [
        'twilio' => [
            'sid' => getenv('TWILIO_ACCOUNT_SID'),
            'token' => getenv('TWILIO_AUTH_TOKEN'),
            'default_sender' => '+15005550006',
            'allowed_senders' => ['+15005550006', 'MyApp'],
            'webhook_url' => 'https://api.example.com/webhooks/sms/twilio',
        ],
        'africastalking' => [
            'app_id' => getenv('AT_USERNAME'),
            'api_key' => getenv('AT_API_KEY'),
            'default_sender' => 'MyApp',
            'allowed_senders' => ['MyApp'],
            'webhook_verifier' => static function (WebhookRequest $request): bool {
                // Authenticate a shared gateway secret, mTLS identity, or an
                // equivalent application-controlled trust boundary.
                return hash_equals(
                    (string) getenv('SMS_GATEWAY_SECRET'),
                    (string) $request->header('x-sms-gateway-secret'),
                );
            },
        ],
    ],
], $database);
```

All configured drivers and default senders are validated at bootstrap. A
request-selected sender is accepted only when it appears in that driver’s
`allowed_senders` list. Configuration cannot be replaced later in the process.

Driver SDK calls can be replaced in tests with `send_callable`; production
credentials are never included in provider errors or database metadata.

## Sending

The typed result API preserves ambiguous attempts:

```php
use TimeFrontiers\Sms\Result\SendResult;
use TimeFrontiers\Sms\Sms;

$result = Sms::sendResult([
    'receiver' => '+234 802 429 6777',
    'message' => 'Your verification code is 123456',
    'idempotency_key' => 'otp:user-828000000000001:challenge-42',
    'idempotency_scope' => 'auth',
]);

switch ($result->outcome) {
    case SendResult::ACCEPTED:
    case SendResult::REPLAYED:
        $sms = $result->sms;
        break;

    case SendResult::IN_FLIGHT:
        // An identical request owns the dispatch claim. Do not send again.
        $sms = $result->sms;
        break;

    case SendResult::UNKNOWN:
        // The provider may have accepted it. Do not send again. Reconcile it.
        $sms = $result->sms;
        break;

    default:
        $safeErrors = $result->errors;
}
```

The caller-supplied key is hashed before storage and is unique within its
scope. Reusing a key with different provider, recipient, message, sender,
owner, batch, parent, or direction returns `SMS_IDEMPOTENCY_CONFLICT` and does
not call a provider.

When no key is supplied, the compatibility API generates a one-use random key.
That prevents internal double-dispatch but cannot deduplicate a later caller
retry, so production OTP and notification flows should always supply a stable
key.

The state machine is:

```text
pending -> dispatching -> sent|failed|unknown -> delivered
```

`delivered` is terminal. A late authenticated delivery can correct `failed` to
`delivered`. `unknown` is never automatically resent.

`Sms::send()` refuses to run while its SQL Database connection is already in a
caller-owned transaction. Provider network I/O cannot be made atomic with the
caller's SQL work, and a duplicate-key reconciliation must never poison an
unrelated transaction. Commit application work before dispatching, or use an
outbox worker after commit.

### Compatibility API

These v1.0 calls remain available:

```php
$sms = Sms::send($data, $driver);       // Sms|false
$sms = Sms::sendAndWait($data, $driver); // Sms|false
```

The wrappers return `false` for validation, rejection, infrastructure, and
unknown outcomes. Use `sendResult()` when the associated entity or exact
outcome is required. The deprecated process-global snapshot is reset around
every compatibility operation:

```php
$sms = Sms::send($data);
if ($sms === false) {
    $errors = Sms::lastErrors();
} else {
    // Optional consumer dependency:
    $errors = (new \TimeFrontiers\InstanceError($sms, $accessRank))->get();
}
```

Do not construct `InstanceError` from `false`.

## Message rules and fees

- Receivers are normalized and stored in E.164 form before assignment.
- `Sms::send()` accepts outbound direction only.
- User, batch, sender, parent, driver, and idempotency identifiers are bounded.
- Line endings are normalized to LF.
- GSM default-alphabet characters consume one septet; extension characters
  such as `^`, `{`, `}`, `\\`, `[`, `]`, `~`, `|`, and `€` consume two.
- Other messages use UTF-16/UCS-2 code-unit boundaries, including surrogate
  pairs for non-BMP characters.
- The configured segment limit replaces the former 250-character limit.

Provider money is persisted as `DECIMAL(18,8)` and handled as a decimal string:

```php
$sms->feesExact(); // "0.12500000"
$sms->fees();      // compatibility-only float view
```

If a provider accepts a message but returns a fee outside the exact decimal
grammar, the reference and accepted outcome are retained, the fee falls back to
`0.00000000`, and bounded metadata records `fee_parse_failed`. The raw fee is
sent only to the configured trusted logger.

## Delivery webhooks

Build webhook context at the trusted HTTP boundary. Do not reconstruct the
public URL from untrusted `Host`, proxy, or server variables.

```php
$request = new WebhookRequest(
    rawBody: file_get_contents('php://input'),
    headers: getallheaders(),
    method: $_SERVER['REQUEST_METHOD'],
    // For Twilio JSON callbacks, include Twilio's signed bodySHA256 query
    // parameter here. The configured webhook_url remains the fixed base URL.
    canonicalUrl: $trustedExactRequestUrl,
    trustedRemoteIp: $trustedProxyResolvedIp,
    parameters: $_POST,
);

$result = Sms::deliveryReportResult('twilio', $request);
```

Twilio signatures are read from `X-Twilio-Signature`. URL identity is checked
against the fixed configured URL after removing only Twilio's `bodySHA256`
parameter; the SDK then validates the signature against the real signed request
URL. Any other query difference is rejected.

AfricasTalking delivery reports fail closed unless `webhook_verifier` is
configured. The presence of `id` and `status` is parsing, not authentication.

Authenticated reports are looked up by `(provider, reference)`, recorded in a
deduplicated event table, and applied with a status/version predicate. Results
distinguish `updated`, `duplicate`, `ignored`, `not_found`, `invalid`, and
`infrastructure_failed`. Unknown statuses and terminal-state regressions do not
merge metadata.

The legacy array-only `processDeliveryReport()` wrapper remains available but
does not carry headers, raw body, URL, or remote-IP context. It therefore fails
closed unless an application-controlled verifier explicitly authenticates that
limited context.

## Lookups

```php
$sms = Sms::findByCode('828123456789012');
$sms = Sms::findByProviderReference('twilio', 'SM123');
$sms = Sms::findByReference('SM123', 'twilio');
```

Calling `findByReference('SM123')` without a provider returns a row only when
the reference is globally unambiguous.

Direct `$sms->save()` is disabled because state changes require conditional
version checks. Use the service/facade operations.

## Recovery and retention

The legacy public-code data repair is also available as a bounded dry-run CLI:

```bash
php bin/sms-backfill-codes --bootstrap=/absolute/path/to/bootstrap.php
php bin/sms-backfill-codes --bootstrap=/absolute/path/to/bootstrap.php --apply
```

Dry-run stale-attempt reconciliation:

```bash
php bin/sms-recover --bootstrap=/absolute/path/to/bootstrap.php
php bin/sms-recover --bootstrap=/absolute/path/to/bootstrap.php --apply
```

Drivers implementing `SmsStatusLookupInterface` can reconcile a known provider
reference. Missing references and unsupported provider lookups are reported for
manual review. The command never resends.

Recovery and retention comparisons use database-session time on both sides of
the comparison, so PHP and database timezone differences do not move the
staleness or expiry windows.

When `content_retention_days` is configured, each new record receives an expiry
timestamp. Redact expired receiver/message content with:

```bash
php bin/sms-purge --bootstrap=/absolute/path/to/bootstrap.php
php bin/sms-purge --bootstrap=/absolute/path/to/bootstrap.php --apply
```

Both commands default to dry-run and emit JSON progress. Deployments must also
use encrypted database volumes/backups, restrict access to the SMS tables, and
set retention appropriate to their jurisdiction. Provider event metadata is
allowlisted and bounded; full raw webhook payloads and throwable text are not
stored.

## Custom drivers

Implement `SmsDriverInterface` using the typed contracts:

```php
final class MyDriver implements \TimeFrontiers\Sms\Driver\SmsDriverInterface
{
    public function send(Sms $sms): ProviderSendResult { /* ... */ }
    public function verifyDeliveryReport(WebhookRequest $request): bool { /* ... */ }
    public function parseDeliveryReport(WebhookRequest $request): ParsedDeliveryReport { /* ... */ }
    public function getProviderName(): string { return 'mydriver'; }
}
```

Register either the driver object or `['driver' => $driver, ...sender policy]`.
Return `ProviderRejectedException` only for a definite rejection. Throw
`ProviderOutcomeUnknownException` whenever provider acceptance cannot be ruled
out.

## Development checks

```bash
composer validate --strict
composer lint
composer test
composer analyse
composer check
```

Real MySQLi, PDO-MySQL, and concurrent-send suites require a disposable
database whose name ends in `_test`:

```bash
SMS_TEST_DATABASE=php_sms_test \
SMS_TEST_USER=test \
SMS_TEST_PASSWORD=secret \
composer test
```

No test makes a live billable provider call.

## License

[MIT](LICENSE)
