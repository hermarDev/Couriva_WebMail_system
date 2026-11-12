-- ============================================================================
-- Couriva Migration 005: Active Directory & Local Account Isolation
-- Prevents Active Directory accounts from bypassing domain restrictions
-- via local database fallback.
-- ============================================================================

ALTER TABLE `users`
ADD COLUMN `auth_source` ENUM('local', 'ldap') NOT NULL DEFAULT 'local' AFTER `avatar`,
ADD INDEX `idx_users_auth_source` (`auth_source`);
