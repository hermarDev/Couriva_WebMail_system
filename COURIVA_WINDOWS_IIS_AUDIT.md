# Couriva WebMail System — Windows Server 2019 & IIS 10 Production Audit

**System:** Couriva WebMail System (`/opt/lampp/htdocs/Web_Mail_System`)  
**Target Environment:** Windows Server 2019 Standard/Datacenter, Internet Information Services (IIS) 10.0, PHP 8.2+ FastCGI (NTS x64), MariaDB 10.4+ / MySQL 8.0  
**Audit Date:** November 2026  
**Audit Scope:** IIS Architecture, `web.config`, FastCGI Handlers, Request Filtering, NTFS Permissions, Active Directory/LDAP vs Local DB Isolation, Password Recovery, Background Workers, Backup/Restore, UI/UX  
**Classification:** Proprietary / Technical Security Audit  

---

## 1. Executive Summary

An exhaustive technical audit of the Couriva WebMail System was conducted to evaluate its readiness, security posture, and architectural compatibility for enterprise deployment on **Windows Server 2019 with IIS 10, PHP FastCGI, and MariaDB/MySQL**.

Couriva demonstrates solid software engineering fundamentals: 100% PDO prepared statements, fail-closed attachment validation, AES-256-GCM cryptographic primitives, CSRF token validation, session epoch revocation, and no default credentials. However, transitioning from a Linux/Apache development environment to an enterprise **Windows Server 2019 IIS 10** production deployment introduces critical operating system, web server pipeline, and identity management requirements that must be resolved prior to production cutover.

### Summary of Audit Findings by Severity

| Severity Level | Count | Definition |
|---|:---:|---|
| **P0 (Critical)** | 2 | Flaws that could lead to account takeover, domain restriction bypass, or service outage. |
| **P1 (High)** | 4 | Security or operational defects compromising directory privacy, worker concurrency, or header integrity. |
| **P2 (Medium)** | 5 | Inconsistent policies, memory/process leak risks, or backup desynchronization vulnerabilities. |
| **P3 (Low)** | 3 | Minor configuration cleanups, documentation enhancements, and UI polish items. |

---

## 2. Prioritized Audit Findings Matrix

| Finding ID | Area | Severity | Summary | Impact |
|---|---|:---:|---|---|
| **SEC-IIS-01** | Identity / Auth | **P0** | Hybrid Auth: Local DB fallback allows bypassing Active Directory account disablement / policies | Critical authentication bypass |
| **SEC-IIS-02** | IIS Handlers | **P0** | Hardcoded FastCGI path `C:\PHP\php-cgi.exe` fails if PHP is installed in standard Windows Server locations | Complete site 500.0 outage |
| **SEC-IIS-03** | Request Filtering | **P1** | `docs/` folder missing from `hiddenSegments`; non-filtered extensions (`.txt`, `.drawio`) directly downloadable | Architecture & log leakage |
| **SEC-IIS-04** | FastCGI / Headers | **P1** | Conflicting/Duplicate Security Headers between IIS `web.config` and PHP `send_security_headers()` | CSP breakage / browser rejection |
| **SEC-IIS-05** | Background Jobs | **P1** | `bin/process_scheduled.php` lacks atomic process locking (`flock`) for Windows Task Scheduler | Race conditions, duplicate SMTP |
| **SEC-IIS-06** | NTFS Permissions | **P1** | Lack of explicit Windows NTFS ACL specification for `IUSR` and `IIS_AppPool\<AppPool>` | Permission denial or code overwrite |
| **SEC-IIS-07** | Backup & DR | **P2** | No synchronized backup procedure between MariaDB dump and `storage/attachments/` on Windows | Dangling records, data loss |
| **SEC-IIS-08** | FastCGI Recycling | **P2** | Missing FastCGI process recycling limits (`instanceMaxRequests` vs `PHP_FCGI_MAX_REQUESTS`) | PHP memory exhaustion |
| **SEC-IIS-09** | Password Policy | **P2** | Inconsistent password complexity between `register.php` (10 chars + 4 classes) and `change-password.php` (8 chars) | Weakened account recovery |
| **SEC-IIS-10** | IIS Site Topology | **P2** | Root physical path vs `public/` folder: `admin/` and `assets/` exposed at top level | Architectural isolation risk |
| **SEC-IIS-11** | Mail Queue | **P2** | Windows Task Scheduler execution requires dedicated CLI error logging and non-zero exit monitoring | Silent job failure |
| **SEC-IIS-12** | SSL / TLS Binding | **P3** | URL Rewrite HTTP->HTTPS rule does not handle Windows Server SNI / HSTS preload flags | Minor redirect overhead |
| **SEC-IIS-13** | UI/UX Form Guards | **P3** | Temporary password generation in `admin/edit-user.php` lacks 1-click cryptographically secure generator | Admin operational friction |
| **SEC-IIS-14** | IIS Logs | **P3** | W3C IIS logging not correlated with Couriva internal `system_logs` audit trail | Audit reconciliation delay |

