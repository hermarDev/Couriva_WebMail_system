-- ============================================================================
-- Couriva - Database Schema
-- Compatible with MySQL 8.x / MariaDB 10.4+ / Windows Server 2019 IIS & LAMPP
--
-- Couriva keeps the proven users/emails data model and extends it with
-- approval states, custom folders, labels, archive/snooze, scheduled send,
-- notifications, sender blocks, and spam reports. The internal database name
-- remains `webmail` for upgrade compatibility.
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET NAMES utf8mb4;

-- Create the database first, then import this file into it, for example:
--   mysql -u root -e "CREATE DATABASE webmail CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
--   mysql -u root webmail < database/schema.sql
-- The file intentionally has no USE statement so it can also build
-- disposable databases such as webmail_test without touching production.

-- ----------------------------------------------------------------------------
-- Table: users
-- Stores accounts, credentials, roles, approval state, profile and preferences
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `spam_reports`;
DROP TABLE IF EXISTS `blocked_senders`;
DROP TABLE IF EXISTS `notifications`;
DROP TABLE IF EXISTS `delivery_status`;
DROP TABLE IF EXISTS `scheduled_emails`;
DROP TABLE IF EXISTS `email_labels`;
DROP TABLE IF EXISTS `labels`;
DROP TABLE IF EXISTS `folders`;
DROP TABLE IF EXISTS `system_logs`;
DROP TABLE IF EXISTS `attachments`;
DROP TABLE IF EXISTS `email_recipients`;
DROP TABLE IF EXISTS `emails`;
DROP TABLE IF EXISTS `contacts`;
DROP TABLE IF EXISTS `users`;

