# Changelog

## [1.1.0] - 2026-08-19

Released after independent audit. The auditor re-verified every finding against
a disposable MariaDB 10.11 instance rather than relying on this package's own
tests; see `AUDIT.md` for the checklist and the recorded verdict.

### Added

- Typed send, provider, webhook, delivery, recovery, and purge results.
- Caller-scoped durable idempotency and versioned dispatch state.
- A distinct `in_flight` outcome for concurrent idempotency claim losers.
- Persisted provider identity and provider-scoped references.
- Authenticated raw webhook request context and fail-closed verification.
- Deduplicated, monotonic delivery-event processing.
- Exact decimal fee access and GSM-7/UCS-2 segment accounting.
- Additive migration, verification SQL, dry-run recovery, and PII purge tools.
- PHPUnit, PHPStan, parallel lint, SDK fakes, and optional real-database tests.

### Changed

- Minimum PHP version is 8.5.
- DatabaseObject and Validator now target their coordinated 1.1 lines.
- Configuration is validated, retains an explicit connection, and is frozen.
- Sends are rejected inside caller-owned transactions before database/provider I/O.
- Recovery and retention windows use database-session time consistently.
- Inputs are validated and normalized before typed entity assignment.
- `findByReference()` can take a provider and otherwise fails on ambiguity.
- Direct Active Record saves are disabled for SMS state.
- `lastErrors()` is retained only as a deprecated compatibility snapshot.

### Security

- Twilio uses its signature header and configured canonical callback URL.
- Twilio JSON callbacks retain the signed `bodySHA256` request URL while URL
  identity remains pinned to fixed configuration.
- AfricasTalking recipient status codes and the `None` reference sentinel are
  treated as rejection signals.
- AfricasTalking fails closed without an application authenticity strategy.
- Raw payloads, credentials, phone numbers, message bodies, and throwable text
  are excluded from stored provider metadata/errors.
- Sender selection requires a per-driver allowlist.

### Deprecated

- `Sms::lastErrors()` in favor of typed result objects.
- Array-only webhook processing when no authenticating gateway boundary exists.
- `populateMissingCodes()` in favor of versioned migration operations.

### Known limitations

- The Twilio webhook URL identity check rebuilds the request query through
  `parse_str()`/`http_build_query()` before comparing it with the configured
  `webhook_url`. A configured URL whose query uses `+` for a space, a valueless
  flag (`?flag`), or a `.` inside a key name will not match its own legitimate
  request. The failure is fail-closed — a genuine callback is rejected, never a
  forged one accepted — and a `webhook_url` with no query string, or with
  ordinary `key=value` pairs, is unaffected. Scheduled for 1.1.1.
