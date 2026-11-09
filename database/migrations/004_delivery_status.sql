-- ============================================================================
-- Couriva - Migration 004: Delivery Status (Outbox / Durable Delivery State)
-- Eliminates "SMTP accepted + DB commit failed" ambiguity by recording
-- per-recipient delivery outcomes BEFORE SMTP transport and finalizing
-- after commit. Supports capped unknown-outcome retries and idempotency.
-- ============================================================================

-- Drop if exists (idempotent)
DROP TABLE IF EXISTS `delivery_status`;

CREATE TABLE `delivery_status` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `message_id` VARCHAR(100) NOT NULL,                 -- SMTP Message-ID (for idempotency)
    `email_id` BIGINT NULL DEFAULT NULL,                          -- Local emails row (sent copy)
    `recipient_email` VARCHAR(255) NOT NULL,             -- Final delivery address
    `recipient_type` ENUM('to', 'cc', 'bcc') NOT NULL DEFAULT 'to',
    `transport` ENUM('database', 'smtp') NOT NULL DEFAULT 'database',
    `status` ENUM('queued', 'delivered', 'failed', 'bounced', 'deferred', 'unknown') NOT NULL DEFAULT 'queued',
    `smtp_response` TEXT NULL DEFAULT NULL,              -- Raw SMTP response (250/4xx/5xx)
    `smtp_code` SMALLINT NULL DEFAULT NULL,              -- Last 3-digit code
    `attempt` TINYINT UNSIGNED NOT NULL DEFAULT 0,       -- Retry attempt number
    `max_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 3,  -- Cap for unknown outcomes
    `next_retry_at` DATETIME NULL DEFAULT NULL,
    `last_error` VARCHAR(500) NULL DEFAULT NULL,
    `idempotency_key` VARCHAR(64) NULL DEFAULT NULL,     -- SHA-256 of (message_id + recipient) for dedup
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `delivered_at` DATETIME NULL DEFAULT NULL,
    `failed_at` DATETIME NULL DEFAULT NULL,
    CONSTRAINT `fk_delivery_email` FOREIGN KEY (`email_id`) REFERENCES `emails` (`id`) ON DELETE CASCADE,
    UNIQUE KEY `uq_delivery_idempotency` (`idempotency_key`),
    INDEX `idx_delivery_email_id` (`email_id`),
    INDEX `idx_delivery_status` (`status`),
    INDEX `idx_delivery_message_id` (`message_id`),
    INDEX `idx_delivery_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add delivery_status_id to scheduled_emails for linking
-- (nullable to avoid breaking existing rows)
ALTER TABLE `scheduled_emails` 
    ADD COLUMN `delivery_status_id` BIGINT NULL DEFAULT NULL AFTER `sent_email_id`,
    ADD CONSTRAINT `fk_scheduled_delivery` FOREIGN KEY (`delivery_status_id`) REFERENCES `delivery_status` (`id`) ON DELETE SET NULL;

-- Add delivery_metadata to emails for Message-ID tracking
ALTER TABLE `emails` 
    ADD COLUMN `message_id` VARCHAR(100) NULL DEFAULT NULL AFTER `parent_email_id`,
    ADD COLUMN `delivery_metadata` JSON NULL DEFAULT NULL AFTER `message_id`;

-- Index for finding emails by Message-ID (idempotency checks)
CREATE INDEX `idx_emails_message_id` ON `emails` (`message_id`);