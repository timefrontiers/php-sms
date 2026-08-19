-- Read-only preflight for timefrontiers/php-sms v1.1.0.
-- Every result set below must be empty or zero before applying 1.1.0.sql.

SELECT `code`, COUNT(*) AS `duplicate_count`
FROM `sms`
WHERE `code` IS NOT NULL
GROUP BY `code`
HAVING COUNT(*) > 1;

SELECT `reference`, COUNT(*) AS `duplicate_count`
FROM `sms`
WHERE `reference` IS NOT NULL AND `reference` <> ''
GROUP BY `reference`
HAVING COUNT(*) > 1;

SELECT COUNT(*) AS `invalid_public_codes`
FROM `sms`
WHERE `code` IS NOT NULL AND `code` NOT REGEXP '^828[0-9]{8,12}$';

SELECT COUNT(*) AS `missing_public_codes`
FROM `sms`
WHERE `code` IS NULL;

SELECT COUNT(*) AS `invalid_receivers`
FROM `sms`
WHERE `receiver` NOT REGEXP '^\\+[1-9][0-9]{5,14}$';

SELECT COUNT(*) AS `oversized_identifiers`
FROM `sms`
WHERE CHAR_LENGTH(`user`) > 15
   OR CHAR_LENGTH(COALESCE(`batch`, '')) > 15
   OR CHAR_LENGTH(`sender`) > 16
   OR CHAR_LENGTH(COALESCE(`reference`, '')) > 128;

SELECT COUNT(*) AS `invalid_message_page_counts`
FROM `sms`
WHERE `_message_pages` < 1 OR `_message_pages` > 20;

-- AfricasTalking used "None" as a refusal sentinel. These rows were never
-- accepted and require an operator decision before provider/reference
-- uniqueness is finalized.
SELECT `id`, `code`, `status`, `reference`
FROM `sms`
WHERE `reference` = 'None'
ORDER BY `id`;
