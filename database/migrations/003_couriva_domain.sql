-- ============================================================================
-- Couriva - Account and address migration
-- Moves every existing @webmail.local address to @couriva.local while keeping
-- password hashes and mailbox ownership intact. External addresses are not
-- changed. Run after 002_couriva_features.sql and after taking a backup.
--
-- This migration is safe to re-run: the WHERE clauses only match old-domain
-- values.
-- ============================================================================

USE `webmail`;

-- Local accounts and signatures. Passwords are intentionally untouched.
UPDATE `users`
SET `email` = REPLACE(LOWER(`email`), '@webmail.local', '@couriva.local'),
    `signature` = REPLACE(REPLACE(`signature`, '@webmail.local', '@couriva.local'),
                          'Webmail System', 'Couriva')
WHERE `email` LIKE '%@webmail.local'
   OR `signature` LIKE '%webmail.local%'
   OR `signature` LIKE '%Webmail System%';

-- Address book entries.
UPDATE `contacts`
SET `contact_email` = REPLACE(LOWER(`contact_email`), '@webmail.local', '@couriva.local')
WHERE `contact_email` LIKE '%@webmail.local';

-- To / Cc / Bcc recipient records.
UPDATE `email_recipients`
SET `email_address` = REPLACE(LOWER(`email_address`), '@webmail.local', '@couriva.local')
WHERE `email_address` LIKE '%@webmail.local';

-- Sender snapshots keep messages readable after account deletion.
UPDATE `emails`
SET `sender_email_snapshot` = REPLACE(LOWER(`sender_email_snapshot`), '@webmail.local', '@couriva.local')
WHERE `sender_email_snapshot` LIKE '%@webmail.local';

-- Human-readable system log details.
UPDATE `system_logs`
SET `details` = REPLACE(`details`, '@webmail.local', '@couriva.local')
WHERE `details` LIKE '%@webmail.local';