---

## 3. Deep-Dive Findings & Technical Evidence

### 3.1 SEC-IIS-01: Hybrid Auth Local Fallback Bypasses Active Directory Restrictions (P0)
- **Location:** `/opt/lampp/htdocs/Web_Mail_System/app/Services/AuthService.php:59-74`, `/opt/lampp/htdocs/Web_Mail_System/config/ldap.php:56`
- **Code Inspection:**
  ```php
  // 1. Attempt LDAP / Active Directory if enabled
  if (!empty($this->ldapConfig['enabled'])) {
      $ldapResult = $this->attemptLdapAuth($email, $password);
      if ($ldapResult['success']) {
          return $ldapResult;
      }
      if (empty($this->ldapConfig['fallback_to_local'])) {
          return ['success' => false, 'message' => $ldapResult['message'] ?? 'Invalid email or password.'];
      }
  }
  // 2. Local Database Authentication Fallback
  return $this->attemptLocalAuth($email, $password);
  ```
- **Evidence & Vulnerability:**
  In `database/schema.sql`, the `users` table lacks an `auth_source` column (`local` vs `ldap`). In `config/ldap.php`, `fallback_to_local` defaults to `true`.
  When an enterprise employee is terminated or suspended, the domain administrator disables their Active Directory account. However, if that user had a local password hash in MariaDB (e.g. from initial setup, a prior local account, or administrator reset), `AuthService` will fail the AD bind and seamlessly fall through to `attemptLocalAuth()`. The terminated employee can authenticate successfully against the local database, completely bypassing Windows Active Directory security boundaries.
- **Recommended Remediation:**
  1. Add an explicit column `auth_source ENUM('local', 'ldap') NOT NULL DEFAULT 'local'` to `users`.
  2. In `AuthService::authenticate()`, if a user's record has `auth_source === 'ldap'`, **never** fall back to local authentication under any circumstances.
  3. If AD authentication fails for an AD-managed user (bad credentials, account locked, account disabled, password expired), terminate the login attempt immediately.
  4. Restrict local authentication fallback exclusively to designated emergency administrative accounts (`admin@couriva.local`) or local-only accounts.

---

### 3.2 SEC-IIS-02: Hardcoded FastCGI Handler Path in `web.config` (P0)
- **Location:** `/opt/lampp/htdocs/Web_Mail_System/web.config:26-32`
- **Code Inspection:**
  ```xml
  <handlers>
      <clear />
      <add name="PHP_via_FastCGI" path="*.php" verb="GET,HEAD,POST,PUT,DELETE,OPTIONS"
           modules="FastCgiModule" scriptProcessor="C:\PHP\php-cgi.exe"
           resourceType="Either" requireAccess="Script" />
      <add name="StaticFile" path="*" verb="*"
           modules="StaticFileModule,DefaultDocumentModule,DirectoryListingModule"
           resourceType="Either" requireAccess="Read" />
  </handlers>
  ```
- **Evidence & Vulnerability:**
  `web.config` hardcodes `scriptProcessor="C:\PHP\php-cgi.exe"`. On standard Windows Server 2019 installations, PHP is frequently deployed under `C:\Program Files\PHP\v8.2\php-cgi.exe`, `C:\tools\php82\php-cgi.exe`, or customized application directories. If the binary is not located at `C:\PHP\php-cgi.exe`, IIS throws a fatal **HTTP 500.0 - FastCGI Handler Not Found** error across the entire application upon first request.
  Furthermore, `<handlers><clear />` removes all inherited IIS system handlers, which can break IIS trace handlers, ASP.NET integration, and built-in error handling if not configured with surgical precision.
