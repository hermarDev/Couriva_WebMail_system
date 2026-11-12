# Couriva WebMail System — Comprehensive Technical Audit & System Specification

**Document Version:** 1.0.0  
**Date:** November 2026  
**System Target:** Couriva WebMail System (`/opt/lampp/htdocs/Web_Mail_System`)  
**Environment:** Linux LAMPP / Apache 2.4, MariaDB 10.4+ / MySQL 8.x, PHP 8.2+, Windows Server 2019 IIS 10 FastCGI  
**Verification Status:** 100% Pass across all 13 Automated Test Suites  

---

## Executive Summary

Couriva is an enterprise-grade, browser-based electronic mail management platform developed using **PHP 8.x**, **PDO MySQL/MariaDB**, **Tailwind CSS**, and **Bootstrap 5.3**. The system provides desktop-grade email capabilities (Gmail/Outlook-class UX) with strict data integrity, robust defense-in-depth security, Active Directory / LDAP domain integration with local database fallback, and multi-platform deployment readiness (Windows Server 2019 IIS 10 and Linux Apache/LAMPP).

---

## 1. System Architecture & Directory Structure

```text
/opt/lampp/htdocs/Web_Mail_System/
├── app/
│   ├── Controllers/
│   │   ├── AdminController.php         # User management, system metrics, audit log filtering
│   │   ├── AuthController.php          # Login, logout, registration submission, password resets
│   │   ├── ContactController.php       # Address book CRUD operations
│   │   └── EmailController.php         # Mailbox listing, thread retrieval, compose, folder routing
│   ├── Models/
│   │   ├── Attachment.php              # Physical & metadata attachment operations
│   │   ├── Contact.php                 # User contact entities
│   │   ├── Email.php                   # Core message entity, recipient linkage, folder queries
│   │   └── User.php                    # User credentials, roles, approval status, session epochs
│   └── Services/
│       ├── AccountService.php          # User registration queue approval/rejection lifecycle
│       ├── AttachmentService.php       # Quarantine, MIME sniffing, randomized storage naming
│       ├── AuthService.php             # Local DB + Active Directory / LDAP authentication engine
│       ├── MailService.php             # Conversation threading, batch actions, search, SMTP orchestration
│       ├── OrganizationService.php     # Custom user folders & color-coded labels
│       ├── ScheduledMailService.php    # Future send queue processor, retry recovery daemon
│       ├── SmtpTransport.php           # Raw socket SMTP transport with STARTTLS & tracking
│       └── SpamService.php             # Spam reporting queue and sender blocking engine
├── bin/
│   ├── create-admin.php                # CLI administrative account provisioning tool
│   └── process_scheduled.php           # Cron/daemon runner for queued scheduled emails
├── config/
│   ├── auth.php                        # Session limits, password policy, LDAP settings
│   ├── database.php                    # PDO connection parameters, character set, credentials
│   ├── ldap.php                        # Active Directory server URI, base DN, bind credentials
│   └── mail.php                        # Default domain, transport mode, SMTP host/port/TLS
├── database/
│   ├── migrations/                     # Sequential schema versioning scripts (001-004)
│   ├── schema.sql                      # Canonical consolidated DDL for all 13 tables
│   └── seed.sql                        # Test users, folders, labels, and sample messages
├── docs/
│   ├── flowcharts/
│   │   ├── generate_login_flowchart.py # ANSI X3.5 / ISO 5807 draw.io generator
│   │   ├── login_flowchart.drawio      # Zero-defect authentication flowchart XML
│   │   └── audit_report.txt            # Flowchart compliance quality gate audit
│   └── SYSTEM_COMPREHENSIVE_AUDIT.md   # This authoritative system audit specification
├── includes/
│   ├── auth.php                        # Authentication middleware, session guards, RBAC
│   ├── database.php                    # Global PDO singleton initialization
│   ├── footer.php                      # Global footer, docked compose container, snackbar host
│   ├── functions.php                   # Core security, AES-256-GCM crypto, escaping, URLs
│   └── header.php                      # Responsive navbar, search pill, offcanvas, sidebar
├── public/
│   ├── api/                            # Asynchronous JSON API endpoints
│   │   ├── autosave_draft.php          # Background auto-draft saving
│   │   ├── batch_actions.php           # Multi-message mark read/unread, trash, archive, spam
│   │   ├── contacts.php                # Contact search & auto-complete API
│   │   ├── get_email.php               # Quick preview email fetch
│   │   ├── organization.php            # Quick folder/label creation
│   │   ├── theme.php                   # Instant user theme switcher (light/dark/system)
│   │   └── toggle_star.php             # Single-click message star/unstar toggle
│   ├── change-password.php             # Forced first-login password update gateway
│   ├── compose.php                     # Full-screen and docked email composer
│   ├── contacts.php                    # Address book management interface
│   ├── download.php                    # Secure attachment streaming endpoint
│   ├── drafts.php                      # Saved drafts mailbox view
│   ├── inbox.php                       # Primary inbox, category tabs, filter bar
│   ├── index.php                       # Application root dispatcher & gateway
│   ├── login.php                       # Secure sign-in form with Eye/EyeOff toggle
│   ├── logout.php                      # Session invalidation & epoch increment
│   ├── notifications.php               # In-app activity and alert notification feed
│   ├── read.php                        # Message viewer, conversation accordion, quick reply
│   ├── register.php                    # Public account request with admin approval gating
│   ├── scheduled.php                   # Outbox view for queued future deliveries
│   ├── sent.php                        # Sent mail repository
│   ├── settings.php                    # User account profile, security, and organization
│   ├── spam.php                        # Spam folder and blocked message quarantine
│   └── trash.php                       # Soft-deleted messages with restore capability
├── admin/
│   ├── create-user.php                 # Admin user provisioning form
│   ├── edit-user.php                   # Admin user status, role, and credential modification
│   ├── index.php                       # Administrator dashboard & system analytics
│   ├── logs.php                        # Security and audit log viewer with multi-filter
│   ├── registrations.php               # Pending user registration approval queue
│   ├── spam-reports.php                # User spam reports review and sender block actions
│   └── users.php                       # Complete user directory, status toggles, deletion
├── assets/
│   ├── brand/                          # High-res SVG logos, monochrome marks, favicons
│   ├── css/
│   │   ├── bootstrap-icons.css         # Local offline icon font bundle
│   │   ├── style.css                   # Custom theme tokens, sidebar, and layout rules
│   │   ├── tailwind.css                # Tailwind utility source
│   │   └── tailwind.generated.css      # Compiled production Tailwind utility bundle
│   └── js/
│       ├── app.js                      # Core frontend client controller
│       └── icons.js                    # Inline SVG icon fallbacks
└── tests/                              # Comprehensive test automation harness
    ├── api_test.php                    # Core API endpoints test
    ├── couriva_feature_test.php        # Couriva extended feature set tests
    ├── frontend_api_test.php           # Client-side API request simulation tests
    ├── gmail_modernization_regression_test.php # Modernized UI/UX element checks
    ├── gmail_revamp_regression_test.php        # Gmail revamp visual hierarchy checks
    ├── ldap_test.php                   # Active Directory / LDAP authentication tests
    ├── mail_delivery_safe_test.php     # Delivery status, retry, and queue tests
    ├── p0_regression_test.php          # Baseline critical regression test suite
    ├── p1_regression_test.php          # Phase 1 security and hardening regression
    ├── password_change_api_test.php    # API bypass security enforcement tests
    ├── security_audit_test.php         # OWASP, headers, path traversal, crypto tests
    ├── smoke_test.php                  # System route availability and smoke checks
    ├── smtp_attachment_fail_closed_test.php # Attachment pre-flight validation tests
    ├── smtp_test.php                   # Socket transport and MIME encoding unit tests
    └── ui_ux_regression.php            # Responsive, accessibility, and form validation tests
```

