# Couriva WebMail System — Defense-Day System Guide

**Edition:** 1.0 — Field reference for oral defense, walkthrough, and live demonstration.
**Last verified:** 2026-11-25 / lab `192.168.1.178` / hotspot `172.20.10.2` documented and captured (host joined hotspot as `172.20.10.3/28`).
**Source of truth:** live Windows Server 2022 VM + repo audit documents. Where they disagree, the live VM wins and the drift is recorded in §10.6.
**Scope:** no application code was changed to produce this guide.

---

## Front matter

### Cover

> COURIVA
> **WebMail System**
> Complete System Guide
> Architecture · Build · Identity · Database · Operations · Defense
>
> Live reference — verified 25 November 2026
> Lab: `http://192.168.1.178/public/login.php`
> Defense hotspot: `http://172.20.10.2/public/login.php`
> Hosted on Windows Server 2022 / IIS 10 / MariaDB 10.11 / PHP 8.x

### Title page

Couriva WebMail System — Defense-Day System Guide.
Prepared from the live VMware Workstation guest and the repository audit set:
`COURIVA_IIS_DEPLOYMENT_CHECKLIST.md`, `README.md`, `docs/SYSTEM_COMPREHENSIVE_AUDIT.md`,
`COURIVA_TESTING_AND_ACCEPTANCE.md`, `COURIVA_WINDOWS_IIS_AUDIT.md`, `web.config`,
`config/database.php`, `config/mail.php`, `config/ldap.php`, `database/schema.sql`,
`database/migrations/*.sql`.

Evidence convention: every claim carries an **Open this to prove it** line — a path, a command, or a figure.

### Preface

This book is a defense aid, not a marketing brochure and not a patch log. It answers three questions a panel will ask: *what is the system*, *where does it live*, and *how do I prove it right now*. Read Part 1–3 for the narrative, Part 4–6 for the platform, Part 7–9 for operation and evidence, Part 10 for failure and drift.

Two disciplines govern the text:

1. **No guessing.** Values observed live are stamped with the observation. The database data directory was obtained with `SELECT @@datadir;` — never inferred.
2. **No secrets.** Passwords, bind credentials, tokens and session IDs are redacted or absent. Do not paste credentials into documentation or chat; type them live into the disposable account created for the demo.

### Inside front cover — IF ASKED...

| Question | Answer (say this first) |
|---|---|
| What is Couriva? | Browser-based mail system, PHP 8 + PDO, MariaDB, AD/LDAP identity, IIS 10 on Windows Server 2022. |
| Where do I log in? | Lab `http://192.168.1.178/public/login.php`; hotspot `http://172.20.10.2/public/login.php`. |
| Which server OS? | Live VM is Windows Server 2022 (build `10.0.20348.1`). Repo docs still say 2019 — recorded drift, §10.6. |
| Which DB? | `webmail` on `127.0.0.1:3306`, MariaDB `10.11.20-MariaDB`, 14 live tables. |
| Where is the data directory? | `C:\Program Files\MariaDB 10.11\data\` — verified with `SELECT @@datadir;`. |
| How does login work? | CSRF check → AD/LDCS bind over LDAPS 636 (`OU=CourivaUsers`) → local fallback only for break-glass admin → session regenerate → forced password gate. |
| What if AD is down? | `LDAP_ALLOW_LOCAL_ADMIN_FALLBACK=true` permits a local `auth_source='local'` admin created by `bin/create-admin.php`. |
| Why two CSP headers? | IIS `web.config:129` static CSP has no nonce; PHP emits a nonce CSP. Live response contains both; noted in §10.6. |
| Can I reach hotspot IP from the lab host? | No. `172.20.10.2` is documented and configured on the guest, but the lab host is not on the iPhone hotspot. |
| How do I change to hotspot? | Run `bin\configure-hotspot-ip.bat` as Administrator; §4.9 runbook. Rollback in §4.10. |
| Is there public signup? | No. `public/register.php` → HTTP 410; web user creation → HTTP 403. Only CLI `bin/create-admin.php`. |
| How do scheduled mails run? | `Couriva-ScheduledMailProcessor`, every 1 minute, runs `C:\PHP\php.exe -f ...\bin\process_scheduled.php`. |

---

## Table of contents

- Part 1 — System overview
- Part 2 — System flow
- Part 3 — Files and locations
- Part 4 — Windows Server build
- Part 5 — Identity and LDAP
- Part 6 — Database
- Part 7 — Member demo click-path
- Part 8 — Administrator / operator
- Part 9 — Testing and acceptance
- Part 10 — Troubleshooting and drift
- Appendices A–E — Commands, environment variables, file map, glossary, full Q&A
- Index

## List of figures

1. Server Manager — installed roles (IIS, AD DS, DNS, File and Storage Services)
2. DNS Manager — `couriva.com` forward zone
3. ADUC — `CourivaUsers` organizational unit
4. IIS Manager — CourivaWebMail site and features
5. IIS Manager — CourivaPool application pool
6. IIS Manager — FastCGI settings
7. IIS Manager — Request Filtering
8. IIS Manager — site bindings
9. File Explorer — application root tree
10. `C:\PHP\php.ini` in Notepad
11. `C:\openldap\sysconf\ldap.conf` in Notepad
12. `storage\logs` in File Explorer
13. Certificates MMC
14. Task Scheduler
15. Event Viewer
16. MariaDB CLI — `SHOW TABLES;` and `SELECT @@datadir;`
17. `ipconfig` — adapter addresses
18. `configure-hotspot-ip.ps1` in Notepad
19. Login page — desktop
20. Login page — mobile (430×932)
21. Forced password change — sign-in form before gate
22. Forced password change gate — current/new/confirm
23. Inbox — member mailbox
24. Compose — new message form
25. Contacts — address book
26. Notifications — activity feed
27. Settings — account settings and theme
28. Drafts — saved drafts
29. Sent — sent mail
30. Spam — quarantine
31. Admin dashboard — metrics and activity
32. Admin users — user governance
33. Admin logs — audit trail
34. Compose with attachment — `demo-attachment.txt` attached
35. Diagram — request pipeline
36. Diagram — login flow
37. Diagram — send flow
38. Diagram — scheduler flow
39. Hotspot login — `http://172.20.10.2/public/login.php` desktop
40. Hotspot login — `http://172.20.10.2/public/login.php` mobile viewport
41. E2E — Login page (`01-login-page.png`)
42. E2E — User A inbox (`02-userA-inbox.png`)
43. E2E — User B inbox (`03-userB-inbox.png`)
44. E2E — Admin landing (`04-admin-landing.png`)
45. E2E — Admin dashboard (`05-admin-dashboard.png`)
46. E2E — Admin users (`06-admin-users.png`)
47. E2E — Admin logs (`07-admin-logs.png`)
48. E2E — Compose form (`08-compose.png`)
49. E2E — After send (`09-after-send.png`)
50. E2E — User B inbox received (`10-userB-inbox-received.png`)
51. E2E — User B read message (`11-userB-read-message.png`)
52. E2E — Prohibited `.php` upload rejected (`12-php-upload-attempt.png`)
53. E2E — Attachment staged (`13-attach-staged.png`)
54. E2E — Sender private attachment read (`14-attach-private-read.png`)
55. E2E — Cross-user attachment download denied 403 (`15-cross-user-denied.png`)
56. E2E — Recipient attachment read (`16-userB-attachment-read.png`)
57. E2E — Admin edit user view (`17-admin-edit-throwaway.png`)
58. E2E — Change password page (`18-change-password-page.png`)
59. E2E — Drafts (`19-drafts.png`)
60. E2E — Search results (`20-search-results.png`)
61. E2E — Report spam modal (`21a-report-spam-modal.png`)
62. E2E — Spam quarantine (`21-spam.png`)
63. E2E — Notifications (`22-notifications.png`)
64. E2E — Contacts & autocomplete (`23-contacts.png`)
65. E2E — Settings (`24-settings.png`)
66. E2E — Dark theme (`25-dark-theme.png`)
67. E2E — Mobile inbox 390×844 (`26-mobile-inbox.png`)
68. E2E — Mobile compose 390×844 (`27-mobile-compose.png`)

