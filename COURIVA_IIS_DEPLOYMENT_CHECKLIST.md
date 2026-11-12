# Couriva WebMail System — Windows Server 2019 & IIS 10 Production Deployment Checklist

**Document:** `COURIVA_IIS_DEPLOYMENT_CHECKLIST.md`  
**Target Environment:** Windows Server 2019 Standard/Datacenter (Build 1809+), IIS 10.0  
**Runtimes:** PHP 8.2+ Non-Thread Safe (NTS) x64, MariaDB 10.4+ / MySQL 8.0 x64  
**Application Root:** `C:\inetpub\wwwroot\Web_Mail_System`  

---

## 1. Pre-Deployment Architecture Overview

```text
               [Internet / Corporate Intranet]
                             │
                             ▼ (HTTPS:443 - TLS 1.3 / 1.2)
       [Windows Server 2019 - IIS 10 Web Server]
         ├── URL Rewrite 2.1 (HTTP -> HTTPS, Clean Routing)
         ├── Request Filtering (Hidden Segments: config, storage, docs, app)
         ├── HTTP Security Headers (HSTS, nosniff, SAMEORIGIN)
         └── FastCgiModule (Managed Pool: CourivaPool, AppPoolIdentity)
                             │
                             ▼ (FastCGI Named Pipes / TCP)
                 [PHP 8.2+ NTS x64 (php-cgi.exe)]
                   ├── Extensions: pdo_mysql, mbstring, openssl, fileinfo, ldap
                   └── Hardened php.ini (session.cookie_httponly, expose_php=Off)
                             │
            ┌────────────────┴────────────────┐
            ▼                                 ▼
   [MariaDB 10.4+ / MySQL 8.0]    [Windows Active Directory]
     Database: `webmail`            Port: 636 (LDAPS) / 389 (STARTTLS)
     Charset: utf8mb4               Domain: couriva.local
```

---

## 2. Server Roles & Feature Prerequisites

Run the following command in an elevated PowerShell session (Run as Administrator) to install the necessary Windows Server roles and IIS sub-features:

```powershell
# Install IIS 10 Web Server with CGI (FastCGI) and Security Features
Install-WindowsFeature -Name Web-Server, `
    Web-WebServer, `
    Web-Common-Http, `
    Web-Default-Doc, `
    Web-Dir-Browsing, `
    Web-Http-Errors, `
    Web-Static-Content, `
    Web-Http-Redirect, `
    Web-Health, `
    Web-Http-Logging, `
    Web-Log-Libraries, `
    Web-Request-Monitor, `
    Web-Http-Tracing, `
    Web-Security, `
    Web-Filtering, `
    Web-Basic-Auth, `
    Web-Windows-Auth, `
    Web-App-Dev, `
    Web-CGI, `
    Web-Mgmt-Tools, `
    Web-Mgmt-Console -IncludeManagementTools
```

### Required External Dependencies
1. **Microsoft Visual C++ 2015–2022 Redistributable (x64):**
   - Download: `https://aka.ms/vs/17/release/vc_redist.x64.exe`
   - Install silently: `Start-Process vc_redist.x64.exe -ArgumentList '/quiet /norestart' -Wait`
2. **IIS URL Rewrite Module 2.1 (x64):**
   - Download: `https://www.iis.net/downloads/microsoft/url-rewrite`
   - Install: `msiexec /i rewrite_amd64_en-US.msi /qn`

---

## 3. PHP 8.2+ FastCGI Installation & `php.ini` Hardening

1. **Extract PHP:**
   Download the latest stable **PHP 8.2+ x64 Non-Thread Safe (NTS)** zip archive from `https://windows.php.net/download/` and extract to `C:\PHP`.
2. **Configure `C:\PHP\php.ini`:**
   Copy `php.ini-production` to `php.ini` and enforce the following directives:

```ini
[PHP]
engine = On
expose_php = Off
max_execution_time = 60
max_input_time = 60
memory_limit = 256M
error_reporting = E_ALL & ~E_DEPRECATED & ~E_STRICT
display_errors = Off
display_startup_errors = Off
log_errors = On
error_log = "C:\inetpub\wwwroot\Web_Mail_System\storage\logs\php_errors.log"
post_max_size = 64M
upload_max_filesize = 64M
max_file_uploads = 20
default_charset = "UTF-8"
date.timezone = "UTC"

; Directory & CGI configuration
extension_dir = "C:\PHP\ext"
cgi.force_redirect = 0
cgi.fix_pathinfo = 0
fastcgi.impersonate = 1
fastcgi.logging = 0

; Required Extensions
extension=curl
extension=fileinfo
extension=mbstring
extension=openssl
extension=pdo_mysql
extension=ldap

; Session Hardening
session.save_handler = files
session.save_path = "C:\PHP\sessions"
session.use_strict_mode = 1
session.use_cookies = 1
session.use_only_cookies = 1
session.name = COURIVASESSID
session.auto_start = 0
session.cookie_lifetime = 0
session.cookie_path = /
session.cookie_domain = ""
session.cookie_httponly = 1
session.cookie_samesite = "Strict"
session.serialize_handler = php
session.gc_probability = 1
session.gc_divisor = 1000
session.gc_maxlifetime = 28800
session.cache_limiter = nocache
```

