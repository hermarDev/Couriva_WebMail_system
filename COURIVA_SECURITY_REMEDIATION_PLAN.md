# Couriva WebMail System — Security Remediation Plan (Windows Server 2019 / IIS 10)

**Document:** `COURIVA_SECURITY_REMEDIATION_PLAN.md`  
**Target Environment:** Windows Server 2019, IIS 10, PHP 8.2+ FastCGI, MariaDB 10.4+  
**Target Application:** Couriva WebMail System (`/opt/lampp/htdocs/Web_Mail_System`)  
**Status:** Actionable Technical Implementation Specification  

---

## 1. Overview & Strategy

This remediation plan translates the findings from `COURIVA_WINDOWS_IIS_AUDIT.md` into concrete, prioritized engineering actions. It is structured to resolve high-risk vulnerabilities first (P0 and P1) before proceeding to operational hardening and quality enhancements (P2 and P3).

### Strict Change Guardrails
1. **Preserve Functionality:** Do not alter database schemas without backwards-compatible defaults.
2. **Zero Plaintext Credentials:** Never log, store, or transmit passwords in plaintext.
3. **Fail-Closed Principle:** Any failure in security validation (crypto, auth, attachments, tokens) must deny access immediately.
4. **Independent Verification:** Every fix must be verified with reproducible test commands before production deployment.

---

## 2. Priority 0 (Critical) Remediation

### 2.1 Remediation Action P0-1: Active Directory Account Isolation (SEC-IIS-01)

#### Objective
Ensure that users managed by Windows Active Directory cannot bypass domain lockout, suspension, or password policies through local database fallback.

#### Technical Implementation
1. **Schema Update (`database/migrations/005_ad_auth_source.sql`):**
   ```sql
   ALTER TABLE `users`
   ADD COLUMN `auth_source` ENUM('local', 'ldap') NOT NULL DEFAULT 'local' AFTER `avatar`,
   ADD INDEX `idx_users_auth_source` (`auth_source`);
   ```

2. **AuthService Logic Hardening (`app/Services/AuthService.php`):**
   Update `AuthService::authenticate()` so that account lookup checks the user's `auth_source` BEFORE deciding whether fallback is permissible:
   ```php
   // Look up existing user to determine identity authority
   $existingUser = $this->userModel->findByEmail($email);
   $isAdUser = $existingUser && ($existingUser['auth_source'] === 'ldap');

   // 1. If LDAP is enabled and user is an AD user (or unmapped)
   if (!empty($this->ldapConfig['enabled'])) {
       $ldapResult = $this->attemptLdapAuth($email, $password);
       if ($ldapResult['success']) {
           return $ldapResult;
       }

       // CRITICAL SECURITY RULE: If this user is an Active Directory account,
       // NEVER fall back to local DB authentication!
       if ($isAdUser) {
           return [
               'success' => false,
               'message' => $ldapResult['message'] ?? 'Active Directory credentials rejected.'
           ];
       }

       // For non-AD users, check if local fallback is globally permitted
       if (empty($this->ldapConfig['fallback_to_local'])) {
           return [
               'success' => false,
               'message' => $ldapResult['message'] ?? 'Invalid email or password.'
           ];
       }
   }

   // 2. Local Database Authentication (Only for auth_source = 'local')
   if ($isAdUser) {
       return [
           'success' => false,
           'message' => 'Active Directory accounts must authenticate via domain controller.'
       ];
   }

   return $this->attemptLocalAuth($email, $password);
   ```

3. **Admin User Management Guard (`admin/edit-user.php`):**
   Disable local password reset for AD accounts in the admin UI. Active Directory passwords must be managed exclusively through the Windows Domain Controller / RSAT.
   ```php
   <?php if ($targetUser['auth_source'] === 'ldap'): ?>
       <div class=\"alert alert-info small mb-0\">
           <i class=\"bi bi-windows me-1\"></i>
           This user is managed by Active Directory. Passwords and account status must be managed in the Windows Domain Controller.
       </div>
   <?php else: ?>
       <!-- Render standard password reset form -->
   <?php endif; ?>
   ```

#### Acceptance Criteria
- [ ] AD account disabled in AD cannot log into Couriva, even if a local password hash exists.
- [ ] Local admin account (`admin@couriva.local`) can still log in when LDAP is offline (disaster recovery path).
- [ ] Admin UI blocks local password reset attempts on AD-managed users.

---

### 2.2 Remediation Action P0-2: Flexible FastCGI Handler Mapping (SEC-IIS-02)

#### Objective
Prevent fatal HTTP 500.0 errors caused by a hardcoded path to `C:\PHP\php-cgi.exe` when PHP is installed in another directory on Windows Server.

