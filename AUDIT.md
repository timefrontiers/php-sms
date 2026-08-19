# Independent audit handoff — v1.1.0

This file is a review checklist, not an audit result. The implementer must not
approve their own release.

## Outcome

The 2026-08-18 independent audit **rejected** the first candidate on five
blockers and three high-severity findings. Those were remediated, and the
2026-08-19 re-audit **approved** the result: every blocker and finding was
re-verified against a disposable MariaDB 10.11.18 instance with fixtures written
independently of this package's own tests, `composer check` passed with a real
database (60 tests, 191 assertions, zero skips, reproduced 3x), and both
migration lineages were proven to converge with a fresh install.

`v1.1.0` was tagged on 2026-08-19 on that basis. The full verdict, including
evidence and the one open low-severity item deferred to 1.1.1, is recorded in
the consuming project at `dev/php-sms-audit-verdict.md`.

Release-gate items that remain the **consuming application's** responsibility,
not the package's: the delivery-report webhook controllers, their route, and an
AfricasTalking webhook authenticity secret. Until those exist, delivery reports
fail closed and message status stops at `sent` — by design.

## Remediation map (audited and accepted)

- B1: Twilio compares fixed URL identity after stripping only `bodySHA256`,
  then validates the real signed JSON URL. The fixture no longer derives
  configuration from its request body.
- B2: AfricasTalking selects the intended recipient, checks accepted status
  codes, and rejects the `None` reference sentinel. Unit coverage verifies the
  durable row becomes `failed` with no reference.
- B3: both schema harnesses trim the install terminator; CI supplies a real
  MariaDB database and requires the integration tests to execute.
- B4: sends fail before SQL/provider I/O on a caller-owned transaction. Unit
  and MariaDB tests assert the caller can still commit unrelated work.
- B5: retention creation, recovery, and purge compare database-generated time.
  The MariaDB suite changes the session timezone and exercises recovery.
- H1: fee parse failures retain acceptance/reference, persist a zero fee plus a
  bounded flag, and send the raw value only to the trusted logger.
- H2: compatibility validation errors are emitted at guest rank with code
  `828100`, while typed field errors remain available.
- H3: claim-race losers return `in_flight`; the barriered concurrency test
  requires exactly `accepted` plus `in_flight` and one provider call.
- M1/M2: guarded phase files converge indexes/checks with a fresh install and
  resume after a deliberate phase-3 unique-key failure.
- L1-L8: URL user-info/fragment validation, dead DTO state, redacted replay,
  metadata overflow handling, constant-time statement failure inspection,
  error preservation, failed-to-delivered correction, and escape/surrogate
  segment-boundary packing are covered by the revised implementation/tests.

Application work is not represented as package approval: the bootstrap now
passes `$database` explicitly, but deployment owners must still supply the
fixed Twilio URL and choose/review the AfricasTalking verifier or keep that
route disabled. No tag or publication is authorized by this file.

## Current implementation gates

- PHP 8.5 syntax/lint
- strict Composer validation
- PHPUnit unit/provider-fixture suite
- PHPStan level 8
- MySQLi and PDO-MySQL integration suite when a disposable database is supplied
- two-process idempotency/concurrency suite when a disposable database is supplied

No live provider send is part of the suite.

## Auditor checklist

### Dependencies and public compatibility

- Confirm resolved immutable versions, especially SQL Database 1.1.1+.
- Confirm the PHP 8.5, direct HasErrors, and `mbstring` requirements.
- Exercise every preserved static facade and `Sms|false` return contract.
- Confirm `lastErrors()` resets between operations and cannot retain stale data.

### State and database integrity

- Review every SQL false branch and affected-row predicate.
- Prove a successful provider call cannot be reported as clean success after a
  failed accepted-state update.
- Kill workers before provider I/O, during I/O, and immediately after provider
  acceptance; confirm no automatic resend occurs.
- Run two or more identical requests concurrently and confirm one row and one
  provider attempt.
- Verify idempotency payload conflicts fail without provider I/O.
- Test unique public-code retry and `(provider, reference)` collisions.
- Test migration preflight, ranged backfill, verification, resumption, and the
  data-preserving rollback procedure on a disposable production-sized copy.

### Webhook boundary

- Recalculate Twilio form and JSON signatures from independent official-style
  fixtures.
- Attempt signature-in-payload, hostile Host, rewritten URL, proxy header,
  missing raw body, altered parameters, and replay attacks.
- Confirm AfricasTalking is unauthenticated by default and succeeds only through
  the reviewed application/gateway verifier.
- Test cross-provider reference collisions, unknown statuses, duplicate event
  IDs, body replay fingerprints, late failure, and delivered-state regression.
- Force insert, update, commit, and rollback failures in event processing.

### Input, encoding, money, and privacy

- Fuzz wrong scalar/container types and every configured identifier bound.
- Independently verify GSM default/extension alphabet and UCS-2/non-BMP vectors.
- Compare provider decimal fixtures without binary-float conversion.
- Scan database rows and logs for credentials, raw webhook bodies, exception
  text, unbounded payloads, and unexpected PII.
- Test dry-run/apply behavior for recovery and retention purge, including
  version conflicts and partial database failure.

### Platform parity

- Run the full suite with MySQLi and PDO-MySQL.
- Repeat on the minimum supported MariaDB and MySQL releases.
- Confirm the deployed online-DDL strategy and replica-lag limits.

## Required release evidence

Attach command output for Composer validation, lint, PHPUnit, PHPStan, real
database parity, concurrency, migration verification, and privacy scans. Record
all findings and resolutions. Only an independent reviewer may mark this audit
complete and authorize the v1.1.0 tag.
