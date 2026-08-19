-- v1.1.0 phase 2: deterministic legacy backfill.
-- This phase is idempotent and may be repeated or applied in primary-key ranges.
-- Assign proven provider identities before replacing the remaining NULLs.

UPDATE `sms`
SET `provider` = 'legacy'
WHERE `provider` IS NULL;

UPDATE `sms`
SET `idempotency_scope` = 'legacy',
    `idempotency_key_hash` = UNHEX(SHA2(CONCAT('legacy:', `id`), 256))
WHERE `idempotency_scope` IS NULL OR `idempotency_key_hash` IS NULL;
