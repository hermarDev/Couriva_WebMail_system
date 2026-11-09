-- ============================================================================
-- Couriva - Seed Data
-- Administrator, Test Users, Sample Emails, Contacts, and System Logs
-- ============================================================================

-- Import into the target database explicitly:
--   mysql -u root webmail < database/seed.sql
SET FOREIGN_KEY_CHECKS = 0;

-- Clean existing data
TRUNCATE TABLE `spam_reports`;
TRUNCATE TABLE `blocked_senders`;
TRUNCATE TABLE `notifications`;
TRUNCATE TABLE `scheduled_emails`;
TRUNCATE TABLE `email_labels`;
TRUNCATE TABLE `labels`;
TRUNCATE TABLE `folders`;
TRUNCATE TABLE `system_logs`;
TRUNCATE TABLE `attachments`;
TRUNCATE TABLE `email_recipients`;
TRUNCATE TABLE `emails`;
TRUNCATE TABLE `contacts`;
TRUNCATE TABLE `users`;

-- ----------------------------------------------------------------------------
-- 1. Users
-- Passwords below are bcrypt hashes of random values that are not stored
-- anywhere. No usable default credential exists in this repository.
-- Provision the first administrator with:
--   ADMIN_EMAIL=... ADMIN_PASSWORD=... php bin/create-admin.php
-- ----------------------------------------------------------------------------
INSERT INTO `users` (`id`, `email`, `password`, `first_name`, `last_name`, `role`, `status`, `avatar`, `signature`, `timezone`, `must_change_password`, `session_epoch`, `created_at`, `updated_at`) VALUES
(1, 'admin@couriva.local', '$2y$12$GFH9OUh1NLprAEI2r/7BCuL13nhjb.CpEnVnhxAu0jVQLW8Lcojhe', 'System', 'Administrator', 'admin', 'active', NULL, '--\r\nSystem Administrator\r\nIT Department | Couriva Operations\r\nEmail: admin@couriva.local', 'UTC', 1, 1, '2026-09-20 08:00:00', '2026-09-20 08:00:00'),
(2, 'john.doe@couriva.local', '$2y$12$NCFYv6k/3TSsAzq.xGi2XekLM7Ni/KikcSIU9qnKY0OGtMm70kg0y', 'John', 'Doe', 'user', 'active', NULL, 'Best regards,\r\nJohn Doe\r\nProduct Lead & Engineering\r\nAcme Solutions', 'America/New_York', 1, 1, '2026-09-21 09:00:00', '2026-09-21 09:00:00'),
(3, 'jane.smith@couriva.local', '$2y$12$42gMYD.tHvUPG54nsKI1YuRPtkunkAUhUFgHlfEVpnGuNTa5cDMLO', 'Jane', 'Smith', 'user', 'active', NULL, 'Thanks & Regards,\r\nJane Smith\r\nSenior Systems Architect\r\nAcme Solutions', 'Europe/London', 1, 1, '2026-09-21 09:30:00', '2026-09-21 09:30:00');

-- ----------------------------------------------------------------------------
-- 1b. Labels and custom folders (seed examples for the demo accounts)
-- ----------------------------------------------------------------------------
INSERT INTO `labels` (`id`, `user_id`, `name`, `color`, `created_at`) VALUES
(1, 2, 'Work', '#2563eb', '2026-09-20 09:00:00'),
(2, 2, 'Personal', '#16a34a', '2026-09-20 09:01:00'),
(3, 2, 'Important', '#dc2626', '2026-09-20 09:02:00'),
(4, 3, 'Work', '#2563eb', '2026-09-20 09:03:00');

INSERT INTO `folders` (`id`, `user_id`, `name`, `color`, `sort_order`, `created_at`) VALUES
(1, 2, 'Projects', '#7c3aed', 1, '2026-09-20 09:10:00'),
(2, 2, 'Reference', '#0891b2', 2, '2026-09-20 09:11:00'),
(3, 3, 'Reviews', '#7c3aed', 1, '2026-09-20 09:12:00');