- **Recommended Remediation:**
  Document the exact registration requirement in `COURIVA_IIS_DEPLOYMENT_CHECKLIST.md`. Ensure IIS FastCGI is registered globally via `appcmd.exe` or PowerShell `Add-WebConfiguration`, and make `web.config` align with the server's registered path or use an inherited handler name (`PHP-FastCGI`).

---

### 3.3 SEC-IIS-03: Request Filtering Gaps — `docs/` and Unlisted Sensitive Extensions (P1)
- **Location:** `/opt/lampp/htdocs/Web_Mail_System/web.config:35-74`
- **Code Inspection:**
  `hiddenSegments` lists:
  `storage`, `config`, `app`, `database`, `includes`, `tests`, `bin`, `.git`, `.github`, `cache`, `node_modules`, `.hermes`.
  `fileExtensions allowUnlisted="true"` blocks:
  `.sql`, `.log`, `.env`, `.ini`, `.bak`, `.bak2`, `.bak3`, `.md`, `.yml`, `.yaml`, `.sh`, `.bat`, `.json`, `.lock`.
- **Evidence & Vulnerability:**
  1. The `docs/` folder is **not** included in `hiddenSegments`.
  2. While `.md` files are blocked by extension filtering, non-markdown files inside `docs/` (such as `docs/flowcharts/audit_report.txt` and `docs/flowcharts/login_flowchart.drawio`) are allowed by `allowUnlisted="true"`.
  3. Consequently, any web browser can directly request `http://server/docs/flowcharts/audit_report.txt` and `http://server/docs/flowcharts/login_flowchart.drawio`, exposing internal architecture, process flows, and audit diagnostics without authentication.
- **Recommended Remediation:**
  1. Add `<add segment="docs" />` to `hiddenSegments` in `web.config`.
  2. Add `<add fileExtension=".txt" allowed="false" />` and `<add fileExtension=".drawio" allowed="false" />` to `fileExtensions` (or restrict static assets exclusively to `assets/` and `public/`).

---

### 3.4 SEC-IIS-04: Conflicting Security Headers Between IIS and PHP (P1)
- **Location:** `/opt/lampp/htdocs/Web_Mail_System/web.config:116-127`, `/opt/lampp/htdocs/Web_Mail_System/includes/functions.php:259-295`
- **Code Inspection:**
  In `web.config`:
  ```xml
  <httpProtocol>
      <customHeaders>
          <add name="X-Content-Type-Options" value="nosniff" />
          <add name="X-Frame-Options" value="SAMEORIGIN" />
          <add name="Referrer-Policy" value="strict-origin-when-cross-origin" />
          <add name="Permissions-Policy" value="camera=(), microphone=(), geolocation=(), payment=(), usb=()" />
          <add name="Strict-Transport-Security" value="max-age=31536000; includeSubDomains" />
          <add name="Content-Security-Policy" value="default-src 'self'; ... script-src 'self' https://cdn.jsdelivr.net; ..." />
      </customHeaders>
  </httpProtocol>
  ```
  In `includes/functions.php` (`send_security_headers()`):
  ```php
  header('X-Content-Type-Options: nosniff');
  header('X-Frame-Options: SAMEORIGIN');
  header('Referrer-Policy: strict-origin-when-cross-origin');
  header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
  header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
  header('Content-Security-Policy: ' . $csp); // dynamically generated with nonce
  ```
- **Evidence & Vulnerability:**
  When PHP runs under IIS FastCGI, headers sent via PHP `header()` are merged with IIS `<customHeaders>`. This creates duplicate HTTP headers in the response:
  - `X-Frame-Options: SAMEORIGIN, SAMEORIGIN`
  - Two distinct `Content-Security-Policy` headers:
    1. The static IIS header (lacking nonces).
    2. The dynamic PHP header (with `'nonce-...'`).
  Modern browsers evaluate the logical intersection of all CSP headers. Because the IIS CSP does not include the nonce that PHP generates for inline script execution, strict browsers will block application scripts or trigger console errors.
- **Recommended Remediation:**
  In `web.config`, configure `<customHeaders>` to manage static assets only, or let PHP manage dynamic security headers while IIS injects transport security (`Strict-Transport-Security`). Remove static CSP and X-Frame-Options from `web.config` `<customHeaders>` so PHP's dynamic nonce-based CSP operates cleanly without duplication.

---