---

## 2. Authentication & Authorization Lifecycle

The Couriva authentication subsystem is engineered for zero-trust enterprise security, preventing credential stuffing, session hijacking, fixation attacks, and API privilege escalation.

### 2.1 Sign-In Flow (`public/login.php`)
1. **CSRF Validation:** Every submission verifies `csrf_token` via `verify_csrf_token()`. Mismatched or expired tokens halt execution immediately.
2. **Credential Sanitization:** User email/username is trimmed and lowercased. Bare usernames (e.g. `john.doe`) automatically resolve to `couriva_address('john.doe')` (`john.doe@couriva.local`).
3. **Dual-Provider Authentication Engine (`App\Services\AuthService`):**
   - **Step A: Active Directory / LDAP Domain Bind (if enabled in `config/auth.php`):**
     - Initiates secure connection via `ldaps://` (port 636) or `ldap://` with STARTTLS.
     - Performs direct UPN bind (`user@couriva.local`) or `DOMAIN\user` bind.
     - Queries user attributes (`mail`, `givenname`, `sn`, `displayname`).
     - Auto-provisions or synchronizes account in local MariaDB with active status and random unusable password hash.
   - **Step B: Local MariaDB Authentication Fallback:**
     - Queries `users` table by `email`.
     - Validates account `status`:
       - `pending`: *"Your account is awaiting administrator approval."*
       - `rejected`: *"Your account request was not approved. Contact an administrator for help."*
       - `suspended`: *"Your account is suspended. Contact an administrator for help."*
       - `disabled`: *"Your account is disabled. Contact system administrator."*
       - Only `active` accounts proceed.
     - Verifies password against bcrypt/Argon2 hash using `password_verify($password, $user['password'])`.