-- ----------------------------------------------------------------------------
-- 2. Emails
-- Covers: Inbox, Sent, Drafts, Starred, Trash, Spam, Threading (parent_email_id)
-- ----------------------------------------------------------------------------
INSERT INTO `emails` (`id`, `sender_id`, `recipient_id`, `parent_email_id`, `subject`, `body`, `is_read`, `is_starred`, `is_draft`, `folder`, `created_at`, `updated_at`, `deleted_at`) VALUES
-- 1: Welcome email from Admin to John (John's Inbox)
(1, 1, 2, NULL, 'Welcome to the Couriva', 
'Hello John,\r\n\r\nWelcome to your new enterprise Webmail account. The system is deployed and optimized for high-performance messaging across Windows Server 2019 IIS 10 and Linux/LAMPP environments.\r\n\r\nKey Features:\r\n- Secure message composition with rich text & multi-recipient support (To, Cc, Bcc)\r\n- Fast attachment upload and virus-safe download handling\r\n- Full mailbox folders: Inbox, Sent, Drafts, Starred, Spam, and Trash\r\n- Address book / contact management with quick auto-fill\r\n- Audit logging and administrative user governance\r\n\r\nIf you have any questions or require mailbox adjustments, please reply to this email.\r\n\r\nSincerely,\r\nSystem Administrator\r\nIT Operations', 
1, 0, 0, 'inbox', '2026-09-22 08:30:00', '2026-09-22 08:30:00', NULL),

-- 2: Jane sends architecture review to John (John's Inbox, Starred)
(2, 3, 2, NULL, 'Q3 System Architecture Review & IIS Deployment Plan', 
'Hi John,\r\n\r\nI have finalized the architecture blueprint for our deployment on Windows Server 2019 IIS 10 with PHP FastCGI and MariaDB.\r\n\r\nKey considerations highlighted in the document:\r\n1. Web.config request filtering isolating /storage, /config, /app, and /database\r\n2. URL rewrite rules for clean routing and HTTPS redirection\r\n3. MariaDB composite indexing on (recipient_id, folder, created_at) for sub-millisecond inbox loading\r\n4. Robust CSRF tokens and PDO prepared statements\r\n\r\nPlease review the attached architecture blueprint PDF and let me know your thoughts before Friday\'s sync.\r\n\r\nBest regards,\r\nJane Smith\r\nSenior Systems Architect', 
0, 1, 0, 'inbox', '2026-09-23 09:15:00', '2026-09-23 09:15:00', NULL),

-- 3: John's Sent reply to Jane (John's Sent folder)
(3, 2, 3, 2, 'Re: Q3 System Architecture Review & IIS Deployment Plan', 
'Hi Jane,\r\n\r\nThe blueprint looks thorough and well-engineered. I especially appreciate the request filtering isolation in web.config and defense-in-depth .htaccess files for Apache.\r\n\r\nLet us proceed with the staging deployment dry run tomorrow at 2:00 PM UTC.\r\n\r\nBest,\r\nJohn Doe', 
1, 0, 0, 'sent', '2026-09-23 11:20:00', '2026-09-23 11:20:00', NULL),

-- 4: Jane receives John's reply (Jane's Inbox)
(4, 2, 3, 2, 'Re: Q3 System Architecture Review & IIS Deployment Plan', 
'Hi Jane,\r\n\r\nThe blueprint looks thorough and well-engineered. I especially appreciate the request filtering isolation in web.config and defense-in-depth .htaccess files for Apache.\r\n\r\nLet us proceed with the staging deployment dry run tomorrow at 2:00 PM UTC.\r\n\r\nBest,\r\nJohn Doe', 
1, 0, 0, 'inbox', '2026-09-23 11:20:00', '2026-09-23 11:20:00', NULL),

