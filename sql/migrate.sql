-- timefrontiers/php-sms — historical upgrade from linktude/php-sms to 1.0.
-- After this completes, run migrations/1.1.0-preflight.sql followed by the
-- staged 1.1.0 migration and verification files.
-- Run this against your existing `messaging` database (or wherever the `sms` table lives).
-- Then use the bounded sms-backfill-codes CLI to fill the new `code` column.

-- MySQL/MariaDB DDL implicitly commits. This historical migration must be run
-- on a backed-up/disposable copy first; it does not claim transactionality.

-- 1. Add new columns (nullable first, so no DEFAULT conflict with existing data)
ALTER TABLE `sms`
  ADD COLUMN `code`       VARCHAR(15) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER `id`,
  ADD COLUMN `message_id` BIGINT UNSIGNED  NULL DEFAULT NULL AFTER `status`,
  ADD COLUMN `direction`  ENUM('outbound','inbound') NOT NULL DEFAULT 'outbound' AFTER `message_id`,
  ADD COLUMN `meta`       JSON             NULL DEFAULT NULL AFTER `reference`,
  ADD COLUMN `_updated`   TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP() AFTER `_created`;

-- 2. Adjust existing columns
ALTER TABLE `sms`
  MODIFY `status` VARCHAR(32) NOT NULL,
  CHANGE `user`   `user` CHAR(15) NOT NULL DEFAULT 'SYSTEM',
  CHANGE `units`  `_message_pages` TINYINT UNSIGNED NOT NULL DEFAULT 1;

-- 3. Normalize legacy values before constraining the new enum.
UPDATE `sms`
SET `status` = CASE UPPER(`status`)
  WHEN 'QUEUED' THEN 'queued'
  WHEN 'SENT' THEN 'sent'
  WHEN 'FAILED' THEN 'failed'
  WHEN 'DELIVERED' THEN 'delivered'
  ELSE 'pending'
END;

ALTER TABLE `sms`
  MODIFY `status` ENUM('pending','queued','sent','failed','delivered') NOT NULL DEFAULT 'pending';

-- 4. Add new indexes. Inspect SHOW INDEX FROM `sms` first and omit only an
-- exact index that is already present. Any unexpected ALTER failure is fatal.
ALTER TABLE `sms`
  ADD UNIQUE INDEX `code`       (`code`),
  ADD INDEX `idx_user`          (`user`),
  ADD INDEX `idx_batch`         (`batch`),
  ADD INDEX `idx_reference`     (`reference`),
  ADD INDEX `idx_message_id`    (`message_id`);

-- 5. After the migration, run the new package CLI with the application
-- bootstrap. It defaults to dry-run; repeat --apply until no rows remain:
--
--   php bin/sms-backfill-codes --bootstrap=/absolute/app/bootstrap.php
--   php bin/sms-backfill-codes --bootstrap=/absolute/app/bootstrap.php --apply
