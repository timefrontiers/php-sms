-- v1.1.0 phase 3: final constraints, convergent indexes, and event table.
-- Pause sends/webhooks. If a unique-index statement finds dirty data, fix the
-- rows identified by preflight and rerun this same file from the beginning.

ALTER TABLE `sms`
  MODIFY `code` VARCHAR(15) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  MODIFY `provider` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  MODIFY `idempotency_scope` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  MODIFY `idempotency_key_hash` BINARY(32) NOT NULL;

-- Normalize the v1.0 public-code unique key name without dropping uniqueness
-- between DDL statements.
SET @sms_v11_sql = CASE
  WHEN EXISTS (SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sms' AND INDEX_NAME = 'uq_sms_code')
    THEN IF(
      EXISTS (SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sms' AND INDEX_NAME = 'code'),
      'ALTER TABLE `sms` DROP INDEX `code`',
      'SELECT 1'
    )
  WHEN EXISTS (SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sms' AND INDEX_NAME = 'code')
    THEN 'ALTER TABLE `sms` DROP INDEX `code`, ADD UNIQUE KEY `uq_sms_code` (`code`)'
  ELSE 'ALTER TABLE `sms` ADD UNIQUE KEY `uq_sms_code` (`code`)'
END;
PREPARE sms_v11_stmt FROM @sms_v11_sql; EXECUTE sms_v11_stmt; DEALLOCATE PREPARE sms_v11_stmt;

SET @sms_v11_sql = IF(
  EXISTS (SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sms' AND INDEX_NAME = 'uq_sms_idempotency'),
  'SELECT 1',
  'ALTER TABLE `sms` ADD UNIQUE KEY `uq_sms_idempotency` (`idempotency_scope`, `idempotency_key_hash`)'
);
PREPARE sms_v11_stmt FROM @sms_v11_sql; EXECUTE sms_v11_stmt; DEALLOCATE PREPARE sms_v11_stmt;

SET @sms_v11_sql = IF(
  EXISTS (SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sms' AND INDEX_NAME = 'uq_sms_provider_reference'),
  'SELECT 1',
  'ALTER TABLE `sms` ADD UNIQUE KEY `uq_sms_provider_reference` (`provider`, `reference`)'
);
PREPARE sms_v11_stmt FROM @sms_v11_sql; EXECUTE sms_v11_stmt; DEALLOCATE PREPARE sms_v11_stmt;

SET @sms_v11_sql = IF(
  EXISTS (SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sms' AND INDEX_NAME = 'idx_sms_recovery'),
  'SELECT 1',
  'ALTER TABLE `sms` ADD KEY `idx_sms_recovery` (`status`, `dispatch_started_at`)'
);
PREPARE sms_v11_stmt FROM @sms_v11_sql; EXECUTE sms_v11_stmt; DEALLOCATE PREPARE sms_v11_stmt;

SET @sms_v11_sql = IF(
  EXISTS (SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sms' AND INDEX_NAME = 'idx_sms_content_expiry'),
  'SELECT 1',
  'ALTER TABLE `sms` ADD KEY `idx_sms_content_expiry` (`content_expires_at`)'
);
PREPARE sms_v11_stmt FROM @sms_v11_sql; EXECUTE sms_v11_stmt; DEALLOCATE PREPARE sms_v11_stmt;

-- Normalize legacy non-unique index names to the fresh-install names.
SET @sms_v11_sql = CASE
  WHEN EXISTS (SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sms' AND INDEX_NAME = 'idx_sms_user')
    THEN IF(EXISTS (SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sms' AND INDEX_NAME = 'idx_user'), 'ALTER TABLE `sms` DROP INDEX `idx_user`', 'SELECT 1')
  WHEN EXISTS (SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sms' AND INDEX_NAME = 'idx_user')
    THEN 'ALTER TABLE `sms` DROP INDEX `idx_user`, ADD KEY `idx_sms_user` (`user`)'
  ELSE 'ALTER TABLE `sms` ADD KEY `idx_sms_user` (`user`)'
END;
PREPARE sms_v11_stmt FROM @sms_v11_sql; EXECUTE sms_v11_stmt; DEALLOCATE PREPARE sms_v11_stmt;

SET @sms_v11_sql = CASE
  WHEN EXISTS (SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sms' AND INDEX_NAME = 'idx_sms_batch')
    THEN IF(EXISTS (SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sms' AND INDEX_NAME = 'idx_batch'), 'ALTER TABLE `sms` DROP INDEX `idx_batch`', 'SELECT 1')
  WHEN EXISTS (SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sms' AND INDEX_NAME = 'idx_batch')
    THEN 'ALTER TABLE `sms` DROP INDEX `idx_batch`, ADD KEY `idx_sms_batch` (`batch`)'
  ELSE 'ALTER TABLE `sms` ADD KEY `idx_sms_batch` (`batch`)'
END;
PREPARE sms_v11_stmt FROM @sms_v11_sql; EXECUTE sms_v11_stmt; DEALLOCATE PREPARE sms_v11_stmt;

SET @sms_v11_sql = CASE
  WHEN EXISTS (SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sms' AND INDEX_NAME = 'idx_sms_message_id')
    THEN IF(EXISTS (SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sms' AND INDEX_NAME = 'idx_message_id'), 'ALTER TABLE `sms` DROP INDEX `idx_message_id`', 'SELECT 1')
  WHEN EXISTS (SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sms' AND INDEX_NAME = 'idx_message_id')
    THEN 'ALTER TABLE `sms` DROP INDEX `idx_message_id`, ADD KEY `idx_sms_message_id` (`message_id`)'
  ELSE 'ALTER TABLE `sms` ADD KEY `idx_sms_message_id` (`message_id`)'
END;
PREPARE sms_v11_stmt FROM @sms_v11_sql; EXECUTE sms_v11_stmt; DEALLOCATE PREPARE sms_v11_stmt;

-- A provider-scoped reference key supersedes both legacy reference indexes.
SET @sms_v11_sql = IF(
  EXISTS (SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sms' AND INDEX_NAME = 'idx_sms_reference'),
  'ALTER TABLE `sms` DROP INDEX `idx_sms_reference`',
  'SELECT 1'
);
PREPARE sms_v11_stmt FROM @sms_v11_sql; EXECUTE sms_v11_stmt; DEALLOCATE PREPARE sms_v11_stmt;
SET @sms_v11_sql = IF(
  EXISTS (SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sms' AND INDEX_NAME = 'idx_reference'),
  'ALTER TABLE `sms` DROP INDEX `idx_reference`',
  'SELECT 1'
);
PREPARE sms_v11_stmt FROM @sms_v11_sql; EXECUTE sms_v11_stmt; DEALLOCATE PREPARE sms_v11_stmt;

SET @sms_v11_sql = IF(
  EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'sms' AND CONSTRAINT_NAME = 'chk_sms_public_code'),
  'SELECT 1',
  'ALTER TABLE `sms` ADD CONSTRAINT `chk_sms_public_code` CHECK (`code` REGEXP ''^828[0-9]{8,12}$'')'
);
PREPARE sms_v11_stmt FROM @sms_v11_sql; EXECUTE sms_v11_stmt; DEALLOCATE PREPARE sms_v11_stmt;

SET @sms_v11_sql = IF(
  EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'sms' AND CONSTRAINT_NAME = 'chk_sms_message_pages'),
  'SELECT 1',
  'ALTER TABLE `sms` ADD CONSTRAINT `chk_sms_message_pages` CHECK (`_message_pages` BETWEEN 1 AND 20)'
);
PREPARE sms_v11_stmt FROM @sms_v11_sql; EXECUTE sms_v11_stmt; DEALLOCATE PREPARE sms_v11_stmt;

CREATE TABLE IF NOT EXISTS `sms_delivery_event` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sms_id` BIGINT UNSIGNED NOT NULL,
  `provider` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `event_id` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
  `event_fingerprint` BINARY(32) NOT NULL,
  `status` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `outcome` ENUM('processing','updated','duplicate','ignored','invalid','infrastructure_failed') NOT NULL DEFAULT 'processing',
  `safe_meta` JSON NULL DEFAULT NULL,
  `received_at` TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `processed_at` TIMESTAMP(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sms_delivery_event_fingerprint` (`provider`, `event_fingerprint`),
  UNIQUE KEY `uq_sms_delivery_event_id` (`provider`, `event_id`),
  KEY `idx_sms_delivery_event_sms` (`sms_id`, `received_at`),
  CONSTRAINT `fk_sms_delivery_event_sms` FOREIGN KEY (`sms_id`) REFERENCES `sms` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
