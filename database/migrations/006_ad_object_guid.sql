-- ============================================================================
-- Couriva Migration 006: Active Directory Immutable objectGUID Binding
-- Binds AD users to canonical ad_object_guid (UUID) to handle renames/email
-- changes safely without local account email hijacking.
-- ============================================================================

ALTER TABLE `users`
ADD COLUMN `ad_object_guid` VARCHAR(36) NULL DEFAULT NULL AFTER `auth_source`,
ADD UNIQUE KEY `uq_users_ad_object_guid` (`ad_object_guid`);