-- 5: John's Draft email (Drafts folder)
(5, 2, 3, NULL, 'Draft: Proposed Mail Migration Guidelines for Q4', 
'Team,\r\n\r\nHere is the initial draft of our organizational migration schedule:\r\n- Phase 1: Local accounts provisioning & schema migration\r\n- Phase 2: Historical email archive imports\r\n- Phase 3: Cutover DNS MX and SPF/DKIM verification\r\n\r\n[Note: Need to confirm maintenance downtime window with Admin]', 
1, 0, 1, 'draft', '2026-09-24 07:45:00', '2026-09-24 07:45:00', NULL),

-- 6: Jane's Draft email (Jane's Drafts folder)
(6, 3, 1, NULL, 'Draft: Security Hardening & Penetration Testing Report', 
'Confidential Draft:\r\nSummary of internal penetration tests covering session fixation, CSRF tokens, and attachment MIME validation...', 
1, 0, 1, 'draft', '2026-09-24 08:10:00', '2026-09-24 08:10:00', NULL),

-- 7: Deleted Newsletter email for John (Trash folder)
(7, 1, 2, NULL, 'Weekly DevOps and Cloud Infrastructure Digest #84', 
'Catch up with the latest trends in containerization, IIS application pools, and database indexing.\r\n\r\nTo manage your newsletter preferences, click here.', 
1, 0, 0, 'trash', '2026-09-21 14:00:00', '2026-09-23 16:30:00', '2026-09-23 16:30:00'),

-- 8: Suspicious / Junk mail for John (Spam folder)
(8, 1, 2, NULL, 'URGENT: Claim your $500 gift voucher right now!', 
'Congratulations! You have been selected as a grand prize winner. Click here to confirm your details immediately: http://malicious-spam-link.biz/claim', 
0, 0, 0, 'spam', '2026-09-23 03:12:00', '2026-09-23 03:12:00', NULL),

-- 9: Jane sends request to Admin (Admin Inbox, Starred)
(9, 3, 1, NULL, 'Storage volume expansion request for IIS server', 
'Hello Admin,\r\n\r\nCould we increase the storage volume quota for the attachments directory (/storage/attachments) on the Windows Server 2019 instance? We anticipate heavier project documentation next month.\r\n\r\nThanks,\r\nJane Smith', 
0, 1, 0, 'inbox', '2026-09-23 16:00:00', '2026-09-23 16:00:00', NULL),