#### Technical Implementation
1. **Remove Hardcoded Path in `web.config`:**
   Instead of specifying a full absolute path in `scriptProcessor` that may not exist, reference the registered server-level FastCGI handler name or support standard IIS handler inheritance:
   ```xml
   <handlers>
       <!-- FastCGI PHP Handler -->
       <add name="PHP_via_FastCGI" path="*.php" verb="GET,HEAD,POST,PUT,DELETE,OPTIONS"
            modules="FastCgiModule" scriptProcessor="C:\PHP\php-cgi.exe"
            resourceType="Either" requireAccess="Script" />
       <add name="StaticFile" path="*" verb="*"
            modules="StaticFileModule,DefaultDocumentModule,DirectoryListingModule"
            resourceType="Either" requireAccess="Read" />
   </handlers>
   ```
2. **Provide Turnkey IIS Registration Command in Deployment Checklist:**
   ```powershell
   # Register PHP FastCGI handler at the server level
   $phpPath = "C:\PHP\php-cgi.exe" # Change to actual path on server
   & "$env:SystemRoot\system32\inetsrv\appcmd.exe" set config /section:system.webServer/fastCGI /+"[fullPath='$phpPath',maxInstances='0',instanceMaxRequests='10000',activityTimeout='300',requestTimeout='300']" /commit:apphost
   ```

---

## 3. Priority 1 (High) Remediation

### 3.1 Remediation Action P1-1: Request Filtering Hardening in `web.config` (SEC-IIS-03)

#### Objective
Ensure that internal architecture documentation, flowcharts, audit logs, and scripts cannot be downloaded directly via HTTP.

#### Technical Implementation
Update `web.config` `<security><requestFiltering>`:
```xml
<security>
    <requestFiltering>
        <!-- Block direct HTTP access to internal system directories -->
        <hiddenSegments>
            <add segment="storage" />
            <add segment="config" />
            <add segment="app" />
            <add segment="database" />
            <add segment="includes" />
            <add segment="tests" />
            <add segment="bin" />
            <add segment="docs" />
            <add segment=".git" />
            <add segment=".github" />
            <add segment="cache" />
            <add segment="node_modules" />
            <add segment=".hermes" />
        </hiddenSegments>

        <!-- Block direct download of sensitive file types -->
        <fileExtensions allowUnlisted="true">
            <add fileExtension=".sql" allowed="false" />
            <add fileExtension=".log" allowed="false" />
            <add fileExtension=".env" allowed="false" />
            <add fileExtension=".ini" allowed="false" />
            <add fileExtension=".bak" allowed="false" />
            <add fileExtension=".bak2" allowed="false" />
            <add fileExtension=".bak3" allowed="false" />
            <add fileExtension=".md" allowed="false" />
            <add fileExtension=".yml" allowed="false" />
            <add fileExtension=".yaml" allowed="false" />
            <add fileExtension=".sh" allowed="false" />
            <add fileExtension=".bat" allowed="false" />
            <add fileExtension=".json" allowed="false" />
            <add fileExtension=".lock" allowed="false" />
            <add fileExtension=".drawio" allowed="false" />
            <add fileExtension=".txt" allowed="false" />
        </fileExtensions>

        <!-- Max allowed upload size: 64 MB (67,108,864 bytes) -->
        <requestLimits maxAllowedContentLength="67108864" />
    </requestFiltering>
</security>
```

#### Acceptance Criteria
- [ ] Requesting `/docs/flowcharts/audit_report.txt` returns HTTP 404.8 (Hidden Segment).
- [ ] Requesting `/docs/flowcharts/login_flowchart.drawio` returns HTTP 404.7 (File Extension Denied).
- [ ] Requesting `/config/database.php` returns HTTP 404.8.

---

### 3.2 Remediation Action P1-2: Security Headers Conflict Resolution (SEC-IIS-04)

#### Objective
Prevent duplicate security headers (`X-Frame-Options`, `Content-Security-Policy`) between IIS `web.config` and PHP `send_security_headers()`.

#### Technical Implementation
1. In `web.config`, remove duplicate response headers from `<customHeaders>`, leaving transport-level headers (`X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Strict-Transport-Security`):
   ```xml
   <httpProtocol>
       <customHeaders>
           <remove name="X-Powered-By" />
           <add name="X-Content-Type-Options" value="nosniff" />
           <add name="X-Frame-Options" value="SAMEORIGIN" />
           <add name="Referrer-Policy" value="strict-origin-when-cross-origin" />
           <add name="Permissions-Policy" value="camera=(), microphone=(), geolocation=(), payment=(), usb=()" />
           <add name="Strict-Transport-Security" value="max-age=31536000; includeSubDomains" />
           <!-- Note: Content-Security-Policy is managed dynamically with nonces in PHP includes/functions.php -->
       </customHeaders>
   </httpProtocol>
   ```
2. In `includes/functions.php` (`send_security_headers()`):
   Emit dynamic nonce-based CSP without duplicating static headers already guaranteed by IIS.

---

### 3.3 Remediation Action P1-3: Concurrency File Lock for Scheduled Mail Worker (SEC-IIS-05)

#### Objective
Prevent overlapping execution of `bin/process_scheduled.php` when invoked every minute by Windows Task Scheduler.

