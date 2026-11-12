# Couriva WebMail System — Windows Server 2019 & IIS 10 Testing and Acceptance Specification

**Document:** `COURIVA_TESTING_AND_ACCEPTANCE.md`  
**Target Environment:** Windows Server 2019 Standard/Datacenter, IIS 10, PHP 8.2+ FastCGI, MariaDB 10.4+  
**Application Target:** `/opt/lampp/htdocs/Web_Mail_System`  
**Standard:** ISO/IEC/IEEE 29119 Software Testing Standards  
**Pass Criteria:** 100% of P0 and P1 Test Cases Must Pass with Zero Tolerated Vulnerabilities  

---

## 1. Test Suite Architecture & Verification Matrix

```text
┌────────────────────────────────────────────────────────────────────────┐
│               Couriva Enterprise Acceptance Test Engine                │
├──────────────────────────┬──────────────────────────┬──────────────────┤
│ Layer 1: IIS Security    │ Layer 2: Identity & Auth │ Layer 3: MailOps │
│ - Request Filtering      │ - AD / LDAP Primacy      │ - Fail-Closed Att│
│ - Hidden Segments (docs) │ - Local Admin DR Path    │ - Delivery Status│
│ - Header De-duplication  │ - Forced Password Gate   │ - Scheduler Lock │
│ - FastCGI NTS Pipeline   │ - Session Epoch Bump     │ - Sync Backup/DR │
└──────────────────────────┴──────────────────────────┴──────────────────┘
```

---

## 2. Comprehensive Test Cases & Acceptance Matrix

### Category 1: IIS 10 Request Filtering & Sensitive Resource Protection

| Test ID | Test Title | Execution Procedure | Expected Result | Priority |
|---|---|---|---|:---:|
| **TC-IIS-01** | Internal Config Directory Isolation | HTTP GET `http://localhost/config/database.php` | Server returns **HTTP 404.8** (Hidden Segment Denied). Source code is not executed and not leaked. | **P0** |
| **TC-IIS-02** | Physical Storage Directory Isolation | HTTP GET `http://localhost/storage/attachments/` | Server returns **HTTP 404.8**. Directory listing or direct file download is denied. | **P0** |
| **TC-IIS-03** | Source & Database Script Isolation | HTTP GET `http://localhost/database/schema.sql` | Server returns **HTTP 404.8** (Hidden Segment) or **HTTP 404.7** (`.sql` extension denied). | **P0** |
| **TC-IIS-04** | Internal Documentation Isolation | HTTP GET `http://localhost/docs/flowcharts/audit_report.txt` | Server returns **HTTP 404.8** (`docs` hidden segment) or **HTTP 404.7** (`.txt` denied). | **P1** |
| **TC-IIS-05** | Sensitive Environment File Denial | HTTP GET `http://localhost/.env` | Server returns **HTTP 404.7** (`.env` extension denied). | **P0** |
| **TC-IIS-06** | FastCGI Handler Mapping Verification | HTTP GET `http://localhost/public/login.php` | Server returns **HTTP 200 OK** with rendered HTML, confirming PHP FastCGI execution. | **P0** |
| **TC-IIS-07** | HTTP Security Headers Verification | Inspect response headers of `http://localhost/public/login.php` | Contains `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`, single CSP. Zero duplicate headers. | **P1** |

---

### Category 2: Identity, Active Directory & Authentication Security

| Test ID | Test Title | Execution Procedure | Expected Result | Priority |
|---|---|---|---|:---:|
| **TC-AUTH-01** | Active Directory Primary Bind | Submit valid AD credentials for domain user in `login.php` | Authenticates via LDAP, auto-provisions or syncs local record with `auth_source = 'ldap'`, logs in to Inbox. | **P0** |
| **TC-AUTH-02** | Active Directory Account Lockout Enforcement | Lock user in ADUC / Domain Controller, attempt login in Couriva | Login rejected immediately with "Active Directory credentials rejected." **Zero fallback to local DB.** | **P0** |
| **TC-AUTH-03** | Local Emergency Admin Login | Submit valid credentials for `admin@couriva.local` when LDAP is disabled or unreachable | Authenticates cleanly against MariaDB local hash. Serves as break-glass disaster recovery. | **P0** |
| **TC-AUTH-04** | Status Enforcement (`pending`) | Register new account via `public/register.php`, attempt immediate login | Login rejected with *"Your account is awaiting administrator approval."* | **P1** |
| **TC-AUTH-05** | Status Enforcement (`disabled`/`suspended`) | Admin sets user status to `disabled` in `admin/users.php`, user attempts login | Login rejected with *"Your account is disabled. Contact system administrator."* Active sessions invalidated. | **P1** |
| **TC-AUTH-06** | CSRF Token Enforcement | Submit login form with forged or omitted `csrf_token` | Login rejected with error: *"Session security token expired. Please try again."* No auth attempt logged. | **P1** |
| **TC-AUTH-07** | IP Rate Limiting on Registration | Submit 11 registration requests within 15 minutes from same IP | 11th attempt is blocked with: *"Too many registration attempts. Please wait 15 minutes and try again."* | **P1** |