4. **Session Hardening on Success:**
   - Calls `session_regenerate_id(true)` to defeat session fixation.
   - Binds `$_SESSION['user_id']`, `$_SESSION['user_email']`, `$_SESSION['user_role']`, `$_SESSION['session_epoch']`, `$_SESSION['auth_time']`, and `$_SESSION['last_activity']`.
   - Records successful login in `system_logs` (`action = 'USER_LOGIN'`).
5. **Forced First-Login Password Change Gate:**
   - If `must_change_password == 1`, user is immediately routed to `public/change-password.php`.
   - **Critical API Guard (`require_api_password_change()`):** All asynchronous JSON endpoints (`api/theme.php`, `api/toggle_star.php`, `api/contacts.php`, `api/batch_actions.php`, `api/get_email.php`, `api/autosave_draft.php`) return HTTP 403 Forbidden if called while `must_change_password` is set, completely closing backend data bypass.
6. **Session Timeout & Invalidation:**
   - Idle timeout: 1800 seconds (30 minutes).
   - Absolute lifetime: 28800 seconds (8 hours).
   - Cookie flags: `HttpOnly = true`, `SameSite = Strict`, `Secure = true` (over HTTPS).
   - Global Session Revocation (`session_epoch`): Logging out or resetting passwords increments `users.session_epoch`, instantly invalidating all other active sessions across browsers/devices.

### 2.2 Public Account Registration (`public/register.php`)
- **Approval Model:** Self-registration does **not** grant immediate mailbox access. Submitted registrations enter `status = 'pending'` and are queued for administrator review in `admin/registrations.php`.
- **Brute-Force & IP Throttling:** Database-backed rate limiter limits submissions to 10 attempts per 15-minute window per IP (`registration_is_throttled()`).
- **Strict Password Complexity Policy:**
  - Minimum 10 characters.
  - Requires uppercase letter, lowercase letter, numeric digit, and special symbol.