-- 10: Admin sends broadcast notification to John & Jane (John's Inbox, Starred)
(10, 1, 2, NULL, 'Scheduled Maintenance Notice: Windows Server 2019 Updates', 
'Dear Team,\r\n\r\nPlease note that our production server host will undergo routine security patching and IIS service recycling this Sunday between 02:00 and 04:00 UTC.\r\n\r\nExpected downtime: under 5 minutes during web service reload.\r\n\r\nRegards,\r\nSystem Administrator', 
1, 1, 0, 'inbox', '2026-09-24 06:00:00', '2026-09-24 06:00:00', NULL),

-- 11: Admin sent copy of the broadcast notification (Admin's Sent folder)
(11, 1, 2, NULL, 'Scheduled Maintenance Notice: Windows Server 2019 Updates', 
'Dear Team,\r\n\r\nPlease note that our production server host will undergo routine security patching and IIS service recycling this Sunday between 02:00 and 04:00 UTC.\r\n\r\nExpected downtime: under 5 minutes during web service reload.\r\n\r\nRegards,\r\nSystem Administrator', 
1, 0, 0, 'sent', '2026-09-24 06:00:00', '2026-09-24 06:00:00', NULL);

-- Preserve sender snapshots for messages so they survive user deletion.
UPDATE `emails` e
LEFT JOIN `users` u ON e.`sender_id` = u.`id`
SET e.`sender_email_snapshot` = u.`email`,
    e.`sender_name_snapshot` = NULLIF(TRIM(CONCAT(COALESCE(u.`first_name`, ''), ' ', COALESCE(u.`last_name`, ''))), '');

-- Demo label assignments (labels never hide a message from its folder)
INSERT INTO `email_labels` (`email_id`, `label_id`, `created_at`) VALUES
(1, 1, '2026-09-22 08:30:00'),
(2, 1, '2026-09-23 09:15:00'),
(2, 3, '2026-09-23 09:16:00'),
(10, 3, '2026-09-24 06:00:00');

-- ----------------------------------------------------------------------------
-- 3. Email Recipients (To, Cc, Bcc mappings)
-- ----------------------------------------------------------------------------
INSERT INTO `email_recipients` (`id`, `email_id`, `recipient_id`, `email_address`, `recipient_type`, `created_at`) VALUES
-- Email 1 (Admin -> John)
(1, 1, 2, 'john.doe@couriva.local', 'to', '2026-09-22 08:30:00'),
-- Email 2 (Jane -> John)
(2, 2, 2, 'john.doe@couriva.local', 'to', '2026-09-23 09:15:00'),
-- Email 3 & 4 (John -> Jane)
(3, 3, 3, 'jane.smith@couriva.local', 'to', '2026-09-23 11:20:00'),
(4, 4, 3, 'jane.smith@couriva.local', 'to', '2026-09-23 11:20:00'),
-- Email 5 (John Draft -> Jane)
(5, 5, 3, 'jane.smith@couriva.local', 'to', '2026-09-24 07:45:00'),
-- Email 6 (Jane Draft -> Admin)
(6, 6, 1, 'admin@couriva.local', 'to', '2026-09-24 08:10:00'),
-- Email 7 (Admin -> John Newsletter Trash)
(7, 7, 2, 'john.doe@couriva.local', 'to', '2026-09-21 14:00:00'),
-- Email 8 (Spam -> John)
(8, 8, 2, 'john.doe@couriva.local', 'to', '2026-09-23 03:12:00'),
-- Email 9 (Jane -> Admin)
(9, 9, 1, 'admin@couriva.local', 'to', '2026-09-23 16:00:00'),
-- Email 10 & 11 (Admin -> John 'to', Jane 'cc')
(10, 10, 2, 'john.doe@couriva.local', 'to', '2026-09-24 06:00:00'),
(11, 10, 3, 'jane.smith@couriva.local', 'cc', '2026-09-24 06:00:00'),
(12, 11, 2, 'john.doe@couriva.local', 'to', '2026-09-24 06:00:00'),
(13, 11, 3, 'jane.smith@couriva.local', 'cc', '2026-09-24 06:00:00');

-- ----------------------------------------------------------------------------
-- 4. Attachments
-- ----------------------------------------------------------------------------
INSERT INTO `attachments` (`id`, `email_id`, `original_filename`, `stored_filename`, `filepath`, `mime_type`, `filesize`, `created_at`) VALUES
(1, 2, 'architecture_blueprint_q3.pdf', 'att_seed_q3_blueprint.pdf', 'storage/attachments/att_seed_q3_blueprint.pdf', 'application/pdf', 154200, '2026-09-23 09:15:00'),
(2, 2, 'iis_fastcgi_settings.txt', 'att_seed_iis_settings.txt', 'storage/attachments/att_seed_iis_settings.txt', 'text/plain', 2048, '2026-09-23 09:15:00');

-- ----------------------------------------------------------------------------
-- 5. Contacts
-- ----------------------------------------------------------------------------
INSERT INTO `contacts` (`id`, `user_id`, `contact_name`, `contact_email`, `phone`, `company`, `notes`, `created_at`, `updated_at`) VALUES
-- Contacts for John Doe (user_id = 2)
(1, 2, 'Jane Smith', 'jane.smith@couriva.local', '+1 (555) 019-2834', 'Acme Solutions', 'Lead Systems Architect & Tech Reviewer', '2026-09-21 10:00:00', '2026-09-21 10:00:00'),
(2, 2, 'System Administrator', 'admin@couriva.local', '+1 (555) 010-0001', 'IT Operations', 'Internal system administrator support', '2026-09-21 10:05:00', '2026-09-21 10:05:00'),
(3, 2, 'Alice Walker', 'alice.walker@enterprise-partner.com', '+1 (555) 014-9922', 'Enterprise Partners Ltd.', 'Strategic account representative', '2026-09-22 14:20:00', '2026-09-22 14:20:00'),
(4, 2, 'David Chen', 'david.chen@cloudnetworks.io', '+1 (555) 017-8831', 'CloudNetworks Corp.', 'Network infrastructure lead', '2026-09-22 15:40:00', '2026-09-22 15:40:00'),

-- Contacts for Jane Smith (user_id = 3)
(5, 3, 'John Doe', 'john.doe@couriva.local', '+1 (555) 012-4411', 'Acme Solutions', 'Product Lead & Technical Coordinator', '2026-09-21 10:10:00', '2026-09-21 10:10:00'),
(6, 3, 'System Administrator', 'admin@couriva.local', '+1 (555) 010-0001', 'IT Operations', 'Internal system administrator support', '2026-09-21 10:12:00', '2026-09-21 10:12:00'),
(7, 3, 'Robert Vance', 'robert.vance@securityaudits.org', '+1 (555) 018-7722', 'CyberSec Audits LLC', 'External compliance auditor', '2026-09-23 11:00:00', '2026-09-23 11:00:00');

-- ----------------------------------------------------------------------------
-- 6. System Logs
-- Audit events across authentication, email operations, and administration
-- ----------------------------------------------------------------------------
INSERT INTO `system_logs` (`id`, `user_id`, `action`, `ip_address`, `user_agent`, `status`, `details`, `created_at`) VALUES
(1, 1, 'USER_LOGIN', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/128.0.0.0', 'SUCCESS', 'Administrator initial session authenticated.', '2026-09-20 08:05:00'),
(2, 1, 'USER_CREATED', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/128.0.0.0', 'SUCCESS', 'Created user account: john.doe@couriva.local', '2026-09-21 09:00:00'),
(3, 1, 'USER_CREATED', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/128.0.0.0', 'SUCCESS', 'Created user account: jane.smith@couriva.local', '2026-09-21 09:30:00'),
(4, 2, 'USER_LOGIN', '192.168.1.101', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/128.0.0.0', 'SUCCESS', 'John Doe authenticated successfully.', '2026-09-22 08:25:00'),
(5, 1, 'EMAIL_SENT', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)', 'SUCCESS', 'Welcome message sent to john.doe@couriva.local', '2026-09-22 08:30:00'),
(6, 3, 'USER_LOGIN', '192.168.1.102', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)', 'SUCCESS', 'Jane Smith authenticated successfully.', '2026-09-23 09:10:00'),
(7, 3, 'ATTACHMENT_UPLOADED', '192.168.1.102', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)', 'SUCCESS', 'Uploaded file architecture_blueprint_q3.pdf (154200 bytes)', '2026-09-23 09:14:00'),
(8, 3, 'EMAIL_SENT', '192.168.1.102', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)', 'SUCCESS', 'Email sent to john.doe@couriva.local with subject: Q3 System Architecture Review & IIS Deployment Plan', '2026-09-23 09:15:00'),
(9, 2, 'EMAIL_SENT', '192.168.1.101', 'Mozilla/5.0 (X11; Linux x86_64)', 'SUCCESS', 'Reply sent to jane.smith@couriva.local (email id: 3)', '2026-09-23 11:20:00'),
(10, 2, 'FAILED_LOGIN', '192.168.1.101', 'Mozilla/5.0 (X11; Linux x86_64)', 'FAILED', 'Failed login attempt for john.doe@couriva.local: invalid password.', '2026-09-23 21:04:12'),
(11, 2, 'USER_LOGIN', '192.168.1.101', 'Mozilla/5.0 (X11; Linux x86_64)', 'SUCCESS', 'John Doe authenticated successfully after retry.', '2026-09-23 21:05:00'),
(12, 1, 'EMAIL_SENT', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)', 'SUCCESS', 'Broadcast maintenance notification sent to all users.', '2026-09-24 06:00:00');

SET FOREIGN_KEY_CHECKS = 1;