### 3.5 SEC-IIS-05: Background Scheduled Worker Lacks Concurrency Lock (P1)
- **Location:** `/opt/lampp/htdocs/Web_Mail_System/bin/process_scheduled.php:34-58`
- **Evidence & Vulnerability:**
  `bin/process_scheduled.php` is intended to be executed every 60 seconds by Windows Task Scheduler. However, the script does not implement an OS-level file lock (`flock`) or process mutex.
  If a large queue of scheduled emails, slow SMTP handshakes, or temporary network latency causes an execution to take longer than 60 seconds, Windows Task Scheduler (by default or if misconfigured) will launch a second concurrent worker instance.
  While `ScheduledMailService::processDue()` uses an atomic SQL `UPDATE ... status = 'processing'` claim, competing workers querying the database concurrently create unnecessary lock contention, connection spikes, and duplicate connection attempts to the SMTP transport.
- **Recommended Remediation:**
  Wrap `bin/process_scheduled.php` execution in an atomic file lock using `flock($fp, LOCK_EX | LOCK_NB)`. If another instance is active, the newly spawned task must exit immediately with code 0.

---

### 3.6 SEC-IIS-06: Windows NTFS ACLs & Application Pool Identity Permissions (P1)
- **Location:** Directory structure across `/storage`, `/config`, `/app`, `/public`, `/admin`
- **Evidence & Vulnerability:**
  IIS 10 runs worker processes under a virtual application pool identity (e.g. `IIS AppPool\CourivaPool`) or `IUSR`.
  Linux permissions (`chmod 777` or `chown daemon`) have no equivalent on Windows Server.
  If administrators grant full modify rights to `Everyone` or `Users` to get the app working, any compromised script can overwrite application code (`public/`, `app/`, `config/database.php`).
  Conversely, if standard restrictive permissions are applied without granting explicit rights to `IIS AppPool\CourivaPool`, attachment uploads fail (`storage/attachments/` inaccessible) and session file writes fail.
- **Recommended Remediation:**
  Define a strict NTFS Security ACL matrix:
  - **Read & Execute Only:** `app/`, `config/`, `includes/`, `public/`, `admin/`, `database/`, `assets/`, `bin/`.
  - **Modify (Read/Write/Delete):** `storage/attachments/`, `storage/logs/`, `storage/cache/`, and the configured PHP session directory (`C:\Windows\Temp` or dedicated session folder).
  - Provide a turnkey PowerShell script (`icacls`) to apply these permissions deterministically.

---

### 3.7 SEC-IIS-07: MariaDB Dump & Attachment Storage Backup Desynchronization (P2)
- **Location:** Database `attachments` table and physical folder `/storage/attachments/`
- **Evidence & Vulnerability:**
  Couriva stores attachment metadata in MariaDB (`attachments` table) and physical binary files in `storage/attachments/` referenced by randomized filenames (`stored_filename`).
  If database backups (`mysqldump.exe`) and filesystem backups are executed asynchronously without coordination, a database restoration will restore references to attachment files that were never captured or have been purged, leading to broken downloads and 404 streaming errors in `public/download.php`.
- **Recommended Remediation:**
  Create a unified PowerShell backup script for Windows Server that:
  1. Executes `mariadb-dump.exe` / `mysqldump.exe` with `--single-transaction --quick`.
  2. Takes a consistent filesystem archive of `storage/attachments/`.
  3. Verifies that every `stored_filename` in the database dump physically exists in the backed-up attachment archive.

---

### 3.8 SEC-IIS-08: PHP FastCGI Process Recycling on Windows Server (P2)
- **Location:** IIS FastCGI Settings (`applicationHost.config`)
- **Evidence & Vulnerability:**
  On Windows Server IIS, PHP FastCGI processes are managed by IIS's `FastCgiModule`. PHP maintains internal request counters via `PHP_FCGI_MAX_REQUESTS` before terminating a worker to prevent memory fragmentation and leaks.
  If IIS FastCGI `instanceMaxRequests` is higher than `PHP_FCGI_MAX_REQUESTS` (or vice-versa), IIS may attempt to route a request to a `php-cgi.exe` process that has already terminated itself, producing intermittent **HTTP 502.2 - Bad Gateway** errors under production load.
- **Recommended Remediation:**
  Synchronize both settings: Set `PHP_FCGI_MAX_REQUESTS = 10000` in PHP FastCGI environment variables and set `instanceMaxRequests = 10000` in IIS FastCGI process model settings.

---