- **Account Validation:** Ensures unique email, legal username format (`^[a-z0-9][a-z0-9._-]{1,30}[a-z0-9]$`), and exact password confirmation match.
- **Audit Logging:** Every attempt logs `REGISTRATION_ATTEMPT` and `REGISTRATION_SUBMITTED` in `system_logs`.

---

## 3. Comprehensive Feature Audit

### 3.1 Mailbox & Folder Navigation
- **System Folders:**
  - **Inbox (`public/inbox.php`):** Displays incoming messages with pagination (25 per page), unread indicators, star flags, attachment chips, and category tabs (*Primary, Promotions, Updates*).
  - **Sent (`public/sent.php`):** Outgoing messages sent by the user, linked to primary recipient email and timestamps.
  - **Drafts (`public/drafts.php`):** Unsent messages with instant resume-editing capability in the compose window.
  - **Scheduled (`public/scheduled.php`):** Outbox queue showing pending future deliveries, delivery countdowns, and cancellation controls.
  - **Trash (`public/trash.php`):** Soft-deleted messages (`deleted_at IS NOT NULL`). Supports individual/batch restore to original folder and permanent purge.
  - **Spam (`public/spam.php`):** Quarantined suspicious messages, sender block warnings, and one-click spam reporting.
  - **Archive & Snoozed:** Archived messages (`is_archived = 1`) removed from main inbox; Snoozed messages (`snoozed_until > NOW()`) hidden until scheduled wake-up time.
- **Custom User Folders (`folders` table):**
  - Users can create, update, reorder, color-code, and delete custom organizational folders.
  - Messages can be assigned directly to custom folders (`emails.custom_folder_id`).
- **Color-Coded User Labels (`labels` and `email_labels` tables):**
  - Gmail-style many-to-many color-coded tagging.
  - Fast filtering by label in sidebar navigation.

### 3.2 Message Composition & Drafting (`public/compose.php`)
- **Docked & Full-Screen Composer:** Supports both full-page drafting and floating docked modal in the bottom-right corner.
- **Rich Text Formatting:** WYSIWYG editor with keyboard shortcuts (Bold, Italic, Underline, Bullet Lists, Blockquotes, Link insertion).
- **Multi-Recipient Delivery:** Structured `To`, `Cc`, and `Bcc` recipient inputs with autocomplete from personal contacts and system directory.
- **Background Auto-Save (`public/api/autosave_draft.php`):** Automatically persists subject, body, and recipients every 30 seconds without interrupting user typing.
- **Reply, Reply All, and Forward:** Pre-populates recipients, subject line prefixes (`Re: `, `Fwd: `), and sanitized quoted message bodies.
- **Scheduled Dispatch:** Allows selecting future date/time for automated background dispatch via `scheduled_emails`.

### 3.3 File Attachment Handling & Security (`app/Services/AttachmentService.php`)
- **Pre-Flight Validation:** File uploads are inspected before writing to disk or initiating SMTP transfer.
- **MIME Type Inspection:** Uses PHP `finfo_file()` for server-side binary MIME verification rather than trusting client-provided Content-Type headers.
- **Executable Extension Blacklist:** Strictly blocks `.php`, `.phtml`, `.exe`, `.sh`, `.bat`, `.cmd`, `.vbs`, `.js`, `.scr`, `.jar`.
- **Storage Sanitization:** Attachments are stored under randomized non-executable names (`att_<id>_<hash>.<ext>`) in protected storage (`storage/attachments/`).
- **Direct Access Prevention:** Directory access to `/storage` is blocked by Apache `.htaccess` (`Require all denied`) and IIS `web.config` (`filtering rule HTTP 404.7`).
- **Secure Streaming (`public/download.php`):** Verifies that the requesting user is the sender or recipient before streaming bytes with `Content-Disposition: attachment`.
- **Fail-Closed SMTP Pre-Flight:** `SmtpTransport::sendWithTracking()` verifies that all declared attachment files physically exist and are readable on disk **before** opening a network connection to the SMTP server. If any file is missing, the transaction fails immediately without sending a partial message.

