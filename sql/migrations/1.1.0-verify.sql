-- All counts should be zero. Index and table rows should be present.
SELECT COUNT(*) AS `missing_v11_values`
FROM `sms`
WHERE `provider` IS NULL
   OR `idempotency_scope` IS NULL
   OR `idempotency_key_hash` IS NULL;

SELECT COUNT(*) AS `duplicate_idempotency_rows`
FROM (
  SELECT `idempotency_scope`, `idempotency_key_hash`
  FROM `sms`
  GROUP BY `idempotency_scope`, `idempotency_key_hash`
  HAVING COUNT(*) > 1
) AS `duplicates`;

SELECT COUNT(*) AS `duplicate_provider_references`
FROM (
  SELECT `provider`, `reference`
  FROM `sms`
  WHERE `reference` IS NOT NULL
  GROUP BY `provider`, `reference`
  HAVING COUNT(*) > 1
) AS `duplicates`;

SELECT `expected`.`index_name` AS `missing_sms_index`
FROM (
  SELECT 'PRIMARY' AS `index_name`
  UNION ALL SELECT 'uq_sms_code'
  UNION ALL SELECT 'uq_sms_idempotency'
  UNION ALL SELECT 'uq_sms_provider_reference'
  UNION ALL SELECT 'idx_sms_user'
  UNION ALL SELECT 'idx_sms_batch'
  UNION ALL SELECT 'idx_sms_message_id'
  UNION ALL SELECT 'idx_sms_recovery'
  UNION ALL SELECT 'idx_sms_content_expiry'
) AS `expected`
LEFT JOIN information_schema.STATISTICS AS `actual`
  ON `actual`.`TABLE_SCHEMA` = DATABASE()
 AND `actual`.`TABLE_NAME` = 'sms'
 AND `actual`.`INDEX_NAME` = `expected`.`index_name`
WHERE `actual`.`INDEX_NAME` IS NULL;

SELECT DISTINCT `INDEX_NAME` AS `unexpected_legacy_sms_index`
FROM information_schema.STATISTICS
WHERE `TABLE_SCHEMA` = DATABASE()
  AND `TABLE_NAME` = 'sms'
  AND `INDEX_NAME` IN ('code', 'idx_user', 'idx_batch', 'idx_message_id', 'idx_reference', 'idx_sms_reference');

SELECT `expected`.`constraint_name` AS `missing_sms_constraint`
FROM (
  SELECT 'chk_sms_public_code' AS `constraint_name`
  UNION ALL SELECT 'chk_sms_message_pages'
) AS `expected`
LEFT JOIN information_schema.TABLE_CONSTRAINTS AS `actual`
  ON `actual`.`CONSTRAINT_SCHEMA` = DATABASE()
 AND `actual`.`TABLE_NAME` = 'sms'
 AND `actual`.`CONSTRAINT_NAME` = `expected`.`constraint_name`
WHERE `actual`.`CONSTRAINT_NAME` IS NULL;

SELECT `expected`.`index_name` AS `missing_delivery_event_index`
FROM (
  SELECT 'PRIMARY' AS `index_name`
  UNION ALL SELECT 'uq_sms_delivery_event_fingerprint'
  UNION ALL SELECT 'uq_sms_delivery_event_id'
  UNION ALL SELECT 'idx_sms_delivery_event_sms'
) AS `expected`
LEFT JOIN information_schema.STATISTICS AS `actual`
  ON `actual`.`TABLE_SCHEMA` = DATABASE()
 AND `actual`.`TABLE_NAME` = 'sms_delivery_event'
 AND `actual`.`INDEX_NAME` = `expected`.`index_name`
WHERE `actual`.`INDEX_NAME` IS NULL;

SELECT COUNT(*) AS `missing_delivery_event_foreign_key`
FROM (
  SELECT 1
  WHERE NOT EXISTS (
    SELECT 1
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE `CONSTRAINT_SCHEMA` = DATABASE()
      AND `TABLE_NAME` = 'sms_delivery_event'
      AND `CONSTRAINT_NAME` = 'fk_sms_delivery_event_sms'
      AND `CONSTRAINT_TYPE` = 'FOREIGN KEY'
  )
) AS `missing`;

SHOW CREATE TABLE `sms`;
SHOW CREATE TABLE `sms_delivery_event`;