## List of tables

1. Defense card (front)
2. Runtime stack
3. Request pipeline
4. Application root tree
5. IIS role feature set
6. NTFS ACL matrix
7. LDAP / FastCGI environment variables
8. Password and session policy
9. Database tables (live)
10. Migration ledger
11. Acceptance matrix
12. Drift ledger
13. Hotspot runbook
14. Rollback matrix
15. Command quick reference
16. Environment variable reference
17. Glossary
18. Q&A index

---

## Part 1 — System overview

Couriva is a self-contained web mail application: PHP 8.x + PDO over MariaDB, with a browser UI that behaves like a modern mailbox and an identity plane anchored in Windows Active Directory. It runs on two platforms from one codebase: Linux/LAMPP/Apache for development, Windows Server + IIS 10 FastCGI for production.

**Runtime stack**

| Layer | Live value | Proof |
|---|---|---|
| Guest OS | Windows Server 2022, build `10.0.20348.1` | Figure 1; `winver` |
| Web server | IIS 10.0 | `curl -sI http://192.168.1.178/public/login.php` → `Server: Microsoft-IIS/10.0` |
| PHP | 8.x NTS, `C:\PHP\php-cgi.exe` | Figure 6; `C:\PHP\php.ini` |
| Database | MariaDB `10.11.20-MariaDB`, `webmail`, `127.0.0.1:3306` | Figure 16 |
| Identity | AD DS domain `couriva.com` (docs say `couriva.local`), LDAPS 636 | Figures 2–3, `config/ldap.php` |
| Site | `CourivaWebMail`, physical path `C:\inetpub\wwwroot\Web_Mail_System` | Figure 8 |
| App pool | `CourivaPool`, `ApplicationPoolIdentity`, Integrated, no managed code | Figure 5 |

**Open this to prove it:** `http://192.168.1.178/public/login.php` returns HTTP 200 and the Couriva sign-in card (Figure 19). From the host: `curl -s -o /dev/null -w "%{http_code}\n" http://192.168.1.178/public/login.php` → `200`.

### 1.1 What the user sees

Sign-in card → forced password change (if flagged) → mailbox with Inbox, Sent, Drafts, Scheduled, Spam, Trash, Archive, custom folders and labels → compose with attachments, To/Cc/Bcc, auto-save, scheduling → contacts, notifications, settings/theme → admin dashboard, users, spam reports, audit logs.

### 1.2 What the system does

1. Validates CSRF token, then authenticates against AD first; local database fallback is narrow and deliberate.
2. Regenerates the session id, sets `HttpOnly` + `SameSite=Strict` cookies, records `USER_LOGIN` in `system_logs`.
3. Forces `must_change_password` users through `public/change-password.php`; all JSON APIs return HTTP 403 until changed.
4. Stores mail as rows in `emails` with normalized recipients and metadata; attachments live in `storage\attachments` under randomized names.
5. Tracks delivery per recipient in `delivery_status` with idempotency keys and capped retries.
6. Runs future sends through a scheduled queue drained by a Windows Task Scheduler task every minute.

---

## Part 2 — System flow

### 2.1 Request pipeline

Browser → IIS 10 → URL Rewrite (root → `public/index.php`, static allow-list, HTTP→HTTPS except lab/hotspot hosts) → Request Filtering (hidden segments and denied extensions) → FastCGI `*.php` handler → PHP application → PDO → MariaDB / LDAPS → response with security headers.

**Open this to prove it:** Figure 7 (denied extensions), Figure 8 (bindings), and `web.config:26-136`.

### 2.2 Login flow

```mermaid
flowchart TD
    A[POST login.php] --> B{CSRF token valid?}
    B -- no --> Z[Reject: token expired]
    B -- yes --> C[Lowercase email / resolve bare username]
    C --> D{LDAP enabled?}
    D -- yes --> E[Bind ldaps:// :636, OU=CourivaUsers]
    E -- success --> F[Sync user: auth_source=ldap, ad_object_guid]
    E -- fail --> G{Local fallback allowed?}
    G -- no --> Z
    G -- yes --> H[Local users table]
    D -- no --> H
    F --> I[session_regenerate_id + session_epoch]
    H --> I
    I --> J{must_change_password?}
    J -- yes --> K[change-password.php; APIs 403]
    J -- no --> L[Inbox]
```

**Open this to prove it:** Figure 19 shows the entry form; Figures 35–38 render the request, login, send and scheduler pipelines; `app/Services/AuthService.php`, `config/ldap.php`, `public/login.php`, `public/change-password.php`.

### 2.3 Send flow

Compose (`public/compose.php`) → validate recipients → `AttachmentService` MIME + extension checks → persist `emails` + `email_recipients` + `attachments` → create `delivery_status` rows (`queued`) → SMTP transport or database delivery → update status (`delivered`/`sent`/`failed`/`bounced`/`unknown`) → retry with `next_retry_at` (max 3). Fail-closed: a declared attachment missing on disk stops before any SMTP connection.

### 2.4 Scheduler flow

`public/scheduled.php` queues a future dispatch in `scheduled_emails` linked to the draft. `bin/process_scheduled.php` (Task Scheduler, every minute) claims due rows (`status='processing'`), delivers, marks `sent`, reclaims rows stuck >10 minutes, and exits quietly if another instance holds the lock.

**Open this to prove it:** Figure 14; `schtasks /query /tn "Couriva-ScheduledMailProcessor" /v /fo list`; `database/migrations/004_delivery_status.sql`.

---

## Part 3 — Files and locations

### 3.1 Application root tree (live Windows path)

