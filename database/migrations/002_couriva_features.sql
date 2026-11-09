-- ============================================================================
-- Couriva - Core feature migration
-- Adds approval states, archive/snooze/custom folders, labels, scheduled send,
-- notifications, sender blocking, and spam reports on top of the existing
-- users/emails schema. Run once against an existing database after a backup.
--
-- Fresh installs receive these objects from database/schema.sql.
-- ============================================================================

USE `webmail`;

-- ----------------------------------------------------------------------------
-- Account lifecycle
-- active    = may sign in
-- pending   = self-registered, waiting for administrator approval
-- rejected  = registration declined
-- disabled  = administratively disabled
-- suspended = temporarily blocked, may be restored
-- ----------------------------------------------------------------------------
ALTER TABLE `users`
    MODIFY COLUMN `status` ENUM('active', 'disabled', 'pending', 'rejected', 'suspended')
        NOT NULL DEFAULT 'active',
    ADD COLUMN `requested_at` DATETIME NULL DEFAULT NULL AFTER `session_epoch`,
    ADD COLUMN `reviewed_by` INT NULL DEFAULT NULL AFTER `requested_at`,
    ADD COLUMN `reviewed_at` DATETIME NULL DEFAULT NULL AFTER `reviewed_by`,
    ADD COLUMN `rejection_reason` VARCHAR(500) NULL DEFAULT NULL AFTER `reviewed_at`,
    ADD CONSTRAINT `fk_users_reviewed_by`
        FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

-- ----------------------------------------------------------------------------
-- Custom folders and labels are always scoped to their owner.
-- ----------------------------------------------------------------------------
CREATE TABLE `folders` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `name` VARCHAR(80) NOT NULL,
    `color` VARCHAR(16) NOT NULL DEFAULT '#64748b',
    `sort_order` INT NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_folders_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    UNIQUE KEY `uq_folders_user_name` (`user_id`, `name`),
    INDEX `idx_folders_user` (`user_id`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `labels` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `name` VARCHAR(64) NOT NULL,
    `color` VARCHAR(16) NOT NULL DEFAULT '#2563eb',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_labels_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    UNIQUE KEY `uq_labels_user_name` (`user_id`, `name`),
    INDEX `idx_labels_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Mailbox organization fields on the existing messages table.
-- is_archived removes a message from Inbox without deleting it.
-- snoozed_until hides a message from Inbox until the timestamp passes.
-- custom_folder_id moves a message into a user-created folder.
-- ----------------------------------------------------------------------------
ALTER TABLE `emails`
    MODIFY COLUMN `folder` ENUM('inbox', 'sent', 'draft', 'trash', 'spam', 'archive')
        NOT NULL DEFAULT 'inbox',
    ADD COLUMN `is_archived` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_draft`,
    ADD COLUMN `archived_at` DATETIME NULL DEFAULT NULL AFTER `is_archived`,
    ADD COLUMN `snoozed_until` DATETIME NULL DEFAULT NULL AFTER `archived_at`,
    ADD COLUMN `custom_folder_id` INT NULL DEFAULT NULL AFTER `snoozed_until`,
    ADD CONSTRAINT `fk_emails_custom_folder`
        FOREIGN KEY (`custom_folder_id`) REFERENCES `folders` (`id`) ON DELETE SET NULL,
    ADD INDEX `idx_email_archived` (`recipient_id`, `is_archived`, `created_at`),
    ADD INDEX `idx_email_snoozed` (`recipient_id`, `snoozed_until`),
    ADD INDEX `idx_email_custom_folder` (`custom_folder_id`);

CREATE TABLE `email_labels` (
    `email_id` BIGINT NOT NULL,
    `label_id` INT NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`email_id`, `label_id`),
    CONSTRAINT `fk_email_labels_email` FOREIGN KEY (`email_id`) REFERENCES `emails` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_email_labels_label` FOREIGN KEY (`label_id`) REFERENCES `labels` (`id`) ON DELETE CASCADE,
    INDEX `idx_email_labels_label` (`label_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Scheduled sending: a scheduled row points at the user's draft. Delivery is
-- performed by bin/process_scheduled.php or by the authenticated runtime
-- trigger. The draft is kept so a cancelled schedule retains its content.
-- ----------------------------------------------------------------------------
CREATE TABLE `scheduled_emails` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `draft_email_id` BIGINT NULL DEFAULT NULL,
    `scheduled_at` DATETIME NOT NULL,
    `status` ENUM('scheduled', 'processing', 'sent', 'failed', 'cancelled')
        NOT NULL DEFAULT 'scheduled',
    `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `last_error` VARCHAR(500) NULL DEFAULT NULL,
    `sent_email_id` BIGINT NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_scheduled_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_scheduled_draft` FOREIGN KEY (`draft_email_id`) REFERENCES `emails` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_scheduled_sent` FOREIGN KEY (`sent_email_id`) REFERENCES `emails` (`id`) ON DELETE SET NULL,
    INDEX `idx_scheduled_due` (`status`, `scheduled_at`),
    INDEX `idx_scheduled_user` (`user_id`, `scheduled_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- In-app notifications and administrator review queues.
-- ----------------------------------------------------------------------------
CREATE TABLE `notifications` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `type` VARCHAR(32) NOT NULL DEFAULT 'system',
    `title` VARCHAR(255) NOT NULL,
    `message` VARCHAR(500) NULL DEFAULT NULL,
    `link` VARCHAR(255) NULL DEFAULT NULL,
    `read_at` DATETIME NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    INDEX `idx_notifications_user` (`user_id`, `read_at`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `blocked_senders` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `sender_email` VARCHAR(255) NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_blocked_senders_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    UNIQUE KEY `uq_blocked_senders_user_email` (`user_id`, `sender_email`),
    INDEX `idx_blocked_senders_email` (`sender_email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `spam_reports` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `reporter_id` INT NOT NULL,
    `email_id` BIGINT NOT NULL,
    `reason` VARCHAR(500) NULL DEFAULT NULL,
    `status` ENUM('open', 'resolved', 'dismissed') NOT NULL DEFAULT 'open',
    `reviewer_id` INT NULL DEFAULT NULL,
    `reviewer_note` VARCHAR(500) NULL DEFAULT NULL,
    `reviewed_at` DATETIME NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_spam_reports_reporter` FOREIGN KEY (`reporter_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_spam_reports_email` FOREIGN KEY (`email_id`) REFERENCES `emails` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_spam_reports_reviewer` FOREIGN KEY (`reviewer_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
    UNIQUE KEY `uq_spam_reports_email_reporter` (`email_id`, `reporter_id`),
    INDEX `idx_spam_reports_status` (`status`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
