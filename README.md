# timefrontiers/php-sms

Multi‑driver SMS messaging for PHP 8.4+. Handles outbound sends, delivery webhooks, message logging, and driver‑based routing with automatic continent selection.

---

## Requirements

- PHP 8.4+
- MariaDB 10.4+ / MySQL 8.0+
- `timefrontiers/php-core` — phone number country / continent detection
- `timefrontiers/php-data` — random code generation
- `timefrontiers/php-sql-database`
- `timefrontiers/php-database-object`
- `timefrontiers/php-pagination`
- `timefrontiers/php-instance-error`
- `timefrontiers/php-validator`
- `twilio/sdk` — required when using the Twilio driver
- `africastalking/africastalking` — required when using the AfricasTalking driver

---

## Installation

```bash
composer require timefrontiers/php-sms
```

---

## Database

Run `sql/install.sql` against your messaging database (e.g. `messaging`) to create the `sms` table.

**Migrating from `linktude/php-sms`?** See [`sql/migrate.sql`](sql/migrate.sql).

---

## Configuration

Call `Sms::configure()` once in your application bootstrap, before any SMS message is sent.

```php
use TimeFrontiers\Sms\Sms;

Sms::configure([
    'db_name'         => 'messaging',     // database containing the `sms` table
    'default_driver'  => 'twilio',        // fallback driver when no explicit driver is given
    'default_sender'  => 'MyApp',         // default sender ID / phone number
    'region_strategy' => 'auto',          // 'auto' = use continent mapping; or a fixed driver name
    'continent_mapping' => [            // maps continent → driver (used when strategy = 'auto')
        'Africa' => 'africastalking',
    ],
    'drivers' => [
        'twilio' => [
            'sid'          => 'AC...',
            'token'        => 'your-auth-token',
            'sender_id'    => 'MyApp',           // alphanumeric sender ID (if supported)
            'sender_phone' => '+1234567890',     // fallback long code
        ],
        'africastalking' => [
            'app_id'  => '...',
            'api_key' => '...',
            'sender_id' => 'MyApp',
        ],
        // future drivers: just add a key and credentials
    ],
]);
```

You can override any driver setting at send time.

---

## Usage

### Sending an SMS

```php
$sms = Sms::send([
    'receiver' => '+2348024296777',
    'message'  => 'Your verification code is 123456',
]);

if ($sms) {
    echo "Message sent! Code: " . $sms->code();
} else {
    $errors = \TimeFrontiers\InstanceError($sms, true);
    echo "Error: " . $errors->first();
}
```

`send()` returns a `Sms` instance (with `status = 'sent'`) on success, or `false` on failure. Errors are accessible via the `HasErrors` trait (`_userError`, `_systemError`). Read them with `InstanceError` (rank‑filtered).

You may also call `sendAndWait()` — an alias that keeps API symmetry for future async support.

### Override defaults per message

```php
$sms = Sms::send([
    'receiver' => '+254700000000',
    'message'  => 'Jambo!',
    'driver'   => 'africastalking',  // explicit driver
    'sender'   => 'MyBrand',         // override sender
    'user'     => $userCode,         // associate with a user (default 'SYSTEM')
    'batch'    => 'BATCH-001',       // group multiple messages
    'message_id' => 42,              // reply‑to an existing message
    'direction' => 'outbound',       // 'outbound' (default) or 'inbound'
]);
```

If `driver` is not supplied, the package resolves the driver according to `region_strategy`:

- `'auto'` — determines the phone number’s continent via `timefrontiers/php-core` and picks the driver from `continent_mapping`, falling back to `default_driver`.
- Any string — uses that driver directly.

### Message lookups

```php
// By unique code
$sms = Sms::findByCode('8280000000001');

// By provider reference (message SID / messageId)
$sms = Sms::findByReference('SM1234567890');

// Paginated messages for a user
$messages = Sms::query()
    ->where('user', $userCode)
    ->orderByDesc('_created')
    ->limit(20)
    ->get();
```

### Delivery reports (webhooks)

Handle incoming status callbacks from Twilio or AfricasTalking with a single static call:

```php
$updated = Sms::processDeliveryReport('twilio', $_POST);
if ($updated) {
    // $updated->status() is now 'delivered' or 'failed'
}
```