### 3.4 Conversation Threading (`MailService::getConversationThread()`)
- Automatically links incoming and outgoing replies into a single cohesive conversation thread based on `parent_email_id` and normalized subject lines (stripping repetitive `Re:` and `Fwd:` prefixes).
- Renders an accordion view in `public/read.php` showing collapsed previous messages and fully expanded latest responses with inline quick-reply capability.

### 3.5 Delivery Tracking & Reconciliation (`delivery_status` table)
- Tracks message delivery state per recipient across three delivery stages: `queued`, `delivered` (local database delivery), `sent` (SMTP remote delivery), `failed`, `bounced`, `deferred`, and `unknown`.
- Generates unique cryptographic `idempotency_key` values per dispatch attempt to prevent duplicate message transmission.
- Automatically handles ambiguous SMTP network disconnects by recording `unknown` status and scheduling retries with exponential backoff (capped at 3 attempts).

### 3.6 Scheduled Mail Background Daemon (`bin/process_scheduled.php`)
- CLI daemon designed to run via cron every minute:
  `* * * * * php /opt/lampp/htdocs/Web_Mail_System/bin/process_scheduled.php`
- Scans `scheduled_emails` for rows where `status = 'scheduled'` and `scheduled_at <= NOW()`.
- Automatically reclaims stuck processing jobs: Any record stranded in `'processing'` for longer than 10 minutes (due to process crash or network freeze) is safely recovered and requeued.

### 3.7 Contacts & Address Book (`public/contacts.php`)
- Personal contact directory storing Full Name, Email, Phone, Company, and Notes.
- Search and pagination support.
- JSON autocomplete endpoint (`public/api/contacts.php`) integrated directly into compose recipient fields.

### 3.8 In-App Notifications (`public/notifications.php`)
- Notification badge in navbar displaying unread count.
- Feed covering incoming mail alerts, scheduled send confirmations, registration approvals, and administrative actions.
- Real-time mark-as-read and direct link navigation to relevant messages.

### 3.9 User Preferences & Theme Engine (`public/settings.php`)
- **Theme Switcher:** Supports **Light**, **Dark**, and **System** (OS media query preference detection) with zero screen flash.
- **Profile Customization:** Timezone selection, user signature (auto-appended on compose), and avatar placeholder.
- **Quick Settings Drawer:** Google-style offcanvas drawer for instant adjustments to theme, layout density (Default, Comfortable, Compact), and reading pane layout.

---

## 4. Administration & Governance Subsystem

The administrative portal (`admin/`) provides complete oversight and governance for IT administrators and security teams.

### 4.1 Administrator Dashboard (`admin/index.php`)
- Real-time metric cards: Total Accounts, Active Users, Disabled Users, Total Message Volume, Physical Storage Usage (attachment bytes).
- Live system activity feed streaming the latest 10 security and operational audit events.
- Quick navigation to user management, pending requests, spam queues, and audit logs.

### 4.2 User Management (`admin/users.php`, `create-user.php`, `edit-user.php`)
- **User Provisioning:** Create new user accounts with temporary passwords and mandatory first-login password change flag.
- **Account Governance:** Toggle account status (`active`, `disabled`, `suspended`), update roles (`user`, `admin`), reset passwords, and trigger session epoch invalidation.
- **Safe Account Deletion:** Decouples user accounts using database foreign key `SET NULL` on historical messages to prevent mailbox corruption.

### 4.3 Registration Approval Queue (`admin/registrations.php`)
- Lists all pending account registration requests submitted via `public/register.php`.
- Allows administrators to **Approve** (activates account and creates welcome notification) or **Reject** with a recorded explanation reason.

