-- ============================================================================
-- Couriva Migration 007: Inbox Category Tabs (Primary / Promotions / Updates)
-- ============================================================================

ALTER TABLE `emails`
ADD COLUMN `category` ENUM('primary', 'promotions', 'updates') NOT NULL DEFAULT 'primary' AFTER `folder`,
ADD INDEX `idx_email_category` (`recipient_id`, `folder`, `category`, `created_at`);