```text
C:\inetpub\wwwroot\Web_Mail_System\
├─ admin\            dashboard, users, edit-user, create-user, logs, spam-reports, registrations
├─ app\
│  ├─ Controllers\   AdminController, AuthController, ContactController, EmailController
│  ├─ Models\        Attachment, Contact, Email, User
│  └─ Services\      AuthService, MailService, AttachmentService, AccountService,
│                    OrganizationService, ScheduledMailService, SmtpTransport, SpamService
├─ assets\           css (style, tailwind, bootstrap-icons), icons, brand, js
├─ bin\              create-admin.php, process_scheduled.php,
│                    configure-hotspot-ip.bat, configure-hotspot-ip.ps1
├─ config\           database.php, mail.php, auth.php, ldap.php
├─ database\         schema.sql, seed.sql, migrations\001..006
├─ docs\             SYSTEM_COMPREHENSIVE_AUDIT.md, flowcharts\, screenshots\, this guide
├─ includes\         auth.php, database.php, functions.php, header.php, footer.php
├─ public\           login, change-password, inbox, read, compose, drafts, sent,
│                    scheduled, spam, trash, contacts, notifications, settings,
│                    download, logout, index + api\ (theme, toggle_star, contacts, ...)
├─ storage\          attachments\, logs\, cache\   <-- ONLY writable app dirs
├─ tests\            13 automated suites + unified runner
├─ .htaccess         Apache parity hardening
├─ web.config        IIS handler, request filtering, rewrite, headers
└─ index.php         root dispatcher
```

**Open this to prove it:** Figure 9; on the guest `dir C:\inetpub\wwwroot\Web_Mail_System`.

### 3.2 Sensitive paths — never web-reachable

`config\`, `app\`, `includes\`, `database\`, `tests\`, `bin\`, `storage\`, `docs\`, `.git`, `.env` are hidden segments in `web.config:38-52`; `.sql`, `.log`, `.env`, `.ini`, `.bak`, `.md`, `.yml`, `.sh`, `.bat`, `.json`, `.lock`, `.drawio`, `.txt` are denied extensions in `web.config:55-72`.

**Open this to prove it:** `curl -s -o /dev/null -w "%{http_code}\n" http://192.168.1.178/config/database.php` → `404.8`-class denial (hidden segment). Figure 7 shows the same rules in IIS Manager.

---

## Part 4 — Windows Server build

### 4.1 Roles and features

Installed on the live guest: **AD DS, DNS, IIS, File and Storage Services** (Figure 1). Server Manager dashboard reports `Roles: 4`, `Server groups: 1`.

```powershell
Install-WindowsFeature -Name Web-Server,Web-WebServer,Web-Common-Http,Web-Default-Doc,
  Web-Http-Errors,Web-Static-Content,Web-Http-Redirect,Web-Http-Logging,Web-Filtering,
  Web-Basic-Auth,Web-Windows-Auth,Web-CGI,Web-Mgmt-Tools,Web-Mgmt-Console -IncludeManagementTools
```

**Open this to prove it:** Figure 1; `Get-WindowsFeature | Where Installed`.

### 4.2 IIS 10 site, pool, FastCGI

- Site `CourivaWebMail`, bindings `http *:80` and `https *:443` (Figure 8).
- Pool `CourivaPool`: no managed code, Integrated, `ApplicationPoolIdentity`, no idle timeout, periodic restart disabled, 50,000 request recycle (Figure 5).
- FastCGI: `C:\PHP\php-cgi.exe`, `maxInstances=0`, `instanceMaxRequests=10000`, `PHP_FCGI_MAX_REQUESTS=10000` (Figure 6). Handler `*.php` is declared **before** `StaticFile` in `web.config:26-32`.

### 4.3 DNS

Live DNS Manager shows forward zones `_msdes.couriva.com` and `couriva.com`; the `couriva.com` zone holds SOA, NS (`couriva.couriva.com`), and CNAME records (Figure 2). The hotspot script updates A records `@`, `www`, `mail`, `couriva` to `172.20.10.2`.

**Drift:** repo and LDAP defaults reference `couriva.local`; live DNS AD domain is `couriva.com`.

### 4.4 AD DS

ADUC shows OU `CourivaUsers` under `couriva.com` with demo domain users (edrich B. Bardilas, hrmar L. Centillas, John Doe, ryle P. pogi). `LDAP_ALLOWED_OU_DN` defaults to `OU=CourivaUsers,DC=couriva,DC=local`; on this host the equivalent live OU is `OU=CourivaUsers,DC=couriva,DC=com`. Figure 3.

### 4.5 PHP

`C:\PHP\php.ini` (Figure 10): `expose_php=Off`, `display_errors=Off`, `log_errors=On`, `error_log=...\storage\logs\php_errors.log`, `upload_max_filesize=64M`, `post_max_size=64M`, `extension_dir=C:\PHP\ext`, extensions `curl,fileinfo,mbstring,openssl,pdo_mysql,ldap`, session name `COURIVASESSID`, `cookie_httponly=1`, `cookie_samesite=Strict`, `gc_maxlifetime=28800`. Session directory `C:\PHP\sessions`.

### 4.6 MariaDB

MariaDB 10.11.20; service on `:3306`; `webmail` database; `@@datadir = C:\Program Files\MariaDB 10.11\data\` (Figure 16). `my.ini` sets `utf8mb4`, InnoDB, `max_allowed_packet=64M`.

### 4.7 URL Rewrite and headers

`web.config:80-118`: HTTP→HTTPS (exempts `localhost`, `127.0.0.1`, `172.20.10.`, `192.168.`, `10.`), root→`public/index.php`, static allow-list for `assets|public|admin`, front-controller fallback. Headers at `web.config:120-130`: `nosniff`, `SAMEORIGIN`, Referrer-Policy, Permissions-Policy, HSTS, CSP. **Live duplication documented in §10.6.**

### 4.8 Certificates, ACLs, Task Scheduler, Event Viewer

- Trusted Root CA for LDAPS installed in Certificates MMC (Figure 13); `C:\openldap\sysconf\ldap.conf` pins `TLS_CACERT` and `TLS_REQCERT demand` (Figure 11).
- NTFS ACL matrix:

| Path | Rights | Account |
|---|---|---|
| app\, config\, includes\, public\, admin\, database\, assets\, bin\ | Read & Execute | IUSR, IIS AppPool\CourivaPool |
| storage\attachments\, storage\logs\, storage\cache\ | Modify | IIS AppPool\CourivaPool only |
| C:\PHP\sessions | Modify | IIS AppPool\CourivaPool; Full for Administrators/SYSTEM |

**Open this to prove it:** `icacls "C:\inetpub\wwwroot\Web_Mail_System\storage\attachments"`.

- Task `Couriva-ScheduledMailProcessor` (Figure 14): action `C:\PHP\php.exe -f ...\bin\process_scheduled.php`, trigger every 1 minute, `MultipleInstances=IgnoreNew`, 10-minute execution limit, principal SYSTEM, `Last Result 0x0`.
- Event Viewer (Figure 15) correlates IIS/W3C events with Couriva `system_logs`.

### 4.9 Hotspot runbook — 172.20.10.2

Files: `bin\configure-hotspot-ip.bat` (admin check, calls PowerShell with `-IPAddress 172.20.10.2 -PrefixLength 28 -Gateway 172.20.10.1 -DNS 172.20.10.2`) and `bin\configure-hotspot-ip.ps1` (Figure 18).

| # | Action | Effect |
|---|---|---|
| 1 | Connect guest NIC to iPhone Personal Hotspot Wi‑Fi | adapter `Up` |
| 2 | Right-click `configure-hotspot-ip.bat` → Run as Administrator | elevation check passes |
| 3 | PS script sets static `172.20.10.2/28`, gateway `172.20.10.1`, DNS `172.20.10.2,127.0.0.1,172.20.10.1,1.1.1.1` | address on guest |
| 4 | `Set-NetConnectionProfile -NetworkCategory Private` | inbound LAN traffic allowed |
| 5 | Firewall rules: TCP 80, TCP/UDP 443, UDP/TCP 53 | web + DNS open |
| 6 | DNS A records `@`,`www`,`mail`,`couriva` → `172.20.10.2`; forwarders `172.20.10.1`,`1.1.1.1`,`8.8.8.8`; cache clear | name resolution |
| 7 | Ensure `*:80` IIS binding; `iisreset /restart` | site listening |
| 8 | On iPhone Safari open `http://172.20.10.2/public/login.php` | login card |

