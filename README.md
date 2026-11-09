# Couriva_WebMail_system

An enterprise-grade, browser-based electronic mail management application built with **PHP 8.x**, **PDO**, and **MySQL/MariaDB**, optimized for deployment on **Windows Server 2019 using IIS 10** as well as Linux **LAMPP / Apache**.

---

## 1. Directory Structure

Conforming directly to **Section 27** of the technical architecture specification:

```text
/opt/lampp/htdocs/Web_Mail_System/
├── app/
│   ├── Controllers/
│   │   ├── AdminController.php
│   │   ├── AuthController.php
│   │   ├── ContactController.php
│   │   └── EmailController.php
│   ├── Models/
│   │   ├── Attachment.php
│   │   ├── Contact.php
│   │   ├── Email.php
│   │   └── User.php
│   └── Services/
│       ├── AttachmentService.php
│       ├── AuthService.php
│       └── MailService.php
├── config/
│   ├── database.php
│   └── mail.php
├── public/
│   ├── api/
│   │   └── toggle_star.php
│   ├── compose.php
│   ├── contacts.php
│   ├── download.php
│   ├── drafts.php
│   ├── inbox.php
│   ├── index.php
│   ├── login.php
│   ├── logout.php
│   ├── read.php
│   ├── sent.php
│   ├── settings.php
│   ├── spam.php
│   └── trash.php
├── admin/
│   ├── create-user.php
│   ├── edit-user.php
│   ├── index.php
│   ├── logs.php
│   └── users.php
├── includes/
│   ├── auth.php
│   ├── database.php
│   ├── footer.php
│   ├── functions.php
│   └── header.php
├── assets/
│   ├── css/
│   │   └── style.css
│   ├── images/
│   └── js/
│       └── app.js
├── storage/
│   ├── attachments/
│   ├── cache/
│   └── logs/
├── database/
│   ├── schema.sql
│   └── seed.sql
├── .htaccess
├── index.php
├── web.config
└── README.md
```

### Frontend asset build

The UI uses a local Tailwind utility layer alongside the application stylesheet.
After changing templates or `assets/css/tailwind.css`, rebuild it with:

```bash
npm install
npm run build:css
```

The generated `assets/css/tailwind.generated.css` file is loaded locally by the
shared header; no Tailwind CDN is required.

Bootstrap Icons are also bundled locally under `assets/css/` and `assets/icons/`
so icon rendering does not depend on external CDNs or browser webfont loading.

---

## 2. Administrator Provisioning

No default or demo credentials ship with this project. Public self-registration
is disabled; accounts are created only by an administrator.

Provision the first administrator from the command line:

```bash
ADMIN_EMAIL='admin@your-domain.example' \
ADMIN_PASSWORD='use-a-long-random-password' \
ADMIN_FIRST_NAME='System' \
ADMIN_LAST_NAME='Administrator' \
php bin/create-admin.php
```

The account is flagged to change its password at first login. The seeded sample
accounts (`john.doe@couriva.local`, `jane.smith@couriva.local`) contain unusable
random password hashes and also require an administrator-provisioned password
change before use. Never place plaintext credentials in source control.

---

## 3. Database Architecture & Performance

### Tables:
1. `users`: Credentials, names, role (`admin`/`user`), status (`active`/`disabled`), avatar, signature, timezone, timestamps.
2. `emails`: Mailbox items (`inbox`, `sent`, `draft`, `trash`, `spam`), sender/recipient references, `parent_email_id` (threading), `is_read`, `is_starred`, `is_draft`, soft deletion `deleted_at`.
3. `email_recipients`: Multi-recipient support (`to`, `cc`, `bcc`) mapping emails to users or external addresses.
4. `attachments`: Attachment metadata (original name, sanitized stored name, physical path, MIME type, size).
5. `contacts`: User personal address book with phone, company, and notes.
6. `system_logs`: Audit trail (action, user ID, client IP, user agent, status, timestamp, details).

### Performance Optimization:
- Composite index on `(recipient_id, folder, created_at)` for instant mailbox pagination.
- Composite index on `(sender_id, folder, created_at)` for sent mail loading.
- Sub-part index on `subject(191)` for high-efficiency search and sorting.
- Foreign key constraints with cascading deletes for attachments/recipients and `SET NULL` for user decoupling.

### Import Commands:
```bash
/opt/lampp/bin/mysql -u root < database/schema.sql
/opt/lampp/bin/mysql -u root webmail < database/seed.sql
```

---

## 4. Cross-Platform Server Configurations

