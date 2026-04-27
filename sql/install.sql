-- timefrontiers/php-sms — fresh install
-- Creates the `sms` table in your messaging database.
-- Run this against the database configured in Sms::configure() (e.g. `messaging`).

CREATE TABLE `sms` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`            CHAR(15)        NOT NULL,
  `status`          ENUM('pending','queued','sent','failed','delivered') NOT NULL DEFAULT 'pending',
  `message_id`      BIGINT UNSIGNED NULL DEFAULT NULL,
  `direction`       ENUM('outbound','inbound') NOT NULL DEFAULT 'outbound',
  `user`            CHAR(15)        NOT NULL DEFAULT 'SYSTEM',
  `batch`           CHAR(15)        NULL DEFAULT NULL,
  `sender`          CHAR(16)        NOT NULL,
  `receiver`        CHAR(16)        NOT NULL,
  `message`         VARCHAR(250)    NOT NULL,
  `_message_pages`  TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `fees_currency`   CHAR(8)         NULL DEFAULT NULL,
  `fees`            DECIMAL(18,8)   NOT NULL DEFAULT 0.00000000,
  `reference`       CHAR(128)       NULL DEFAULT NULL,
  `meta`            JSON            NULL DEFAULT NULL,
  `_created`        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  `_updated`        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`),
  INDEX `idx_sms_user` (`user`),
  INDEX `idx_sms_batch` (`batch`),
  INDEX `idx_sms_reference` (`reference`),
  INDEX `idx_sms_message_id` (`message_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;