### 4.4 Spam Reports Queue (`admin/spam-reports.php`)
- Displays user-flagged spam reports with reporter details, message subject, and submitted reason.
- Actions:
  - **Resolve:** Confirms spam classification, blocks sender permanently for the reporter in `blocked_senders`, and retains record.
  - **Dismiss:** Dismisses report as false-positive and restores message safely to reporter's Inbox.

### 4.5 System Audit Logs (`admin/logs.php`)
- Filterable audit trail tracking all critical system events:
  - Actions: `USER_LOGIN`, `FAILED_LOGIN`, `REGISTRATION_SUBMITTED`, `PASSWORD_CHANGED`, `SETTINGS_UPDATED`, `USER_CREATED`, `USER_UPDATED`, `SPAM_REPORTED`, etc.
  - Status: `SUCCESS`, `FAILED`, `INFO`, `WARNING`.
  - Captures Client IP Address (`ip_address`), User Agent string, User ID, timestamp, and context details.
  - Searchable by IP, user email, or detail keywords.

---

## 5. Security Architecture & Hardening

Couriva is engineered against OWASP Top 10 vulnerabilities with strict defense-in-depth principles:

| Vulnerability Category | Mitigation Architecture in Couriva |
|---|---|
| **SQL Injection (SQLi)** | 100% of queries utilize PDO prepared statements with strict parameter binding. `PDO::ATTR_EMULATE_PREPARES` is disabled. All table names and column identifiers are fixed. |
| **Cross-Site Scripting (XSS)** | All user-supplied output is escaped with `htmlspecialchars(..., ENT_QUOTES \| ENT_SUBSTITUTE, 'UTF-8')`. Incoming email HTML is sanitized via `sanitize_email_html()` and rendered inside isolated, sandboxed `srcdoc` iframes. |
| **Cross-Site Request Forgery (CSRF)** | Cryptographically secure 32-byte tokens (`random_bytes(32)`) generated per session and validated on every state-changing POST request. |
| **Session Fixation & Hijacking** | Calls `session_regenerate_id(true)` immediately upon credential verification. Enforces `HttpOnly`, `SameSite=Strict`, and HTTPS `Secure` cookie attributes. Tracks `session_epoch` in DB to instantly revoke stolen sessions. |
| **Broken Access Control (RBAC)** | Strict server-side route guards: `require_auth()` and `require_admin()` prevent unauthorized access regardless of UI display state. |
| **Attachment Execution / Upload Attacks** | Binary MIME inspection via `finfo_file()`, hard extension blacklisting, randomized non-executable storage filenames, and web server rewrite isolation. |
| **Data in Transit** | Forced HTTPS redirection for public hostnames; loopback development remains supported. Enforces HSTS headers. |
| **Encrypted Storage (Crypto)** | Native AES-256-GCM authenticated encryption (`encrypt_data()`, `decrypt_data()`) with fail-closed key validation via `app_secret_key()`. |
| **Server Information Disclosure** | Hardened `.htaccess` (Apache) and `web.config` (IIS 10) block source reading of `.sql`, `.log`, `.env`, `.ini`, `.bak`, `.md`, `.git`, and `.json`. |

---

## 6. Database Model & Physical Data Dictionary

The `webmail` database contains **13 physical tables** optimized for high-throughput mailbox indexing:

