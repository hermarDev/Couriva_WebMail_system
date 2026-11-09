-- ============================================================================
-- Webmail System - P1 security migration
-- Run once against an existing database, after taking a backup.
-- Fresh installs already receive these changes from database/schema.sql.
-- ============================================================================

USE `webmail`;

-- First-login password change and session revocation epoch.
ALTER TABLE `users`
    ADD COLUMN `must_change_password` TINYINT(1) NOT NULL DEFAULT 0 AFTER `theme`,
    ADD COLUMN `session_epoch` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `must_change_password`;

-- Deleting a user must not delete messages from other users' mailboxes.
-- Preserve a sender snapshot so the message remains readable after deletion.
ALTER TABLE `emails`
    ADD COLUMN `sender_email_snapshot` VARCHAR(255) NULL DEFAULT NULL AFTER `sender_id`,
    ADD COLUMN `sender_name_snapshot` VARCHAR(255) NULL DEFAULT NULL AFTER `sender_email_snapshot`;

UPDATE `emails` e
LEFT JOIN `users` u ON e.`sender_id` = u.`id`
SET e.`sender_email_snapshot` = COALESCE(e.`sender_email_snapshot`, u.`email`),
    e.`sender_name_snapshot` = COALESCE(
        e.`sender_name_snapshot`,
        NULLIF(TRIM(CONCAT(COALESCE(u.`first_name`, ''), ' ', COALESCE(u.`last_name`, ''))), '')
    );

ALTER TABLE `emails` DROP FOREIGN KEY `fk_emails_sender`;
ALTER TABLE `emails` MODIFY `sender_id` INT NULL DEFAULT NULL;
ALTER TABLE `emails`
    ADD CONSTRAINT `fk_emails_sender`
    FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;