**Open this to prove it:** Figure 17 shows the lab adapter; Figure 2 shows DNS zone; on the guest `Get-NetIPAddress | ? IPAddress -like '172.20.10.*'`.

**Hotspot verification:** after the host joined the iPhone hotspot (`172.20.10.3/28`, gateway `172.20.10.1`), `curl -s -o /dev/null -w '%{http_code} %{remote_ip}' http://172.20.10.2/public/login.php` returned `200 172.20.10.2`. Figures 39–40 are fresh captures of the login card at the defense URL at desktop and 430×932 mobile viewport. The iPhone Safari application itself was not driven from this host; the mobile capture is a browser at phone viewport. Figure 17 still shows both guest addresses: lab `192.168.1.178` and hotspot `172.20.10.2`.

### 4.10 Rollback

| Change | Rollback command |
|---|---|
| Static IP | `Set-NetIPAddress -InterfaceAlias <nic> -AddressFamily IPv4 -PrefixPrefixLength 0` / re-enable DHCP: `Set-NetIPInterface -InterfaceAlias <nic> -Dhcp Enabled` |
| Firewall | `Remove-NetFirewallRule -DisplayName "Couriva WebMail (HTTP 80)"`, etc. |
| DNS records | `Set-DnsServerResourceRecord` back to lab IP, or `Remove-DnsServerResourceRecord` |
| IIS binding | `Remove-WebBinding -Name CourivaWebMail -Port 80 -IPAddress *` (if added) |
| Profile | `Set-NetConnectionProfile -NetworkCategory Public` |
| Then | `iisreset /restart`; verify `http://192.168.1.178/public/login.php` → 200 |

No persisted rollback script was added; the commands above are the operator path.

---

## Part 5 — Identity and LDAP

`config/ldap.php` reads every setting from environment (FastCGI Environment Variables in IIS). Defaults: `LDAP_ENABLED=false`, host `127.0.0.1`, port 389, `LDAP_DOMAIN=couriva.local`, `LDAP_BASE_DN=dc=couriva,dc=local`, `LDAP_ALLOWED_OU_DN=OU=CourivaUsers,DC=couriva,DC=local`, `LDAP_REQUIRE_TLS=true`, `LDAP_ALLOW_LOCAL_ADMIN_FALLBACK=true`, `LDAP_FALLBACK_LOCAL=false`, `LDAP_AUTO_CREATE=true`.

| Variable | Production value | Meaning |
|---|---|---|
| `LDAP_ENABLED` | `true` | enables domain auth |
| `LDAP_HOST` / `LDAP_PORT` | DC FQDN / `636` | LDAPS endpoint |
| `LDAP_USE_SSL` / `LDAP_USE_TLS` | `true` / `false` | LDAPS, not STARTTLS |
| `LDAP_BASE_DN` | `dc=couriva,dc=local` (doc) / `dc=couriva,dc=com` (live) | search root |
| `LDAP_ALLOWED_OU_DN` | `OU=CourivaUsers,...` | login restricted to this OU |
| `LDAP_BIND_DN` / `LDAP_BIND_PASSWORD` | service account / secret | directory search; never on disk |
| `LDAP_REQUIRE_TLS` | `true` | fail closed without TLS |
| `LDAP_AUTO_CREATE` | `true` | first login provisions mailbox |
| `LDAP_FALLBACK_LOCAL` | `false` | no local fallback for LDAP users |
| `LDAP_ALLOW_LOCAL_ADMIN_FALLBACK` | `true` | break-glass local admin only |

**Password rules:** minimum 10 characters, uppercase, lowercase, digit, symbol. LDAP users are directed to the domain controller; Couriva never changes AD passwords. `ad_object_guid` is immutable and unique (migration 006), so renames cannot hijack a mailbox.

**Open this to prove it:** Figures 3 and 11; `config/ldap.php`; `database/migrations/005_ad_auth_source.sql`, `006_ad_object_guid.sql`.

---

## Part 6 — Database

### 6.1 Connection

`config/database.php`: DSN `mysql:host=127.0.0.1;port=3306;dbname=webmail;charset=utf8mb4`, `PDO::ATTR_EMULATE_PREPARES=false`, `ERRMODE_EXCEPTION`, `SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci`, and `SET time_zone='+00:00'`. Live CLI verification (Figure 16):

```sql
SELECT @@datadir;              -- C:\Program Files\MariaDB 10.11\data\
SELECT @@version;              -- 10.11.20-MariaDB
SHOW TABLES;                   -- 14 rows
```

### 6.2 Tables (live `SHOW TABLES`, 14 rows)

1. `attachments`
2. `blocked_senders`
3. `contacts`
4. `delivery_status`
5. `email_labels`
6. `email_recipients`
7. `emails`
8. `folders`
9. `labels`
10. `notifications`
11. `scheduled_emails`
12. `spam_reports`
13. `system_logs`
14. `users`

Note: the repository audit says 13 tables and the assignment brief says 16. The live server reports **14** — record the drift, do not adjust the evidence.

### 6.3 Schema and migrations

`database/schema.sql` is the canonical DDL (no `USE`, safe for disposable DBs). Migration ledger:

