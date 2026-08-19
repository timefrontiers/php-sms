# Upgrading from 1.0.x to 1.1

`1.1.0` was released on 2026-08-19 after the independent audit recorded in
`AUDIT.md`. Require `timefrontiers/php-sms:^1.1`.

## 1. Prerequisites

- Upgrade the runtime to PHP 8.5 with `mbstring`.
- Resolve SQL Database 1.1.1+, DatabaseObject 1.1, Validator 1.1, and HasErrors
  1.x from immutable releases.
- Verify the installed references with `composer show -a`.
- Commit or otherwise preserve the application’s current SMS configuration and
  database schema before proceeding.

The library does not ship a Composer lock file.

## 2. Preflight and migration

Use a disposable copy first. The migration contains DDL and is not
transactional on MySQL/MariaDB.

1. Pause sends and delivery webhooks.
2. Back up `sms` and record its row count/checksum.
3. Run `sql/migrations/1.1.0-preflight.sql`.
4. Resolve every duplicate, invalid receiver, invalid code, and oversized
   identifier reported by preflight. Missing public codes can be handled in
   bounded, resumable batches with `bin/sms-backfill-codes`; it defaults to
   dry-run and requires `--apply` to write.
5. Run `1.1.0-phase1.sql`, `1.1.0-phase2.sql`, then `1.1.0-phase3.sql`.
   The mysql/mariadb CLI may use `1.1.0.sql` as a `SOURCE` wrapper; invoke it
   with `--abort-source-on-error` so automation receives a failing exit status.
6. Run `sql/migrations/1.1.0-verify.sql`; all counts must be zero and all
   expected indexes/tables must be present.
7. Deploy application configuration/code.
8. Re-enable webhooks, then controlled non-billable provider fixtures, then
   outbound workers.

Historical provider identity cannot be inferred safely. Existing rows are
backfilled as `legacy`, and live provider webhooks cannot target them through a
different provider route. Between migration phases 1 and 2, operators may
assign provider values only where trusted deployment records prove the source.

Each phase is resumable. If phase 3 fails while creating a unique index, use
the preflight result sets—not the online-DDL error text—to identify and repair
the data, then rerun phase 3 from its beginning. Its index and constraint steps
are guarded and converge old names to the fresh-install schema.

Before migration, review and resolve every row with `reference = 'None'`.
AfricasTalking uses that value as a recipient-refusal sentinel; v1.1 never
accepts it as a provider reference.

For large tables, run the two deterministic backfill updates in primary-key
ranges, monitor replica lag, and create unique indexes using the online-DDL
options supported by the deployed MySQL/MariaDB release. The supplied SQL is a
reference sequence; the database operator owns online-DDL syntax and capacity.

## 3. Configuration changes

Pass the exact SQL Database facade:

```php
Sms::configure($options, $database);
```

Configuration is call-once. Every enabled driver needs valid credentials and a
configured default sender. A missing credential or sender is fatal during
bootstrap even if that driver is not the default; omit intentionally disabled
drivers from the configuration. Any sender selectable by message input must
appear in `allowed_senders`.

Configure Twilio’s exact public `webhook_url`. Do not derive it from request
host/proxy headers. Configure an application verifier for AfricasTalking or
leave its webhook route disabled.

## 4. Sending changes

Existing `Sms|false` calls still work, but cannot communicate an ambiguous
provider outcome. Upgrade important flows to:

```php
$result = Sms::sendResult([
    ...$data,
    'idempotency_key' => $stableBusinessOperationKey,
]);
```

Never retry `unknown` automatically. Reconcile it through the recovery command
or an administrative provider lookup.

`in_flight` means an identical concurrent request already owns the dispatch
claim. It is not an ambiguous provider outcome and must not be sent again.

Do not call `Sms::send()` inside an open transaction on its SQL Database
connection. The call is rejected before SQL or provider I/O. Commit first or
dispatch through a post-commit outbox worker.

The old `max:250` rule is gone. Set `max_segments` as the transport policy.
`fees()` remains a float compatibility view; accounting must use `feesExact()`.

## 5. Webhook controller changes

Build `WebhookRequest` with the exact raw body, complete provider parameters,
headers, method, trusted exact request URL, and trusted-proxy-resolved remote IP.
Call `deliveryReportResult()` when the controller needs a reasoned response.

For Twilio JSON callbacks, the request URL includes Twilio's `bodySHA256` query
parameter. Keep the configured `webhook_url` fixed; do not append a body hash to
configuration. Any other request/configuration query difference fails closed.

Passing only a payload array no longer establishes authenticity.

## 6. Retention and operations

Schedule `sms-recover` and `sms-purge` in dry-run first. Review their JSON output
before applying changes. Recovery never sends an SMS. Purge overwrites expired
receiver and message content while retaining financial and delivery audit data.

Use encrypted database volumes/backups and ensure the trusted logger redacts
PII and credentials.

## 7. Rollback

Follow `sql/migrations/1.1.0-rollback.sql`. Do not drop v1.1 columns, unique
indexes, or the event table during an emergency application rollback. First
reconcile all `dispatching` and `unknown` rows. Destructive reversal would erase
the evidence needed to decide whether a provider accepted a message.

## 8. Verification

Run `composer check` with `SMS_TEST_DATABASE` set, and require zero skips. This
runs the MySQLi, PDO-MySQL, transaction, clock-skew, and two-process concurrency
suites against a disposable `*_test` database. CI performs the same run against
a MariaDB 10.11 service container on every push.

Deploying the consuming application still requires its bootstrap to pass the
explicit database connection, its fixed Twilio public webhook URL to be
configured, and an AfricasTalking verifier to be reviewed (or that route left
disabled). Until then delivery reports fail closed and message status stops at
`sent` — the package is correct, the edge is simply not wired yet.