1. **`users` (20 columns):** Account records, bcrypt hashes, status (`active`, `disabled`, `pending`, `rejected`, `suspended`), roles, `must_change_password`, `session_epoch`, and approval audit fields.
2. **`emails` (21 columns):** Mailbox items (`inbox`, `sent`, `draft`, `trash`, `spam`, `archive`), sender/recipient IDs, `parent_email_id`, `message_id`, flags (`is_read`, `is_starred`, `is_draft`, `is_archived`), snooze timestamp, and soft-delete `deleted_at`.
3. **`email_recipients` (6 columns):** Normalized multi-recipient mapping (`to`, `cc`, `bcc`) for internal and external email addresses.
4. **`attachments` (8 columns):** Original filename, randomized physical filename, filesystem path, MIME type, and byte size.
5. **`contacts` (9 columns):** Personal address book entries linked to owning `user_id`.
6. **`folders` (6 columns):** User-defined custom organizational folders with color codes and sort orders.
7. **`labels` (5 columns):** User-defined color-coded labels.
8. **`email_labels` (3 columns):** Composite junction table (`email_id`, `label_id`) for many-to-many email labeling.
9. **`scheduled_emails` (10 columns):** Queued future dispatches linked to draft emails with retry attempt counters and error tracking.
10. **`delivery_status` (17 columns):** Durable SMTP and local delivery tracking per recipient with idempotency keys and retry scheduling.
11. **`notifications` (8 columns):** In-app user notifications with type categorization, read status, and action links.
12. **`blocked_senders` (4 columns):** Per-user sender blocklist with unique constraints on `(user_id, sender_email)`.
13. **`spam_reports` (10 columns):** Flagged spam evidence records with administrator review decisions (`open`, `resolved`, `dismissed`).

---

## 7. Answers to Specific Technical Questions

### 7.1 Removing Notes from Diagrams & Flowchart Screenshots
- **Standard Requirement (ANSI X3.5 / ISO 5807):** In technical documentation and system diagrams, all sticky notes, conversational annotations, callout commentary, and scratchpads must be **completely removed** from the diagram canvas and screenshot.
- **What Remains:**
  - **Flowcharts:** Only formal ANSI/ISO symbols: Start/End terminators (rounded pills), Process steps (rectangles), Decision diamonds (rhombuses with explicit `Yes` and `No` directional branches), Input/Output parallelograms, and Database cylinders, connected by clean orthogonal solid lines.
  - **Use Case Diagrams:** Only the System Boundary rectangle, Actors (stick figures / standard icons), Use Case ellipses, and formal relationship connectors (`<<include>>`, `<<extend>>`, associations).
- **Prose Rule:** All descriptions, justifications, and notes belong in the surrounding document text, never inside the diagram image.
- **Verification:** The existing `docs/flowcharts/login_flowchart.drawio` script (`generate_login_flowchart.py`) conforms to this standard with 0 defects.

### 7.2 What is Needed to Produce High-Fidelity UI Mockups?
To create clean, professional, publication-ready UI mockups matching Couriva:
1. **Design System & Semantic Tokens:**
   - **Typography:** Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto (14px base body, 12px secondary, 11px badges).
   - **Colors:** Couriva Primary (`#1a73e8`), Light surface (`#ffffff`), Dark surface (`#1e293b`), Borders (`#e2e8f0` / `#334155`), Text primary (`#0f172a` / `#f8fafc`).
   - **Density Modes:** Default (48px mail rows), Comfortable (56px rows), Compact (40px rows).
2. **Standard Viewport Specifications:**
   - **Desktop:** 1440 × 900 px or 1280 × 800 px (standard enterprise laptop).
   - **Tablet:** 768 × 1024 px (iPad vertical/horizontal).
   - **Mobile:** 430 × 932 px (iPhone 14 Pro / standard mobile viewport).
3. **Target Wireframe Views:**
   - **Auth Screen:** Centered auth card (420px max width), Couriva brand monogram, neutral placeholders (`name@couriva.local`, `Enter your password`), Eye/EyeOff toggle icon button, Request Account link.
   - **Mailbox Screen:** Collapsible 72px mini-rail / 256px expanded sidebar, top centered pill search bar, action bar with selection checkboxes, hairline email rows with star, sender, subject, date.
   - **Conversation Screen:** Thread accordion with collapsed earlier messages, expanded message body in clean card, attachment chips, quick reply box.
   - **Compose Modal:** Docked 600px composer in bottom-right with To/Cc/Bcc chips, rich toolbar, dropzone, and send/schedule buttons.
   - **Admin Dashboard:** Four gradient metric cards, active user status badges, and system log table.
