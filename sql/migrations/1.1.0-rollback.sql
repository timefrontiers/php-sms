-- DATA-PRESERVING APPLICATION ROLLBACK NOTES
-- Do not drop v1.1 columns or sms_delivery_event during an emergency code
-- rollback. They are additive and contain reconciliation/audit data.
--
-- Before deploying 1.0 code:
--   1. pause dispatch and webhook workers;
--   2. reconcile every dispatching/unknown row;
--   3. confirm no status contains dispatching or unknown;
--   4. keep the v1.1 unique indexes and event table in place;
--   5. deploy 1.0 code only if it tolerates the expanded status enum.
--
-- Destructive schema reversal is intentionally omitted. It would discard
-- idempotency, provider identity, exact audit events, and ambiguous outcomes.

SELECT `id`, `code`, `provider`, `status`, `reference`, `dispatch_started_at`
FROM `sms`
WHERE `status` IN ('dispatching', 'unknown')
ORDER BY `id`;