3. **Create Dedicated Session Directory:**
   ```powershell
   New-Item -ItemType Directory -Path "C:\PHP\sessions" -Force
   & icacls "C:\PHP\sessions" /inheritance:r /grant "ADMINISTRATORS:(OI)(CI)F" /grant "SYSTEM:(OI)(CI)F" /grant "IIS AppPool\CourivaPool:(OI)(CI)M" /Q
   ```

---

## 4. IIS FastCGI Registration & Application Pool Setup

Run the following commands in PowerShell to register FastCGI with recycling parameters matching PHP:

```powershell
Import-Module WebAdministration

# 1. Register PHP FastCGI executable globally in IIS
$phpPath = "C:\PHP\php-cgi.exe"
& "$env:SystemRoot\system32\inetsrv\appcmd.exe" set config /section:system.webServer/fastCGI /+"[fullPath='$phpPath',maxInstances='0',instanceMaxRequests='10000',activityTimeout='300',requestTimeout='300']" /commit:apphost

# Add environment variable to synchronize PHP internal recycling with FastCGI
& "$env:SystemRoot\system32\inetsrv\appcmd.exe" set config /section:system.webServer/fastCGI /+"[fullPath='$phpPath'].environmentVariables.[name='PHP_FCGI_MAX_REQUESTS',value='10000']" /commit:apphost

# 2. Create Isolated Dedicated Application Pool
$poolName = "CourivaPool"
if (-not (Test-Path "IIS:\AppPools\$poolName")) {
    New-Item "IIS:\AppPools\$poolName"
}

# Configure Application Pool Properties
Set-ItemProperty "IIS:\AppPools\$poolName" -Name "managedRuntimeVersion" -Value "" # No Managed Code
Set-ItemProperty "IIS:\AppPools\$poolName" -Name "managedPipelineMode" -Value "Integrated"
Set-ItemProperty "IIS:\AppPools\$poolName" -Name "processModel.identityType" -Value "ApplicationPoolIdentity"
Set-ItemProperty "IIS:\AppPools\$poolName" -Name "processModel.idleTimeout" -Value "00:00:00" # Never idle out
Set-ItemProperty "IIS:\AppPools\$poolName" -Name "recycling.periodicRestart.time" -Value "00:00:00" # Predictable recycling
Set-ItemProperty "IIS:\AppPools\$poolName" -Name "recycling.periodicRestart.requests" -Value 50000
```

---

## 5. Website Creation & Virtual Directory Configuration

```powershell
$siteName = "CourivaWebMail"
$physicalPath = "C:\inetpub\wwwroot\Web_Mail_System"

# Remove Default Web Site if desired or bind to custom port/hostname
if (Test-Path "IIS:\Sites\Default Web Site") {
    Stop-WebSite -Name "Default Web Site"
}

if (-not (Test-Path "IIS:\Sites\$siteName")) {
    New-WebSite -Name $siteName -Port 80 -PhysicalPath $physicalPath -ApplicationPool "CourivaPool"
} else {
    Set-ItemProperty "IIS:\Sites\$siteName" -Name "physicalPath" -Value $physicalPath
    Set-ItemProperty "IIS:\Sites\$siteName" -Name "applicationPool" -Value "CourivaPool"
}

Start-WebSite -Name $siteName
```

---

## 6. Windows NTFS Permissions & Security Hardening (Least Privilege)

Execute the security ACL configuration script to isolate read-only source files from writable data stores:

```powershell
$AppRoot = "C:\inetpub\wwwroot\Web_Mail_System"
$AppPoolAccount = "IIS AppPool\CourivaPool"

# 1. Reset inheritance and grant read & execute across the application
& icacls "$AppRoot" /inheritance:r /grant "ADMINISTRATORS:(OI)(CI)F" /grant "SYSTEM:(OI)(CI)F" /grant "IUSR:(OI)(CI)RX" /grant "${AppPoolAccount}:(OI)(CI)RX" /T /Q

# 2. Ensure storage subdirectories exist
$StorageDirs = @(
    "$AppRoot\storage\attachments",
    "$AppRoot\storage\logs",
    "$AppRoot\storage\cache"
)

foreach ($dir in $StorageDirs) {
    if (-not (Test-Path $dir)) {
        New-Item -ItemType Directory -Path $dir -Force | Out-Null
    }
    # Grant Modify permissions to the application pool identity ONLY
    & icacls "$dir" /grant "${AppPoolAccount}:(OI)(CI)M" /Q
}

Write-Host "NTFS Access Control Lists successfully enforced." -ForegroundColor Green
```