| File | Purpose |
|---|---|
| `001_p1_security.sql` | `must_change_password`, `session_epoch`, sender snapshots, `ON DELETE SET NULL` |
| `002_couriva_features.sql` | approval states, folders, labels, archive/snooze, scheduled mail, notifications, blocks, spam reports |
| `003_couriva_domain.sql` | `@webmail.local` → `@couriva.local` rewrite (idempotent) |
| `004_delivery_status.sql` | durable per-recipient delivery state + idempotency |
| `005_ad_auth_source.sql` | `auth_source ENUM('local','ldap')` |
| `006_ad_object_guid.sql` | immutable `ad_object_guid` unique binding |

Fresh installs import `schema.sql` only; upgrades apply migrations 001–006 in order after backup.

### 6.4 CLI, logs, backup

```powershell
& "C:\Program Files\MariaDB 10.11\bin\mysql.exe" -u root -p webmail < database\schema.sql
& "C:\Program Files\MariaDB 10.11\bin\mysqldump.exe" -u root -p --single-transaction --quick webmail > webmail_backup.sql
Compress-Archive -Path storage\attachments\* -DestinationPath attachments_backup.zip
```

Application logs: `storage\logs\php_errors.log`, `storage\logs\` (Figure 12), `system_logs` table, IIS W3C logs, Windows Event Viewer (Figure 15). Backup integrity: every `attachments.stored_filename` in the dump must exist in the zip.

**Open this to prove it:** Figure 16; `dir C:\inetpub\wwwroot\Web_Mail_System\storage\logs`.

---

## Part 7 — Member demo click-path

1. Open lab `http://192.168.1.178/public/login.php` (Figure 19) or mobile (Figure 20). On hotspot use `http://172.20.10.2/public/login.php`.
2. Sign in with the disposable AD account created for the demo. Never read its password from the book.
3. If flagged, the app forces `public/change-password.php` (complexity gate; all APIs 403).
4. Inbox: category tabs, star, attachment chips, thread accordion in `read.php`.
5. Compose: To/Cc/Bcc, attachment, auto-save every 30s, optional schedule.
6. Verify Sent, Drafts, Scheduled, Spam, Trash; contacts autocomplete; notifications badge; settings theme light/dark/system.

**Open this to prove it:** navigate each URL directly; every protected route redirects to login when unauthenticated (`require_auth()`).

*Capture status:* login screens are fresh from the live VM (Figures 19–20). Authenticated member screens were captured against the live VM web app via a disposable AD/local account (Figures 22–34); no passwords were written to any file. Safari at `172.20.10.2` remains pending until the host joins the hotspot.

---

## Part 8 — Administrator / operator

`admin/index.php` shows account counts, message volume, attachment bytes and a live activity feed. `admin/users.php` governs status/role/session epoch; web creation is disabled (HTTP 403). `admin/edit-user.php` issues temporary passwords, sets `must_change_password=1`, bumps `session_epoch`. `admin/logs.php` filters `system_logs` by action, status, IP, user and keyword. `admin/spam-reports.php` resolves/dismisses reports; `admin/registrations.php` is retired (HTTP 410).

Break-glass provisioning (only supported local admin path):

```powershell
$env:ADMIN_EMAIL='admin@couriva.local'
$env:ADMIN_PASSWORD='<type-live-secret>'
$env:ADMIN_FIRST_NAME='System'; $env:ADMIN_LAST_NAME='Administrator'
& C:\PHP\php.exe C:\inetpub\wwwroot\Web_Mail_System\bin\create-admin.php
```

**Open this to prove it:** `admin/logs.php`; `curl -s -o /dev/null -w "%{http_code}\n" http://192.168.1.178/admin/create-user.php` → `403`.

---

## Part 9 — Testing and acceptance

`npm test` runs the unified offline suite. Live smoke:

| Check | Expected |
|---|---|
| `GET /public/login.php` | 200, rendered form (Figure 19) |
| `GET /config/database.php` | 404.8 hidden segment |
| `GET /storage/attachments/` | 404.8 |
| `GET /database/schema.sql` | 404.8 / 404.7 |
| `GET /.env` | 404.7 |
| `GET /public/register.php` | 410 |
| `POST /admin/create-user.php` | 403 |
| Temp-password user `GET /public/inbox.php` | redirect to change-password |
| Temp-password user `POST /api/toggle_star.php` | 403 JSON |
| `schtasks /run /tn "Couriva-ScheduledMailProcessor"` | Last Result `0x0` |
| AD in-OU login | auto-provision, `auth_source='ldap'`, GUID set |
| AD out-of-OU login | rejected, no row created |

**Open this to prove it:** `COURIVA_TESTING_AND_ACCEPTANCE.md` categories 1–6; `tests/`; Figures 7, 14, 16.

### 9.2 Live E2E Verification Run (`agent-browser`, 2026-11-26)

Full browser E2E run executed against `http://172.20.10.2/public/login.php` using `agent-browser` 0.44.0 across three persistent sessions (`seno@couriva.com`, `olivar@couriva.com`, `admin@couriva.com`). Raw report archived at [docs/e2e/report.md](e2e/report.md).

| Metric | Count | Notes |
|---|---:|---|
| Checks passed | 33 | Auth, CSRF, RBAC, A↔B mail, attachments, admin, drafts, spam, search, contacts, notifications, theme, mobile, logout |
| Checks failed | 1 | `BUG-001` (duplicate security headers / dual CSP) — **Fixed** in `web.config` and verified on Windows Server (`172.16.13.138`) |
| Deviations (P3) | 2 | `DEV-001`: `/forgot-password.php` etc. return `302` catch-all to login; `DEV-002`: `admin/registrations.php` returns `GET 200` retirement notice (`POST 410`) |
| Blocked / not tested | 3 | Forced password-change gate & prior-session invalidation (no local non-admin throwaway), DC-side AD lockout/out-of-OU |

**E2E Flow Summary (Figures 41–68):**
- **Authentication & Session Security (Figures 41–44, 58):** Login form CSRF validation, bad-password rejection, AD account landing, and logout session/cookie invalidation.
- **Admin Governance & Audit Trail (Figures 45–47, 57):** Dashboard metrics, user governance table with `Protected` admin badges, `system_logs` audit trail, and AD password-reset guard on `edit-user.php`.
- **Mail Delivery & Threading (Figures 48–51):** User A→B send, B→A reply (`Re:`), and B→`augusto@couriva.com` forward (`Fwd:`).
- **Attachment Security & Access Control (Figures 52–56):** `.php` payload upload rejected, `.txt` attachment staged and delivered, non-party cross-user download denied (`403`), sender & recipient downloads verified (`200`).
- **Mailbox Features, Theme & Mobile (Figures 59–68):** Draft save/reopen, inbox-scoped search, report-spam modal + `[SPAM]` quarantine, unread notifications, contacts + compose autocomplete, settings + dark theme toggle, and 390×844 mobile inbox/compose.

---

## Part 10 — Troubleshooting and drift