### A. Windows Server 2019 / IIS 10 (`web.config`)
- **FastCGI Handler**: Maps `*.php` to `C:\PHP\php-cgi.exe` via `FastCgiModule`. The FastCGI mapping is declared **before** the catch-all `StaticFile` handler so `.php` files in `public/`, `config/`, and `storage/` are executed by PHP (and then denied by the application/rules), never served as source.
- **Request Filtering**: Isolates internal directories (`/storage`, `/config`, `/app`, `/database`, `/includes`, `/tests`, `/bin`, `.git`, `.env`) with HTTP 404.7 / 403.
- **File Extensions Blocked**: Direct access to `.sql`, `.log`, `.env`, `.ini`, `.bak`, `.md`, `.yml` is rejected.
- **Request Limits**: Max allowed content length set to 64 MB for attachment uploads.
- **URL Rewrite Rules**:
  - Redirects HTTP to HTTPS (`{HTTPS}` server variable).
  - Automatically routes root requests to `public/index.php`.
  - Preserves direct access to static files in `assets/`, `public/`, and `admin/`.
  - Clean URL fallback forwarding.
- **Security Response Headers**: Sets `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`, `Content-Security-Policy`, `Permissions-Policy`, HSTS on HTTPS, and `Referrer-Policy: strict-origin-when-cross-origin`.

> IIS runtime verification could not be performed in this Linux environment. The configuration is syntactically validated (`web.config` XML + security audit assertions) and the exact IIS checks required are documented in the P1 gate report.

### B. Linux / Apache / LAMPP (`.htaccess`)
- Root `.htaccess` redirects root traffic cleanly to `/public/index.php`.
- Rewrite rules block direct access to `/storage`, `/config`, `/app`, `/database`, `/includes`, `/tests`, and `/bin`.
- **HTTPS enforcement**: real hostnames are redirected to HTTPS (including `X-Forwarded-Proto` from a trusted TLS-terminating proxy). Loopback hosts (`localhost`, `127.0.0.1`, `[::1]`) intentionally remain usable over plain HTTP so local LAMPP development is not broken.
- Defense-in-depth `.htaccess` placed directly inside `storage/`, `config/`, `app/`, and `database/` enforcing `Require all denied` (Apache 2.4+) and `Deny from all` (Apache 2.2).
- Sensitive extensions (`.sql`, `.log`, `.env`, `.md`, etc.) are denied.

### C. Environment Variables
| Variable | Default | Purpose |
|---|---|---|
| `APP_ENV` | `production` | Must be `development` for the SMTP TLS dev override. |
| `WEBMAIL_FORCE_HTTPS` | `false` | Set `true` to force an application-level HTTPS redirect. |
| `TRUST_PROXY_HEADERS` | `false` | Set `true` only behind a trusted reverse proxy; honors `X-Forwarded-Proto` / `X-Forwarded-SSL`. |
| `SESSION_IDLE_TIMEOUT` | `1800` | Idle timeout in seconds. |
| `SESSION_ABSOLUTE_TIMEOUT` | `28800` | Absolute session lifetime in seconds. |
| `SMTP_VERIFY_PEER` | `true` | Verify the SMTP server certificate chain. |
| `SMTP_VERIFY_PEER_NAME` | `true` | Verify the SMTP server certificate hostname. |
| `SMTP_CA_FILE` | none | Custom CA bundle for private SMTP infrastructure. |
| `SMTP_INSECURE_TLS` | `false` | Development-only escape hatch; ignored unless `APP_ENV=development`. |

---

## 5. Security Architecture

1. **SQL Injection Protection**: All queries utilize PDO prepared statements with strict parameter binding. `PDO::ATTR_EMULATE_PREPARES` is disabled.
2. **Cross-Site Scripting (XSS)**: Output is strictly escaped with `htmlspecialchars(..., ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`.
3. **Cross-Site Request Forgery (CSRF)**: Cryptographically secure tokens (`random_bytes(32)`) generated per session and validated on state-changing requests (`POST`).
4. **Session Fixation Defense**: Sessions regenerate ID on login via `session_regenerate_id(true)`. Cookie flags enforced: `HttpOnly`, `SameSite=Strict`, and `Secure` when under HTTPS.
5. **Role-Based Access Control (RBAC)**: Strict separation between standard `user` and `admin` routes via `require_admin()` middleware.
6. **Attachment Sanitization**: File uploads are scanned for blocked executable extensions (`.php`, `.exe`, `.sh`, `.bat`, etc.), validated with `finfo_file` MIME inspection, and stored under randomized non-executable filenames in protected storage.