### 3.9 SEC-IIS-09: Inconsistent Password Complexity Policies (P2)
- **Location:** `/opt/lampp/htdocs/Web_Mail_System/public/register.php:64-68`, `/opt/lampp/htdocs/Web_Mail_System/public/change-password.php:45-51`, `/opt/lampp/htdocs/Web_Mail_System/admin/edit-user.php:60-63`
- **Evidence & Vulnerability:**
  - `public/register.php` enforces: length >= 10, uppercase, lowercase, digit, and symbol.
  - `public/change-password.php` enforces: `strlen($newPass) < 8` with no character class requirements.
  - `admin/edit-user.php` enforces: `strlen($newPass) < 8` with no character class requirements.
  An account that was forced to reset its password can set a trivial 8-character numeric or lowercase password (`12345678`), undermining the organization's credential strength standards.
- **Recommended Remediation:**
  Extract a centralized password complexity validation function in `includes/functions.php` (`is_valid_password_complexity($password)`) and enforce identical rules across registration, user-initiated password changes, and administrator resets.

---

### 3.10 SEC-IIS-10: IIS Site Physical Path Topology vs Sibling Folders (P2)
- **Location:** Project root structure (`admin/`, `assets/`, `public/`, `includes/`)
- **Evidence & Vulnerability:**
  Couriva's structure places `admin/` and `assets/` at the repository root alongside `public/`.
  In an ideal enterprise web application, the IIS Website physical path points strictly to the `public/` directory, making `app/`, `config/`, and `includes/` physically unreachable by the web server.
  However, because `admin/` and `assets/` are siblings to `public/`, pointing IIS to `public/` breaks admin access and styling unless virtual directories or rewrite rules are mapped.
  Therefore, IIS must point to the root directory, relying entirely on `hiddenSegments` and URL Rewrite rules for security.
- **Recommended Remediation:**
  In the short term, harden `web.config` hidden segments (blocking `docs/`, `bin/`, `storage/`, `config/`, `app/`, `includes/`, `database/`, `tests/`). In a future minor release, relocate `admin/` into `public/admin/` and `assets/` into `public/assets/` to allow pointing the IIS physical path directly to `public/`.

---

## 4. Operational & Infrastructure Review

### 4.1 Windows Task Scheduler Compatibility
`bin/process_scheduled.php` is fully compatible with Windows Task Scheduler when invoked via CLI:
```cmd
C:\PHP\php.exe -f C:\inetpub\wwwroot\Web_Mail_System\bin\process_scheduled.php
```
Audit verified that:
1. `PHP_SAPI === 'cli'` check works identically on Windows and Linux.
2. File paths use `dirname(__DIR__)` which normalizes correctly across backslashes and forward slashes.
3. Queue drain loop processes up to 20 batches of 25 messages, preventing infinite runtimes.
4. Stuck jobs (>10 minutes in `'processing'`) are reclaimed automatically upon worker startup.

### 4.2 Database Layer (MariaDB on Windows)
1. Charset & Collation: `utf8mb4` and `utf8mb4_unicode_ci` are supported in MariaDB 10.4+ on Windows.
2. Table Names & Case Sensitivity: On Windows, the filesystem is case-insensitive, so MariaDB defaults to `lower_case_table_names = 1` or `2`. Couriva's codebase consistently uses lowercase table names (`users`, `emails`, `attachments`, `scheduled_emails`, `delivery_status`), ensuring zero portability collisions between Linux and Windows.
3. Storage Engine: All tables declare `ENGINE=InnoDB` with cascading foreign keys and transactions.

### 4.3 UI/UX & Responsive Views on IIS
All UI templates, stylesheets, and client scripts operate natively in modern browsers accessing IIS:
- Local Tailwind CSS (`assets/css/tailwind.generated.css`) and Bootstrap Icons are served directly via static file handlers without external CDN dependencies.
- MIME mappings for `.woff` and `.woff2` are declared explicitly in `web.config:9-14` to prevent IIS 404.3 font errors.
- Theme switching (Light / Dark / System) operates seamlessly via client-side `localStorage` and `public/api/theme.php`.

---

## 5. Audit Conclusion & Roadmap

The Couriva WebMail System is architecturally sound and can be safely deployed to Windows Server 2019 IIS 10 once the P0 and P1 remediation steps detailed in `COURIVA_SECURITY_REMEDIATION_PLAN.md` are applied.