### 10.1 Login loop / forced password change

Symptom: redirect to `change-password.php` on every route. Cause: `must_change_password=1`. Fix: complete the form (10+ chars, four classes) or reset via `admin/edit-user.php`. API 403 during this state is intentional.

### 10.2 500.0 FastCGI

Symptom: all `.php` return 500.0. Cause: PHP path mismatch. Fix: register `C:\PHP\php-cgi.exe` globally and align `web.config:29`.

### 10.3 404.8 on `docs/` or `storage/`

Expected hardening. Use the filesystem (`dir`) or the guide figures, not HTTP, to inspect those paths.

### 10.4 Duplicate CSP / blocked inline scripts

Two CSP headers are present live: static from `web.config:129` (no nonce) and dynamic from PHP (with nonce). Browsers intersect them, so nonce-bearing inline scripts can be blocked. Recorded as drift; do not claim a clean single header.

### 10.5 Scheduled mail not sending

Check `schtasks /query /tn "Couriva-ScheduledMailProcessor" /v`, `storage\logs\`, `scheduled_emails.status`, `delivery_status.next_retry_at`, and the 10-minute stale-job reclaim.

### 10.6 Drift ledger (read this before answering)

| # | Documentation says | Live evidence | Ruling |
|---|---|---|---|
| 1 | Windows Server 2019 | VM title "Windows Server 2022", build `10.0.20348.1` (Figure 1) | Live VM is 2022 |
| 2 | Single CSP from `web.config:129` | `curl -sI` shows two CSP headers; second has `'nonce-…'` | Duplicate headers live |
| 3 | 13 tables (audit) / 16 tables (brief) | `SHOW TABLES;` → 14 rows (Figure 16) | Live count is 14 |
| 4 | AD domain `couriva.local` | DNS zone `couriva.com`, ADUC `couriva.com` (Figures 2–3) | Live domain is `couriva.com` |
| 5 | Data dir unknown | `SELECT @@datadir;` → `C:\Program Files\MariaDB 10.11\data\` | Verified, not guessed |
| 6 | Console window `0x02400007` | Window id changed to `0x02a00007`; driven via Xvfb `:99` | Environment drift |
| 7 | Hotspot `172.20.10.2` reachable | Initially unreachable; after host joined hotspot, `curl` → `200 172.20.10.2` and Figures 39–40 captured | Verified; Safari device UI not driven |
| 8 | `LDAP_ALLOW_LOCAL_ADMIN_FALLBACK=false` | Temporarily set to `true` for capture, then restored to `false` with `iisreset /restart`; disposable local + AD accounts deleted | Production posture restored; no residual demo identity |

### 10.7 Redaction rule

No password, bind password, token or session id appears in this book or its screenshots. The login card shows placeholders only; the MariaDB screen shows no secret.

---

## Appendix A — Command quick reference

```bash
# Host
curl -s -o /dev/null -w "%{http_code}\n" http://192.168.1.178/public/login.php
curl -sI http://192.168.1.178/public/login.php | grep -i -E 'server|content-security|nosniff'
npm test

# Guest PowerShell (elevated)
Get-WindowsFeature | Where Installed
Get-NetIPAddress | Format-Table IPAddress,InterfaceAlias
schtasks /query /tn "Couriva-ScheduledMailProcessor" /v /fo list
icacls "C:\inetpub\wwwroot\Web_Mail_System\storage\attachments"
certutil -store -user Root