---

### Category 3: Administrator-Governed Password Recovery & Route Lockdown

| Test ID | Test Title | Execution Procedure | Expected Result | Priority |
|---|---|---|---|:---:|
| **TC-RECOV-01** | Public Self-Service Recovery Absence | Probe `/forgot-password.php`, `/reset-password.php`, `/recover.php` | All return **HTTP 404**; zero public self-service reset endpoints exist. | **P0** |
| **TC-RECOV-02** | Admin Temporary Password Issuance | Admin resets local user password in `admin/edit-user.php` | Sets temporary password, sets `must_change_password = 1`, increments `session_epoch = session_epoch + 1`. | **P0** |
| **TC-RECOV-03** | Prior Session Invalidation on Reset | User has active session in Browser A; Admin resets password; User refreshes Browser A | Browser A session is immediately revoked (epoch mismatch), user redirected to login. | **P0** |
| **TC-RECOV-04** | Mandatory Password Change Redirect | User signs in with temporary password | Immediately redirected to `public/change-password.php`. Flash warning displayed. | **P0** |
| **TC-RECOV-05** | Protected Web Route Lockdown | User holding temp password attempts direct GET `/public/inbox.php` | Request intercepted; redirected back to `public/change-password.php`. Mailbox inaccessible. | **P0** |
| **TC-RECOV-06** | Complete JSON API Lockdown | User holding temp password calls `POST /public/api/toggle_star.php` | Endpoint returns **HTTP 403 Forbidden** with `{"success":false,"error":"You must change your password..."}`. | **P0** |
| **TC-RECOV-07** | Password Complexity Enforcement | User enters simple new password (e.g. `secret12`) on `change-password.php` | Rejected with complexity error: requires >=10 chars, uppercase, lowercase, digit, and symbol. | **P1** |
| **TC-RECOV-08** | Audit Log Integrity | Inspect `admin/logs.php` after admin password reset | Action `PASSWORD_RESET` is logged with Admin ID and User ID. Plaintext password is **absent**. | **P1** |

---

### Category 4: Mail Operations, Attachment Security & Delivery Reconciliation

| Test ID | Test Title | Execution Procedure | Expected Result | Priority |
|---|---|---|---|:---:|
| **TC-MAIL-01** | Fail-Closed Missing Attachment Pre-Flight | Compose email with declared attachment whose physical file was removed from `storage/attachments/` | `SmtpTransport::sendWithTracking()` fails closed **before** opening SMTP connection; transaction rolls back safely. | **P0** |
| **TC-MAIL-02** | Executable Upload Sanitization | Attempt to upload `exploit.php` or `payload.exe` via compose form | Upload rejected by `AttachmentService`. Executable extension blocked, file discarded. | **P0** |
| **TC-MAIL-03** | Storage Path Obfuscation | Inspect physical attachment stored in `storage/attachments/` | Stored under randomized name (e.g. `att_15_7c13df408bad.pdf`), original filename kept in DB only. | **P1** |
| **TC-MAIL-04** | Secure Attachment Streaming | User B attempts to download attachment belonging to User A via `public/download.php?id=X` | Access denied (HTTP 403 / redirect). Only sender and recipient can stream file bytes. | **P1** |
| **TC-MAIL-05** | Durable Delivery Status Tracking | Send message to external SMTP recipient | Record created in `delivery_status` with unique `idempotency_key`, recipient email, and initial `queued` status. | **P1** |
| **TC-MAIL-06** | Ambiguous SMTP Network Disconnect | Simulate network reset during SMTP `DATA` transmission | `delivery_status` updated to `unknown`; scheduled for retry with exponential backoff (max 3 retries). | **P1** |

