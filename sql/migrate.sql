-- timefrontiers/php-sms — upgrade from linktude/php-sms
-- Run this against your existing `messaging` database (or wherever the `sms` table lives).
-- Then call Sms::populateMissingCodes($conn) from your application to fill the new `code` column.

START TRANSACTION;

-- 1. Add new columns (nullable first, so no DEFAULT conflict with existing data)
ALTER TABLE `sms`
  ADD COLUMN `code`       CHAR(15)         NULL AFTER `id`,
  ADD COLUMN `message_id` BIGINT UNSIGNED  NULL DEFAULT NULL AFTER `status`,
  ADD COLUMN `direction`  ENUM('outbound','inbound') NOT NULL DEFAULT 'outbound' AFTER `message_id`,
  ADD COLUMN `meta`       JSON             NULL DEFAULT NULL AFTER `reference`,
  ADD COLUMN `_updated`   TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP() AFTER `_created`;

-- 2. Adjust existing columns
ALTER TABLE `sms`
  MODIFY `status` ENUM('pending','queued','sent','failed','delivered') NOT NULL DEFAULT 'pending',
  CHANGE `user`   `user` CHAR(15) NOT NULL DEFAULT 'SYSTEM',
  CHANGE `units`  `_message_pages` TINYINT UNSIGNED NOT NULL DEFAULT 1;

-- 3. Map legacy statuses to 'pending' (existing rows won't have the new values)
UPDATE `sms` SET `status` = 'pending' WHERE `status` NOT IN ('pending','queued','sent','failed','delivered');

-- 4. Add new indexes (IF NOT EXISTS is not available in MariaDB; these may fail silently if they already exist — that's fine)
ALTER TABLE `sms`
  ADD UNIQUE INDEX `code`       (`code`),
  ADD INDEX `idx_user`          (`user`),
  ADD INDEX `idx_batch`         (`batch`),
  ADD INDEX `idx_reference`     (`reference`),
  ADD INDEX `idx_message_id`    (`message_id`);

COMMIT;

-- 5. After the migration, run this PHP code (using the same connection):
--
--    $conn = new \TimeFrontiers\SQLDatabase(...);
--    \TimeFrontiers\Sms\Sms::populateMissingCodes($conn);
--
-- This will give every existing row a unique 15-char code prefixed '828'.