- The driver verifies the webhook signature (`verifyDeliveryReport`).
- The payload is parsed into a normalized reference and status (`parseDeliveryReport`).
- The corresponding `sms` row is loaded and its status updated.
- Driver‑specific metadata is merged into the `meta` JSON column.
- Returns the updated `Sms` instance or `null` if verification fails.

If you need a fully custom delivery endpoint, you can instantiate a driver directly and call its `verifyDeliveryReport` / `parseDeliveryReport` methods.

---

## Message entity

The `Sms` class represents a single row in the `sms` table and uses `DatabaseObject` + `Pagination` + `HasErrors`.

### Public getters

| Method | Returns | Description |
|--------|---------|-------------|
| `id()` | `?int` | Auto‑increment primary key |
| `code()` | `?string` | `828`-prefixed 15‑char unique code |
| `status()` | `string` | `pending`, `queued`, `sent`, `failed`, `delivered` |
| `messageId()` | `?int` | Parent message ID (for reply chains) |
| `direction()` | `string` | `outbound` or `inbound` |
| `user()` | `string` | Owner code (default `'SYSTEM'`) |
| `batch()` | `?string` | Batch group code |
| `sender()` | `string` | Sender ID / phone used |
| `receiver()` | `string` | Recipient phone number |
| `message()` | `string` | Message body (max 250 chars) |
| `messagePages()` | `int` | Calculated message parts (1‑5) |
| `fees()` | `float` | Cost incurred |
| `feesCurrency()` | `?string` | Currency of the fee |
| `reference()` | `?string` | Provider‑assigned ID |
| `meta()` | `?array` | Driver‑specific metadata (decoded JSON) |

### Pagination

The `Pagination` trait is available:

```php
$sms = new Sms($conn);
$sms->setPage($page)->setPerPage(20);
$conn   = $sms->conn();
$total  = $conn->fetchOne("SELECT COUNT(*) AS c FROM `messaging`.`sms` WHERE `user` = ?", [$userCode]);
$sms->setTotalCount((int)($total['c'] ?? 0));

$rows   = $conn->fetchAll(
    "SELECT * FROM `messaging`.`sms`
      WHERE `user` = ?
      ORDER BY `_created` DESC " . $sms->limitClause(),
    [$userCode]
);
return [
    'items' => $rows,
    'meta'  => $sms->paginationMeta("https://api.example.com/sms?user={$userCode}"),
];
```

### Query Builder

```php
$pending = Sms::query()
    ->where('status', 'pending')
    ->where('direction', 'outbound')
    ->orderBy('_created')
    ->limit(50)
    ->get();
```

---

## Custom drivers

Implement `TimeFrontiers\Sms\Driver\SmsDriverInterface`:

```php
class MyDriver implements SmsDriverInterface
{
    public function send(Sms $sms): array
    {
        // return [cost, currency, reference, senderUsed]
    }
    public function verifyDeliveryReport(array $payload): bool { ... }
    public function parseDeliveryReport(array $payload): array
    {
        // return ['reference' => ..., 'status' => 'delivered'|'failed', 'meta' => [...]]
    }
    public function getProviderName(): string { return 'mydriver'; }
}
```

Register it in configuration:

```php
Sms::configure([
    ...
    'drivers' => [
        'mydriver' => [ 'api_key' => '...' ],
    ],
]);
```

Then pass `'driver' => 'mydriver'` when sending.

---

## Error handling

- The `Sms` class uses the `HasErrors` trait. Validation failures and driver errors are recorded as `_userError` (rank 0) or `_systemError` (rank 7).
- To read errors filtered by access rank, wrap the instance in `InstanceError`:

```php
$ie = new \TimeFrontiers\InstanceError($sms, $session->access_rank);
echo $ie->first();
```

- Exceptions are thrown only for truly exceptional conditions (missing configuration, unknown driver). Normal send failures return `false` and populate errors.

---

## Database migration

If you are upgrading from `linktude/php-sms`:

1. Run `sql/migrate.sql` to update the schema.
2. Then call `Sms::populateMissingCodes($conn)` to fill the new `code` column with unique `828`‑prefixed identifiers.

---

## License

[MIT](LICENSE)