---

## 7. Windows Task Scheduler Setup for Background Queue Processing

Create the scheduled task for `bin/process_scheduled.php` running every minute:

```powershell
$TaskName = "Couriva-ScheduledMailProcessor"
$PhpExe = "C:\PHP\php.exe"
$ScriptPath = "C:\inetpub\wwwroot\Web_Mail_System\bin\process_scheduled.php"

# Action: Run PHP CLI script
$Action = New-ScheduledTaskAction -Execute $PhpExe -Argument "-f `"$ScriptPath`""

# Trigger: Run every minute indefinitely
$Trigger = New-ScheduledTaskTrigger -Once -At (Get-Date) -RepetitionInterval (New-TimeSpan -Minutes 1) -RepetitionDuration ([TimeSpan]::MaxValue)

# Settings: Ignore new if already running, wake to run, stop if running longer than 10 minutes
$Settings = New-ScheduledTaskSettingsSet `
    -MultipleInstances IgnoreNew `
    -ExecutionTimeLimit (New-TimeSpan -Minutes 10) `
    -RestartCount 3 `
    -RestartInterval (New-TimeSpan -Minutes 1) `
    -DisallowStartIfOnBatteries:$false `
    -StopIfGoingOnBatteries:$false

# Principal: Run as SYSTEM or dedicated service account with high privileges
$Principal = New-ScheduledTaskPrincipal -UserId "SYSTEM" -LogonType ServiceAccount -RunLevel Highest

# Register Task
Register-ScheduledTask -TaskName $TaskName -Action $Action -Trigger $Trigger -Settings $Settings -Principal $Principal -Force

Write-Host "Scheduled task $TaskName successfully registered." -ForegroundColor Green
```

---

## 8. MariaDB Setup & Database Provisioning

1. **Service Configuration (`my.ini`):**
   ```ini
   [mysqld]
   port = 3306
   default_storage_engine = InnoDB
   character-set-server = utf8mb4
   collation-server = utf8mb4_unicode_ci
   max_allowed_packet = 64M
   innodb_buffer_pool_size = 1G
   innodb_log_file_size = 256M
   innodb_file_per_table = 1
   lower_case_table_names = 2
   sql_mode = "NO_ENGINE_SUBSTITUTION,STRICT_TRANS_TABLES"
   ```

2. **Schema & Seed Import:**
   ```powershell
   & "C:\Program Files\MariaDB\bin\mysql.exe" -u root -p -e "CREATE DATABASE webmail CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
   & "C:\Program Files\MariaDB\bin\mysql.exe" -u root -p webmail < "C:\inetpub\wwwroot\Web_Mail_System\database\schema.sql"
   ```

3. **Provision Initial Administrator:**
   ```powershell
   $env:ADMIN_EMAIL = "admin@couriva.local"
   $env:ADMIN_PASSWORD = "EnterpriseAdminSecurePassword2026!"
   $env:ADMIN_FIRST_NAME = "System"
   $env:ADMIN_LAST_NAME = "Administrator"
   & "C:\PHP\php.exe" "C:\inetpub\wwwroot\Web_Mail_System\bin\create-admin.php"
   ```

---

## 9. Verification & Cutover Checklist

- [ ] FastCGI handler `*.php` responds with HTTP 200 on `http://localhost/public/login.php`.
- [ ] Attempting to access `http://localhost/config/database.php` returns **HTTP 404.8** (Hidden Segment).
- [ ] Attempting to access `http://localhost/storage/attachments/` returns **HTTP 404.8**.
- [ ] Attempting to access `http://localhost/docs/flowcharts/audit_report.txt` returns **HTTP 404.8** or **HTTP 404.7**.
- [ ] Attempting to access `http://localhost/.env` returns **HTTP 404.7** (File Extension Denied).
- [ ] Logging in as `admin@couriva.local` forces immediate redirect to `/public/change-password.php`.
- [ ] Changing password updates `must_change_password = 0` and grants access to `/admin/index.php`.
- [ ] Sending a scheduled email inserts row into `scheduled_emails`.
- [ ] Running `schtasks /run /tn "Couriva-ScheduledMailProcessor"` delivers due messages and logs result in `storage/logs/`.