#### Technical Implementation
Add an OS-level atomic non-blocking file lock in `bin/process_scheduled.php`:
```php
// Concurrency guard: Ensure single-instance execution under Windows Task Scheduler
$lockFilePath = dirname(__DIR__) . '/storage/cache/scheduled_worker.lock';
$lockHandle = fopen($lockFilePath, 'c+');

if (!$lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    // Another instance is already running; exit gracefully without error
    if ($lockHandle) {
        fclose($lockHandle);
    }
    echo "[" . date('Y-m-d H:i:s') . "] Scheduled worker already running. Exiting.\n";
    exit(0);
}

// Register shutdown function to release lock
register_shutdown_function(function() use ($lockHandle, $lockFilePath) {
    if (is_resource($lockHandle)) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
    @unlink($lockFilePath);
});
```

#### Acceptance Criteria
- [ ] Running two instances of `php bin/process_scheduled.php` simultaneously results in the second instance immediately exiting with code 0.
- [ ] Lock file is cleanly released and unlinked on completion.

---

### 3.4 Remediation Action P1-4: Automated Windows NTFS Permissions Script (SEC-IIS-06)

#### Objective
Apply least-privilege NTFS Access Control Lists (ACLs) to application directories.

#### Technical Implementation
Create a PowerShell script `bin/setup-iis-permissions.ps1`:
```powershell
param (
    [string]$AppRoot = "C:\inetpub\wwwroot\Web_Mail_System",
    [string]$AppPoolName = "CourivaPool"
)

Write-Host "Applying secure NTFS ACLs to $AppRoot for IIS AppPool\$AppPoolName..." -ForegroundColor Cyan

$AppPoolAccount = "IIS AppPool\$AppPoolName"

# 1. Inherit read & execute by default across the entire application root
& icacls "$AppRoot" /inheritance:r /grant "ADMINISTRATORS:(OI)(CI)F" /grant "SYSTEM:(OI)(CI)F" /grant "IUSR:(OI)(CI)RX" /grant "${AppPoolAccount}:(OI)(CI)RX" /T /Q

# 2. Grant Modify rights ONLY to specific writable directories
$WritableDirs = @(
    "$AppRoot\storage\attachments",
    "$AppRoot\storage\logs",
    "$AppRoot\storage\cache"
)

foreach ($dir in $WritableDirs) {
    if (-not (Test-Path $dir)) {
        New-Item -ItemType Directory -Path $dir -Force | Out-Null
    }
    & icacls "$dir" /grant "${AppPoolAccount}:(OI)(CI)M" /Q
}

Write-Host "NTFS permissions successfully configured." -ForegroundColor Green
```

---

## 4. Priority 2 (Medium) Remediation

### 4.1 Remediation Action P2-1: Unified Password Complexity Policy (SEC-IIS-09)
1. Add `is_valid_password_complexity(string $password): bool` in `includes/functions.php`:
   ```php
   function is_valid_password_complexity(string $password): bool
   {
       return strlen($password) >= 10
           && preg_match('/[a-z]/', $password)
           && preg_match('/[A-Z]/', $password)
           && preg_match('/[0-9]/', $password)
           && preg_match('/[^A-Za-z0-9]/', $password);
   }
   ```
2. Refactor `public/register.php`, `public/change-password.php`, and `admin/edit-user.php` to use this single function.

### 4.2 Remediation Action P2-2: Synchronized Windows Backup Script (SEC-IIS-07)
Create `bin/backup-couriva.ps1`:
- Invokes `mysqldump.exe` with `--single-transaction --routines --triggers --events`.
- Simultaneously archives `storage\attachments\` into a timestamped zip.
- Generates an SHA-256 manifest of the backup bundle.

### 4.3 Remediation Action P2-3: FastCGI Process Model Synchronization (SEC-IIS-08)
Configure IIS FastCGI environment variables:
- Set `PHP_FCGI_MAX_REQUESTS = 10000`.
- Set IIS FastCGI `instanceMaxRequests = 10000`.

---

## 5. Priority 3 (Low) Polish

- **SEC-IIS-13:** Add a "Generate Random Password" button in `admin/edit-user.php` using `bin2hex(random_bytes(6))` with symbol insertion to simplify administrator provisioning.
- **SEC-IIS-14:** Include `$_SERVER['HTTP_X_ARR_LOG_ID']` or request trace IDs in `system_logs` to cross-reference with IIS W3C log entries.

---

## 6. Implementation Schedule & Approval Gate

| Phase | Items | Target Completion | Reviewer Sign-off |
|---|---|---|---|
| **Phase 1 (P0)** | P0-1 (AD Isolation), P0-2 (FastCGI Path) | Immediate / Pre-Staging | Security Architect |
| **Phase 2 (P1)** | P1-1 (Request Filtering), P1-2 (Headers), P1-3 (Worker Lock), P1-4 (NTFS ACLs) | Staging Deployment | Lead DevOps / QA |
| **Phase 3 (P2)** | P2-1 (Password Policy), P2-2 (Backup Script), P2-3 (FastCGI Tuning) | Pre-Production | Systems Administrator |
| **Phase 4 (P3)** | P3 UI & Logging Polish | Post-Launch Sprint | UI/UX Lead |
