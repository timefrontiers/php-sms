-- v1.1.0 phase 1: guarded additive columns and compatible column shapes.
-- This phase is safe to resume after a partial DDL failure.

SET @sms_v11_sql = IF(
  EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sms' AND COLUMN_NAME = 'provider'),
  'SELECT 1',
  'ALTER TABLE `sms` ADD COLUMN `provider` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER `status`'
);
PREPARE sms_v11_stmt FROM @sms_v11_sql; EXECUTE sms_v11_stmt; DEALLOCATE PREPARE sms_v11_stmt;

SET @sms_v11_sql = IF(
  EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sms' AND COLUMN_NAME = 'idempotency_scope'),
  'SELECT 1',
  'ALTER TABLE `sms` ADD COLUMN `idempotency_scope` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER `provider`'
);
PREPARE sms_v11_stmt FROM @sms_v11_sql; EXECUTE sms_v11_stmt; DEALLOCATE PREPARE sms_v11_stmt;

SET @sms_v11_sql = IF(
  EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sms' AND COLUMN_NAME = 'idempotency_key_hash'),
  'SELECT 1',
  'ALTER TABLE `sms` ADD COLUMN `idempotency_key_hash` BINARY(32) NULL AFTER `idempotency_scope`'
);
PREPARE sms_v11_stmt FROM @sms_v11_sql; EXECUTE sms_v11_stmt; DEALLOCATE PREPARE sms_v11_stmt;

SET @sms_v11_sql = IF(
  EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sms' AND COLUMN_NAME = 'version'),
  'SELECT 1',
  'ALTER TABLE `sms` ADD COLUMN `version` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `idempotency_key_hash`'
);
PREPARE sms_v11_stmt FROM @sms_v11_sql; EXECUTE sms_v11_stmt; DEALLOCATE PREPARE sms_v11_stmt;

SET @sms_v11_sql = IF(
  EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sms' AND COLUMN_NAME = 'message_encoding'),
  'SELECT 1',
  'ALTER TABLE `sms` ADD COLUMN `message_encoding` ENUM(''GSM-7'',''UCS-2'') NOT NULL DEFAULT ''GSM-7'' AFTER `_message_pages`'
);
PREPARE sms_v11_stmt FROM @sms_v11_sql; EXECUTE sms_v11_stmt; DEALLOCATE PREPARE sms_v11_stmt;

SET @sms_v11_sql = IF(
  EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sms' AND COLUMN_NAME = 'last_error_code'),
  'SELECT 1',
  'ALTER TABLE `sms` ADD COLUMN `last_error_code` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER `meta`'
);
PREPARE sms_v11_stmt FROM @sms_v11_sql; EXECUTE sms_v11_stmt; DEALLOCATE PREPARE sms_v11_stmt;

SET @sms_v11_sql = IF(
  EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sms' AND COLUMN_NAME = 'dispatch_started_at'),
  'SELECT 1',
  'ALTER TABLE `sms` ADD COLUMN `dispatch_started_at` TIMESTAMP(6) NULL DEFAULT NULL AFTER `last_error_code`'
);
PREPARE sms_v11_stmt FROM @sms_v11_sql; EXECUTE sms_v11_stmt; DEALLOCATE PREPARE sms_v11_stmt;

SET @sms_v11_sql = IF(
  EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sms' AND COLUMN_NAME = 'provider_accepted_at'),
  'SELECT 1',
  'ALTER TABLE `sms` ADD COLUMN `provider_accepted_at` TIMESTAMP(6) NULL DEFAULT NULL AFTER `dispatch_started_at`'
);
PREPARE sms_v11_stmt FROM @sms_v11_sql; EXECUTE sms_v11_stmt; DEALLOCATE PREPARE sms_v11_stmt;

SET @sms_v11_sql = IF(
  EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sms' AND COLUMN_NAME = 'reconciled_at'),
  'SELECT 1',
  'ALTER TABLE `sms` ADD COLUMN `reconciled_at` TIMESTAMP(6) NULL DEFAULT NULL AFTER `provider_accepted_at`'
);
PREPARE sms_v11_stmt FROM @sms_v11_sql; EXECUTE sms_v11_stmt; DEALLOCATE PREPARE sms_v11_stmt;

SET @sms_v11_sql = IF(
  EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sms' AND COLUMN_NAME = 'content_expires_at'),
  'SELECT 1',
  'ALTER TABLE `sms` ADD COLUMN `content_expires_at` TIMESTAMP NULL DEFAULT NULL AFTER `reconciled_at`'
);
PREPARE sms_v11_stmt FROM @sms_v11_sql; EXECUTE sms_v11_stmt; DEALLOCATE PREPARE sms_v11_stmt;

SET @sms_v11_sql = IF(
  EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sms' AND COLUMN_NAME = 'content_redacted_at'),
  'SELECT 1',
  'ALTER TABLE `sms` ADD COLUMN `content_redacted_at` TIMESTAMP(6) NULL DEFAULT NULL AFTER `content_expires_at`'
);
PREPARE sms_v11_stmt FROM @sms_v11_sql; EXECUTE sms_v11_stmt; DEALLOCATE PREPARE sms_v11_stmt;

ALTER TABLE `sms`
  MODIFY `status` ENUM('pending','dispatching','queued','sent','failed','unknown','delivered') NOT NULL DEFAULT 'pending',
  MODIFY `user` VARCHAR(15) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'SYSTEM',
  MODIFY `batch` VARCHAR(15) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
  MODIFY `sender` VARCHAR(16) NOT NULL,
  MODIFY `receiver` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  MODIFY `message` TEXT NOT NULL,
  MODIFY `fees_currency` VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
  MODIFY `reference` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
  MODIFY `_created` TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  MODIFY `_updated` TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6);