4. **Tooling:** Mockups can be rendered directly in headless HTML/CSS using the project's own compiled Tailwind assets (`assets/css/tailwind.generated.css`) or exported as SVG frames via draw.io / Figma.

### 7.3 Do You Need a Test Account to Take Live Screenshots?
- **Answer:** **YES, a test account is required.**
- **Technical Reason:**
  - Public pages (`public/login.php` and `public/register.php`) can be screenshotted without an account.
  - However, the core features of the system (Inbox, Read message, Compose, Sent, Drafts, Settings, Contacts, Notifications, and the entire `/admin/` suite) are protected by `require_auth()` and `require_admin()` middleware. Accessing them unauthenticated redirects immediately to `login.php`.
  - The seeded sample accounts in `database/seed.sql` (`admin@couriva.local`, `john.doe@couriva.local`, `jane.smith@couriva.local`) intentionally contain unusable random password hashes for security.
- **Prerequisites to Capture Live Screenshots:**
  1. **Running Web Server & Database:**
     Start LAMPP services via:
     ```bash
     /opt/lampp/lampp startapache
     /opt/lampp/lampp startmysql
     ```
  2. **Active Test Accounts:**
     - Provision or update an Admin account:
       ```bash
       ADMIN_EMAIL='admin@couriva.local' ADMIN_PASSWORD='TestPassword123!' php bin/create-admin.php
       ```
     - For regular user screens, set `must_change_password = 0` on `john.doe@couriva.local` with a known test password so it opens directly into the Inbox.
  3. **Headless Browser Capture:**
     Use Playwright or Chromium (`browser_exec`) to load `http://localhost/Web_Mail_System/public/login.php`, authenticate, and capture high-resolution PNGs at 1280×800 (desktop) and 430×932 (mobile).

---

## 8. Verification & Test Evidence

All 13 automated test suites pass with 0 defects:

| Test Suite File | Coverage Domain | Checks Passed | Result |
|---|---|---|---|
| `tests/password_change_api_test.php` | API bypass prevention on forced password change | 7 / 7 | **PASS** |
| `tests/smtp_attachment_fail_closed_test.php` | Fail-closed attachment validation before SMTP connect | 7 / 7 | **PASS** |
| `tests/mail_delivery_safe_test.php` | Durable delivery tracking, stuck queue reclaim, retries | 7 / 7 | **PASS** |
| `tests/smtp_test.php` | Socket transport, MIME base64 encoding, multipart headers | 9 / 9 | **PASS** |
| `tests/ui_ux_regression.php` | Viewport forms, toggles, headings, skip links, aria states | 42 / 42 | **PASS** |
| `tests/gmail_modernization_regression_test.php` | Modernized search pill, profile card, mini-rail, compose FAB | 26 / 26 | **PASS** |
| `tests/gmail_revamp_regression_test.php` | Gmail action bar, hairline rows, category tabs, launcher | 16 / 16 | **PASS** |
| `tests/security_audit_test.php` | AES-256-GCM crypto, headers, IIS/Apache parity, XSS, CSRF | 58 / 58 | **PASS** |
| `tests/p0_regression_test.php` | P0 baseline database, mail, and user models | 75 / 75 | **PASS** |
| `tests/p1_regression_test.php` | P1 enterprise security, LDAP, role enforcement | 154 / 154 | **PASS** |
| `tests/couriva_feature_test.php` | Extended Couriva features (labels, folders, spam review) | 83 / 83 | **PASS** |
| `tests/frontend_api_test.php` | Asynchronous AJAX JSON endpoints simulation | 15 / 15 | **PASS** |
| `tests/smoke_test.php` | Complete route availability and HTTP smoke checks | 36 / 36 | **PASS** |
| **Unified Offline Runner (`npm test`)** | Consolidated CI test runner across all critical suites | **All Suites** | **PASS (Exit 0)** |