# MariaDB CLI
SELECT @@datadir; SELECT @@version; SHOW DATABASES; USE webmail; SHOW TABLES;
SELECT email,role,status,auth_source FROM users;
```

## Appendix B — Environment variables

`APP_ENV`, `WEBMAIL_FORCE_HTTPS`, `TRUST_PROXY_HEADERS`, `SESSION_IDLE_TIMEOUT` (1800), `SESSION_ABSOLUTE_TIMEOUT` (28800), `SMTP_VERIFY_PEER`, `SMTP_VERIFY_PEER_NAME`, `SMTP_CA_FILE`, `SMTP_INSECURE_TLS`, `MAIL_DRIVER` (`database`/`smtp`), `MAIL_DOMAIN` (`couriva.local`), `DB_HOST/PORT/NAME/USER/PASS`, and all `LDAP_*` keys in Part 5. SMTP TLS verification is on by default; `SMTP_INSECURE_TLS` is ignored unless `APP_ENV=development`.

## Appendix C — File map

| Need | File |
|---|---|
| Prove DB settings | `config/database.php` |
| Prove LDAP defaults | `config/ldap.php` |
| Prove IIS hardening | `web.config` |
| Prove schema | `database/schema.sql` |
| Prove migrations | `database/migrations/001..006` |
| Prove test matrix | `COURIVA_TESTING_AND_ACCEPTANCE.md` |
| Prove IIS requirements | `COURIVA_IIS_DEPLOYMENT_CHECKLIST.md` |
| Prove architecture | `docs/SYSTEM_COMPREHENSIVE_AUDIT.md` |
| Change hotspot IP | `bin/configure-hotspot-ip.bat` / `.ps1` |
| Provision break-glass admin | `bin/create-admin.php` |
| Drain scheduled mail | `bin/process_scheduled.php` |

## Appendix D — Glossary

**AD DS** Active Directory Domain Services. **LDAPS** LDAP over TLS, port 636. **OU** organizational unit. **UPN** user principal name. **FastCGI** IIS PHP process bridge. **CSRF** cross-site request forgery token. **RBAC** role-based access control. **idempotency key** unique key preventing duplicate delivery. **hidden segment** IIS request-filtering rule returning 404.8. **session epoch** DB counter invalidating older sessions. **objectGUID** immutable AD identity.

## Appendix E — Full Q&A

**Q: Two login URLs — which is correct?** A: Both. Lab `192.168.1.178` is reachable now; hotspot `172.20.10.2` is for defense day after joining the iPhone hotspot.
**Q: Is self-registration available?** A: No — retired (410); web user creation 403.
**Q: Can LDAP users change password in Couriva?** A: No — AD only.
**Q: What happens if AD is unreachable?** A: Local break-glass admin can still log in if `LDAP_ALLOW_LOCAL_ADMIN_FALLBACK=true`.
**Q: How many tables?** A: Live: 14. Docs disagree; evidence wins.
**Q: Where is the data dir?** A: `SELECT @@datadir;` → `C:\Program Files\MariaDB 10.11\data\`.
**Q: Are attachments executable?** A: No — MIME sniffing, extension blacklist, randomized names, denied storage path.
**Q: How often does the scheduler run?** A: Every minute; stale jobs >10 min reclaimed.
**Q: Are credentials in this book?** A: No.
**Q: What remains to capture?** A: Nothing in the book scope. Authenticated web screens: Figures 22–34. Hotspot defense URL: Figures 39–40. Only the native iPhone Safari chrome itself is not driven from this host.

---

## Figure plates

![Figure 1. Server Manager — installed roles: IIS, AD DS, DNS, File and Storage Services.](screenshots/guide-01-server-manager-roles.png)

*Figure 1. Server Manager — installed roles: IIS, AD DS, DNS, File and Storage Services. Live VM, 2026-11-25.*

![Figure 2. DNS Manager — couriva.com forward lookup zone.](screenshots/guide-02-dns-manager.png)

*Figure 2. DNS Manager — `couriva.com` forward lookup zone. Live VM.*

![Figure 3. ADUC — CourivaUsers OU.](screenshots/guide-03-aduc.png)

*Figure 3. Active Directory Users and Computers — `CourivaUsers` OU. Live VM.*

![Figure 4. IIS Manager — CourivaWebMail.](screenshots/guide-04-iis-manager.png)

*Figure 4. IIS Manager — CourivaWebMail site and server features. Live VM.*

![Figure 5. IIS Manager — CourivaPool.](screenshots/guide-05-iis-app-pool.png)

*Figure 5. IIS Manager — `CourivaPool` application pool. Live VM.*

![Figure 6. IIS Manager — FastCGI settings.](screenshots/guide-06-fastcgi.png)

*Figure 6. IIS Manager — FastCGI `C:\PHP\php-cgi.exe`, 10000 requests. Live VM.*

![Figure 7. IIS Manager — Request Filtering.](screenshots/guide-07-request-filtering.png)

*Figure 7. IIS Manager — Request Filtering denied extensions. Live VM.*

![Figure 8. IIS Manager — site bindings.](screenshots/guide-08-iis-bindings.png)

*Figure 8. IIS Manager — site bindings (`http :80`, `https :443`). Live VM.*

![Figure 9. File Explorer — application root.](screenshots/guide-09-file-tree.png)

*Figure 9. File Explorer — application root tree. Live VM.*

![Figure 10. php.ini.](screenshots/guide-10-php-ini.png)

*Figure 10. `C:\PHP\php.ini` in Notepad. Live VM.*

![Figure 11. ldap.conf.](screenshots/guide-11-ldap-conf.png)

*Figure 11. `C:\openldap\sysconf\ldap.conf` in Notepad. Live VM.*

![Figure 12. storage\logs.](screenshots/guide-12-storage-logs.png)

*Figure 12. `storage\logs` in File Explorer. Live VM.*

![Figure 13. Certificates MMC.](screenshots/guide-13-certificates.png)

*Figure 13. Certificates MMC — trusted roots for LDAPS. Live VM.*

![Figure 14. Task Scheduler.](screenshots/guide-14-task-scheduler.png)

*Figure 14. Task Scheduler — `Couriva-ScheduledMailProcessor`. Live VM.*

![Figure 15. Event Viewer.](screenshots/guide-15-event-viewer.png)

*Figure 15. Event Viewer — IIS/Windows events. Live VM.*

![Figure 16. MariaDB CLI.](screenshots/guide-16-mariadb-cli.png)

*Figure 16. MariaDB CLI — `SHOW TABLES;` (14 rows) and verified `SELECT @@datadir;`. Live VM.*

![Figure 17. ipconfig.](screenshots/guide-17-ipconfig.png)

*Figure 17. `ipconfig` — adapter addresses on the live guest.*

![Figure 18. Hotspot script.](screenshots/guide-18-hotspot-script.png)

*Figure 18. `bin\configure-hotspot-ip.ps1` in Notepad. Live VM.*

![Figure 19. Login page — desktop.](screenshots/guide-19-web-login-desktop.png)

*Figure 19. Login page at `192.168.1.178/public/login.php` — desktop. Live VM.*

![Figure 20. Login page — mobile.](screenshots/guide-20-web-login-mobile.png)

*Figure 20. Login page at `192.168.1.178/public/login.php` — 430×932 mobile viewport. Fresh headless capture against the live VM web app.*

![Figure 21. Sign-in form before the forced change.](screenshots/guide-21-forced-password-change.png)

*Figure 21. Sign-in form at `192.168.1.178/public/login.php` — captured before the disposable account login.*

![Figure 22. Forced password-change gate.](screenshots/guide-22-change-password-gate.png)

*Figure 22. Forced password-change gate — only authenticated destination while `must_change_password=1`.*

![Figure 23. Inbox.](screenshots/guide-23-inbox.png)

*Figure 23. Member inbox — category tabs, sidebar folders and message list.*

![Figure 24. Compose.](screenshots/guide-24-compose.png)

*Figure 24. Compose — To/Cc/Bcc, rich text, attach zone, Send / Save as Draft / Schedule.*

![Figure 25. Contacts.](screenshots/guide-25-contacts.png)

*Figure 25. Contacts — personal address book.*

![Figure 26. Notifications.](screenshots/guide-26-notifications.png)

*Figure 26. Notifications — unread/total feed.*

![Figure 27. Settings.](screenshots/guide-27-settings.png)

*Figure 27. Settings — account preferences and theme controls.*

![Figure 28. Drafts.](screenshots/guide-28-drafts.png)

*Figure 28. Drafts — saved drafts view.*

![Figure 29. Sent.](screenshots/guide-29-sent.png)

*Figure 29. Sent — outgoing mail repository.*

![Figure 30. Spam.](screenshots/guide-30-spam.png)

*Figure 30. Spam — quarantine folder.*

![Figure 31. Admin dashboard.](screenshots/guide-31-admin-dashboard.png)

*Figure 31. Admin dashboard — account/message/storage metrics and recent activity.*

![Figure 32. Admin users.](screenshots/guide-32-admin-users.png)

*Figure 32. Admin users — user governance.*

![Figure 33. Admin logs.](screenshots/guide-33-admin-logs.png)

*Figure 33. Admin logs — filterable `system_logs` audit trail.*

![Figure 34. Compose with attachment.](screenshots/guide-34-compose-attachment.png)

*Figure 34. Compose with attachment — `demo-attachment.txt` shown as an attached file chip.*

![Figure 35. Request pipeline diagram.](screenshots/guide-35-request-flow.png)

*Figure 35. Request pipeline — browser, IIS filtering, rewrite, FastCGI, app, MariaDB/LDAP, response.*

![Figure 36. Login flow diagram.](screenshots/guide-36-login-flow.png)

*Figure 36. Login flow — CSRF, normalisation, LDAPS bind, local break-glass fallback, session, gate.*

![Figure 37. Send flow diagram.](screenshots/guide-37-send-flow.png)

*Figure 37. Send flow — compose, validation, persistence, `delivery_status`, SMTP, reconciliation.*

![Figure 38. Scheduler flow diagram.](screenshots/guide-38-scheduler-flow.png)

*Figure 38. Scheduler flow — queue, Task Scheduler claim, flock lock, delivery, stale-job reclaim.*

![Figure 39. Hotspot login — desktop.](screenshots/guide-39-hotspot-login.png)

*Figure 39. Defense URL `http://172.20.10.2/public/login.php` — desktop capture after host joined hotspot. Verified `http=200`, `remote_ip=172.20.10.2`.*