---

### Category 5: Windows Task Scheduler & Background Worker Concurrency

| Test ID | Test Title | Execution Procedure | Expected Result | Priority |
|---|---|---|---|:---:|
| **TC-CRON-01** | Worker Single-Instance File Lock | Launch two simultaneous instances of `php bin/process_scheduled.php` | Second instance acquires `flock(..., LOCK_EX | LOCK_NB)` failure, logs "already running", and exits 0 cleanly. | **P1** |
| **TC-CRON-02** | Scheduled Mail Due Dispatch | Insert message into `scheduled_emails` with `scheduled_at = NOW() - 1 MIN`, run worker | Worker claims row (`status = 'processing'`), delivers message, marks `status = 'sent'`, deletes draft. | **P1** |
| **TC-CRON-03** | Stuck Job Automatic Recovery | Create row in `scheduled_emails` with `status = 'processing'` and `updated_at = NOW() - 15 MIN`, run worker | Worker startup query detects stale processing job (>10 min), automatically reclaims to `'scheduled'`, and delivers it. | **P1** |
| **TC-CRON-04** | Windows Task Scheduler Return Code | Trigger task via `schtasks /run /tn "Couriva-ScheduledMailProcessor"` | Task executes and completes with `Last Result = 0x0` (Success). | **P2** |

---

### Category 6: Backup & Disaster Recovery Synchronization

| Test ID | Test Title | Execution Procedure | Expected Result | Priority |
|---|---|---|---|:---:|
| **TC-DR-01** | Coordinated DB & Storage Snapshot | Execute `bin/backup-couriva.ps1` | Creates timestamped directory containing `webmail_backup.sql` and `attachments_backup.zip`. | **P2** |
| **TC-DR-02** | Backup Referential Integrity Verification | Run verification script checking all `attachments.stored_filename` in SQL dump against zip archive | 100% of referenced attachment files exist in the archive. Zero dangling references. | **P2** |
| **TC-DR-03** | Staging Restore Dry-Run | Restore `webmail_backup.sql` and extract `attachments_backup.zip` to clean test environment | Application starts cleanly, users log in, and all attachment downloads open without corruption. | **P1** |

---

## 3. Test Execution Commands & Verification Scripts

### Running Offline Test Harness
The application test suite can be run on both Windows (PowerShell) and Linux:

```bash
# Run all consolidated offline test suites
npm test
```

### Direct PHP CLI Test Suite Matrix
```bash
php tests/password_change_api_test.php         # Verifies API lockdown during forced password change
php tests/smtp_attachment_fail_closed_test.php # Verifies fail-closed attachment pre-flight check
php tests/mail_delivery_safe_test.php          # Verifies delivery tracking and stuck-queue reclaim
php tests/security_audit_test.php              # Verifies AES-256-GCM crypto, headers, IIS parity
php tests/ui_ux_regression.php                 # Verifies form controls, Eye/EyeOff toggles, aria states
php tests/p1_regression_test.php               # Verifies P1 enterprise security regression
php tests/ldap_test.php                        # Verifies Active Directory bind & fallback handling
```

---

## 4. Acceptance Sign-Off Criteria

Production deployment to Windows Server 2019 IIS 10 is authorized **only** when:
1. **P0 Gates (100% Pass):**
   - Active Directory accounts cannot bypass domain suspension via local fallback.
   - FastCGI handler is registered and functional without hardcoding path conflicts.
   - Public resources are strictly segregated; internal directories return HTTP 404.8.
   - Public self-service password reset is absent; temporary password flow forces immediate update.
2. **P1 Gates (100% Pass):**
   - `web.config` blocks `.drawio`, `.txt`, and `docs/`.
   - Security response headers contain zero browser-conflicting duplicates.
   - Windows Task Scheduler worker executes with non-blocking concurrency locking.
   - NTFS ACLs restrict modify permissions to `storage/` and session paths only.
3. **P2/P3 Gates:**
   - Turnkey backup and recovery script verified with zero dangling attachments.
   - Unified password complexity enforced across all forms.