CREATE TABLE `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `email` VARCHAR(255) NOT NULL UNIQUE,
    `password` VARCHAR(255) NOT NULL,
    `first_name` VARCHAR(100) NOT NULL,
    `last_name` VARCHAR(100) NOT NULL,
    `role` ENUM('user', 'admin') NOT NULL DEFAULT 'user',
    `status` ENUM('active', 'disabled', 'pending', 'rejected', 'suspended') NOT NULL DEFAULT 'active',
    `avatar` VARCHAR(255) NULL DEFAULT NULL,
    `signature` TEXT NULL DEFAULT NULL,
    `timezone` VARCHAR(100) NOT NULL DEFAULT 'UTC',
    `theme` VARCHAR(20) NOT NULL DEFAULT 'light',
    `must_change_password` TINYINT(1) NOT NULL DEFAULT 0,
    `session_epoch` INT UNSIGNED NOT NULL DEFAULT 1,
    `requested_at` DATETIME NULL DEFAULT NULL,
    `reviewed_by` INT NULL DEFAULT NULL,
    `reviewed_at` DATETIME NULL DEFAULT NULL,
    `rejection_reason` VARCHAR(500) NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_users_reviewed_by` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
    INDEX `idx_users_email` (`email`),
    INDEX `idx_users_role` (`role`),
    INDEX `idx_users_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table: folders
-- User-created mailbox folders (system folders stay in emails.folder)
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

-- ----------------------------------------------------------------------------
-- Table: labels
-- Color-coded labels owned by a single user
-- ----------------------------------------------------------------------------
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
-- Table: emails
-- Core messages with system folder, archive, snooze, and custom folder fields
-- ----------------------------------------------------------------------------
CREATE TABLE `emails` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `sender_id` INT NULL DEFAULT NULL,
    `sender_email_snapshot` VARCHAR(255) NULL DEFAULT NULL,
    `sender_name_snapshot` VARCHAR(255) NULL DEFAULT NULL,
    `recipient_id` INT NULL DEFAULT NULL,
    `parent_email_id` BIGINT NULL DEFAULT NULL,
    `message_id` VARCHAR(255) NULL DEFAULT NULL,
    `delivery_metadata` JSON NULL DEFAULT NULL,
    `subject` VARCHAR(500) NOT NULL DEFAULT '',
    `body` LONGTEXT NULL,
    `is_read` BOOLEAN NOT NULL DEFAULT FALSE,
    `is_starred` BOOLEAN NOT NULL DEFAULT FALSE,
    `is_draft` BOOLEAN NOT NULL DEFAULT FALSE,
    `is_archived` TINYINT(1) NOT NULL DEFAULT 0,
    `archived_at` DATETIME NULL DEFAULT NULL,
    `snoozed_until` DATETIME NULL DEFAULT NULL,
    `custom_folder_id` INT NULL DEFAULT NULL,
    `folder` ENUM('inbox', 'sent', 'draft', 'trash', 'spam', 'archive') NOT NULL DEFAULT 'inbox',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at` TIMESTAMP NULL DEFAULT NULL,
    CONSTRAINT `fk_emails_sender` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_emails_recipient` FOREIGN KEY (`recipient_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_emails_parent` FOREIGN KEY (`parent_email_id`) REFERENCES `emails` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_emails_custom_folder` FOREIGN KEY (`custom_folder_id`) REFERENCES `folders` (`id`) ON DELETE SET NULL,
    INDEX `idx_email_recipient` (`recipient_id`),
    INDEX `idx_email_sender` (`sender_id`),
    INDEX `idx_email_folder` (`folder`),
    INDEX `idx_email_created` (`created_at`),
    INDEX `idx_email_subject` (`subject`(191)),
    INDEX `idx_email_is_read` (`is_read`),
    INDEX `idx_email_is_starred` (`is_starred`),
    INDEX `idx_email_is_draft` (`is_draft`),
    INDEX `idx_email_deleted` (`deleted_at`),
    INDEX `idx_email_parent` (`parent_email_id`),
    INDEX `idx_email_message_id` (`message_id`),
    INDEX `idx_email_recipient_folder` (`recipient_id`, `folder`, `created_at`),
    INDEX `idx_email_sender_folder` (`sender_id`, `folder`, `created_at`),
    INDEX `idx_email_archived` (`recipient_id`, `is_archived`, `created_at`),
    INDEX `idx_email_snoozed` (`recipient_id`, `snoozed_until`),
    INDEX `idx_email_custom_folder` (`custom_folder_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table: email_recipients
-- Multi-recipient support (To, Cc, Bcc)
-- ----------------------------------------------------------------------------
CREATE TABLE `email_recipients` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `email_id` BIGINT NOT NULL,
    `recipient_id` INT NULL DEFAULT NULL,
    `email_address` VARCHAR(255) NOT NULL,
    `recipient_type` ENUM('to', 'cc', 'bcc') NOT NULL DEFAULT 'to',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_recipients_email` FOREIGN KEY (`email_id`) REFERENCES `emails` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_recipients_user` FOREIGN KEY (`recipient_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
    INDEX `idx_recipients_email_id` (`email_id`),
    INDEX `idx_recipients_recipient_id` (`recipient_id`),
    INDEX `idx_recipients_email_address` (`email_address`),
    INDEX `idx_recipients_type` (`recipient_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table: attachments
-- Stores metadata and physical storage references for file attachments
-- ----------------------------------------------------------------------------
CREATE TABLE `attachments` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `email_id` BIGINT NOT NULL,
    `original_filename` VARCHAR(255) NOT NULL,
    `stored_filename` VARCHAR(255) NOT NULL,
    `filepath` VARCHAR(500) NOT NULL,
    `mime_type` VARCHAR(100) NULL DEFAULT NULL,
    `filesize` BIGINT NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_attachments_email` FOREIGN KEY (`email_id`) REFERENCES `emails` (`id`) ON DELETE CASCADE,
    INDEX `idx_attachments_email_id` (`email_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table: contacts
-- User address book and contact management
-- ----------------------------------------------------------------------------
CREATE TABLE `contacts` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `contact_name` VARCHAR(255) NOT NULL,
    `contact_email` VARCHAR(255) NOT NULL,
    `phone` VARCHAR(50) NULL DEFAULT NULL,
    `company` VARCHAR(255) NULL DEFAULT NULL,
    `notes` TEXT NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_contacts_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    INDEX `idx_contacts_user_id` (`user_id`),
    INDEX `idx_contacts_email` (`contact_email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table: email_labels
-- Many-to-many label assignment
-- ----------------------------------------------------------------------------
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
-- Table: scheduled_emails
-- Pending future dispatches linked to the owner's draft
-- ----------------------------------------------------------------------------
CREATE TABLE `scheduled_emails` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `draft_email_id` BIGINT NULL DEFAULT NULL,
    `scheduled_at` DATETIME NOT NULL,
    `status` ENUM('scheduled', 'processing', 'sent', 'failed', 'cancelled') NOT NULL DEFAULT 'scheduled',
    `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `last_error` VARCHAR(500) NULL DEFAULT NULL,
    `sent_email_id` BIGINT NULL DEFAULT NULL,
    `delivery_status_id` BIGINT NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_scheduled_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_scheduled_draft` FOREIGN KEY (`draft_email_id`) REFERENCES `emails` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_scheduled_sent` FOREIGN KEY (`sent_email_id`) REFERENCES `emails` (`id`) ON DELETE SET NULL,
    INDEX `idx_scheduled_due` (`status`, `scheduled_at`),
    INDEX `idx_scheduled_user` (`user_id`, `scheduled_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table: delivery_status
-- Durable SMTP & local delivery tracking per recipient for retry/reconciliation
-- ----------------------------------------------------------------------------
CREATE TABLE `delivery_status` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `message_id` VARCHAR(255) NOT NULL,
    `email_id` BIGINT NULL DEFAULT NULL,
    `recipient_email` VARCHAR(255) NOT NULL,
    `recipient_type` ENUM('to', 'cc', 'bcc') NOT NULL DEFAULT 'to',
    `transport` ENUM('database', 'smtp', 'local') NOT NULL DEFAULT 'smtp',
    `status` ENUM('queued', 'delivered', 'sent', 'failed', 'bounced', 'deferred', 'unknown') NOT NULL DEFAULT 'queued',
    `smtp_response` TEXT NULL DEFAULT NULL,
    `smtp_code` SMALLINT NULL DEFAULT NULL,
    `attempt` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `max_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 3,
    `next_retry_at` DATETIME NULL DEFAULT NULL,
    `last_error` VARCHAR(500) NULL DEFAULT NULL,
    `idempotency_key` VARCHAR(64) NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `delivered_at` DATETIME NULL DEFAULT NULL,
    `failed_at` DATETIME NULL DEFAULT NULL,
    CONSTRAINT `fk_delivery_status_email` FOREIGN KEY (`email_id`) REFERENCES `emails` (`id`) ON DELETE CASCADE,
    UNIQUE KEY `uk_delivery_idempotency` (`idempotency_key`),
    INDEX `idx_delivery_message` (`message_id`),
    INDEX `idx_delivery_email` (`email_id`),
    INDEX `idx_delivery_status` (`status`),
    INDEX `idx_delivery_retry` (`status`, `next_retry_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table: notifications
-- In-app notifications for new mail, schedules, approvals, and reports
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

-- ----------------------------------------------------------------------------
-- Table: blocked_senders
-- Per-user sender block rules
-- ----------------------------------------------------------------------------
CREATE TABLE `blocked_senders` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `sender_email` VARCHAR(255) NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_blocked_senders_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    UNIQUE KEY `uq_blocked_senders_user_email` (`user_id`, `sender_email`),
    INDEX `idx_blocked_senders_email` (`sender_email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table: spam_reports
-- User-submitted spam evidence reviewed by administrators
-- ----------------------------------------------------------------------------
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

-- ----------------------------------------------------------------------------
-- Table: system_logs
-- Comprehensive audit logging for security, administrative actions, and debugging
-- ----------------------------------------------------------------------------
CREATE TABLE `system_logs` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NULL DEFAULT NULL,
    `action` VARCHAR(100) NOT NULL,
    `ip_address` VARCHAR(45) NULL DEFAULT NULL,
    `user_agent` VARCHAR(255) NULL DEFAULT NULL,
    `status` ENUM('SUCCESS', 'FAILED', 'INFO', 'WARNING') NOT NULL DEFAULT 'SUCCESS',
    `details` TEXT NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_system_logs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
    INDEX `idx_logs_user_id` (`user_id`),
    INDEX `idx_logs_action` (`action`),
    INDEX `idx_logs_status` (`status`),
    INDEX `idx_logs_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