![Figure 40. Hotspot login — mobile viewport.](screenshots/guide-40-hotspot-login-mobile.png)

*Figure 40. Defense URL `http://172.20.10.2/public/login.php` — 430×932 mobile viewport capture (phone-sized browser, not the Safari app itself).*

### E2E Verification Plates (2026-11-26)

![Figure 41. E2E — Login page.](screenshots/e2e-01-login-page.png)

*Figure 41. E2E T01 — Login page renders with CSRF token and no self-registration link.*

![Figure 42. E2E — User A inbox.](screenshots/e2e-02-userA-inbox.png)

*Figure 42. E2E — User A (`seno@couriva.com`) authenticated inbox landing.*

![Figure 43. E2E — User B inbox.](screenshots/e2e-03-userB-inbox.png)

*Figure 43. E2E — User B (`olivar@couriva.com`) authenticated inbox landing.*

![Figure 44. E2E — Admin landing.](screenshots/e2e-04-admin-landing.png)

*Figure 44. E2E T12 — Admin (`admin@couriva.com`) login lands without forced password-change gate.*

![Figure 45. E2E — Admin dashboard.](screenshots/e2e-05-admin-dashboard.png)

*Figure 45. E2E T23 — Admin dashboard showing account, message, and attachment metrics.*

![Figure 46. E2E — Admin users.](screenshots/e2e-06-admin-users.png)

*Figure 46. E2E T24 — Admin User Governance table with roles, statuses, and `Protected` admin badges.*

![Figure 47. E2E — Admin logs.](screenshots/e2e-07-admin-logs.png)

*Figure 47. E2E T25 — Admin System Audit Logs table.*

![Figure 48. E2E — Compose form.](screenshots/e2e-08-compose.png)

*Figure 48. E2E T15 — User A composing message `E2E_A_TO_B_070549` to User B.*

![Figure 49. E2E — After send.](screenshots/e2e-09-after-send.png)

*Figure 49. E2E T15 — Confirmation after sending message from User A.*

![Figure 50. E2E — User B inbox received.](screenshots/e2e-10-userB-inbox-received.png)

*Figure 50. E2E T15 — User B inbox showing delivered message from User A.*

![Figure 51. E2E — User B read message.](screenshots/e2e-11-userB-read-message.png)

*Figure 51. E2E T16/T17 — User B reading message with reply and forward controls.*

![Figure 52. E2E — Prohibited .php upload rejected.](screenshots/e2e-12-php-upload-attempt.png)

*Figure 52. E2E T18 — Uploading `e2e-payload.php` rejected with prohibited-type alert.*

![Figure 53. E2E — Attachment staged.](screenshots/e2e-13-attach-staged.png)

*Figure 53. E2E T19 — Valid `.txt` attachment staged on compose (`1 file(s) attached (51 B)`).*

![Figure 54. E2E — Sender private attachment read.](screenshots/e2e-14-attach-private-read.png)

*Figure 54. E2E T21 — Sender downloads own attachment (`200 OK`, `text/plain`, 51 bytes).*

![Figure 55. E2E — Cross-user attachment download denied.](screenshots/e2e-15-cross-user-denied.png)

*Figure 55. E2E T20 — Non-party User B denied access to User A's private attachment (`403 Access denied to attachment.`).*

![Figure 56. E2E — Recipient attachment read.](screenshots/e2e-16-userB-attachment-read.png)

*Figure 56. E2E T22 — Recipient User B reads and downloads shared attachment (`200 OK`).*

![Figure 57. E2E — Admin edit user view.](screenshots/e2e-17-admin-edit-throwaway.png)

*Figure 57. E2E N01 — Admin edit view for AD-managed user showing AD password-reset guard.*

![Figure 58. E2E — Change password page.](screenshots/e2e-18-change-password-page.png)

*Figure 58. E2E — Change password page view.*

![Figure 59. E2E — Drafts.](screenshots/e2e-19-drafts.png)

*Figure 59. E2E T26 — Saved draft persisted and reopened with To/Subject/Body intact.*

![Figure 60. E2E — Search results.](screenshots/e2e-20-search-results.png)

*Figure 60. E2E T28 — Inbox-scoped search returning matching message.*

![Figure 61. E2E — Report spam modal.](screenshots/e2e-21a-report-spam-modal.png)

*Figure 61. E2E T27 — Report spam confirmation modal.*

![Figure 62. E2E — Spam quarantine.](screenshots/e2e-21-spam.png)

*Figure 62. E2E T27 — Reported message moved to Spam with `[SPAM]` prefix.*

![Figure 63. E2E — Notifications.](screenshots/e2e-22-notifications.png)

*Figure 63. E2E T30 — Notifications panel listing unread messages.*

![Figure 64. E2E — Contacts and autocomplete.](screenshots/e2e-23-contacts.png)

*Figure 64. E2E T29 — Contact saved in address book and suggested by compose autocomplete.*

![Figure 65. E2E — Settings.](screenshots/e2e-24-settings.png)

*Figure 65. E2E T31 — Account settings and theme selector.*

![Figure 66. E2E — Dark theme.](screenshots/e2e-25-dark-theme.png)

*Figure 66. E2E T31 — Dark theme (`data-bs-theme="dark"`) applied.*

![Figure 67. E2E — Mobile inbox 390x844.](screenshots/e2e-26-mobile-inbox.png)

*Figure 67. E2E T32 — Mobile viewport (390×844) inbox with hamburger nav and compose FAB.*

![Figure 68. E2E — Mobile compose 390x844.](screenshots/e2e-27-mobile-compose.png)

*Figure 68. E2E T32 — Mobile viewport (390×844) compose view with reachable controls.*

---

## Index

Admin dashboard → Part 8; AD DS → Part 4.4; attachment security → Part 2.3; backup → Part 6.4; bindings → Part 4.2; break-glass admin → Part 5; CSP drift → Part 10.4, 10.6; CSRF → Part 2.2; data directory → Part 6.1; DNS → Part 4.3; FastCGI → Part 4.2; forced password change → Part 2.2, 10.1; hidden segments → Part 3.2; hotspot → Part 4.9–4.10; IIS → Part 4.2; LDAP → Part 5; login flow → Part 2.2; MariaDB → Part 4.6, 6; migrations → Part 6.3; NTFS ACL → Part 4.8; objectGUID → Part 5, 6.3; request pipeline → Part 2.1; scheduled mail → Part 2.4, 10.5; security headers → Part 4.7; session epoch → Part 1.2; tables → Part 6.2; Task Scheduler → Part 4.8; testing → Part 9; TLS → Part 4.8, 5; URL Rewrite → Part 4.7; verification stamp → cover.
