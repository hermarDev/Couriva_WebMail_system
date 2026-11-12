# Couriva WebMail System — Administrator-Only Password Recovery Workflow & Flowchart Specification

**Document:** `COURIVA_PASSWORD_RECOVERY_FLOW.md`  
**Standard Compliance:** ANSI X3.5-1970 & ISO 5807:1985 Information Processing Documentation Standards  
**Target Architecture:** Windows Server 2019 / IIS 10 & Hybrid Active Directory / MariaDB Identity  
**Application Path:** `/opt/lampp/htdocs/Web_Mail_System`  

---

## 1. Architectural Philosophy & Zero-Trust Recovery

Unlike consumer web applications that provide automated email or SMS self-service password resets, enterprise environments and high-security intranets prohibit public self-service recovery mechanisms to eliminate:
1. **Email-loop account takeovers:** If an attacker compromises an email account, they cannot reset other corporate credentials.
2. **SIM-swapping & SMS interception:** Automated token delivery over untrusted public telecom networks is eliminated.
3. **Automated credential harvesting & enumeration:** Attackers cannot probe endpoints (e.g. `/forgot-password.php`) to harvest valid organizational usernames or email addresses.

Couriva implements a strict **Administrator-Governed Identity Recovery Workflow**. All password resets require out-of-band verification by an authorized system administrator before a temporary password can be issued.

---

## 2. Core Security Requirements & State Machine

```text
[State 0: Active / Locked Out]
               │
               ▼
   [Out-of-Band Admin Verification]
               │
               ▼
[Admin Issues Temporary Password] ──► [users.session_epoch + 1] (Prior Sessions Destroyed)
               │                 ──► [users.must_change_password = 1]
               │                 ──► [system_logs: Action recorded, Credential NOT logged]
               ▼
   [User Logs In with Temp Password]
               │
               ├──► [must_change_password == 1 ?]
               │            │
               │            ▼ (Yes)
               │   [Redirect to change-password.php]
               │   [All Protected Routes Blocked]
               │   [All JSON APIs Return HTTP 403]
               │            │
               │            ▼
   [User Submits Compliant New Password]
               │
               ▼
[Verify Current Temp Pass == Entered Current Pass]
[Validate Complexity: Length >= 10, Upper, Lower, Number, Symbol]
[Validate New Pass != Current Temp Pass]
[Validate New Pass == Confirmation Pass]
               │
               ▼
[Update password = bcrypt(newPass)]
[Set must_change_password = 0]
[Increment session_epoch = session_epoch + 1]
[Emit Success Flash & Grant Full Mailbox Access]
```

### Key Technical Enforcement Gates:
1. **Public Endpoint Absence:** The repository contains no `forgot-password.php`, `reset.php`, or self-service recovery links.
2. **Session Revocation (`users.session_epoch`):** The moment the administrator resets a password in `admin/edit-user.php`, `updatePassword()` executes:
   ```sql
   UPDATE users
   SET password = ?, must_change_password = 1, session_epoch = session_epoch + 1, updated_at = NOW()
   WHERE id = ?
   ```
   This immediately invalidates any active browser sessions, stolen cookies, or lingering mobile logins.
3. **Forced First-Login Guard (`includes/auth.php:require_auth()`):**
   ```php
   if (!$allowPendingPasswordChange && !empty($_SESSION['must_change_password'])) {
       set_flash('warning', 'You must change your password before continuing.');
       header('Location: ' . app_base_url('public/change-password.php'));
       exit;
   }
   ```
4. **Complete API Lockdown (`includes/auth.php:require_api_password_change()`):**
   Every asynchronous endpoint (`api/theme.php`, `api/toggle_star.php`, `api/contacts.php`, `api/batch_actions.php`, `api/get_email.php`, `api/autosave_draft.php`) calls `require_api_password_change()`:
   ```php
   function require_api_password_change(): void
   {
       if (!empty($_SESSION['must_change_password'])) {
           http_response_code(403);
           echo json_encode(['success' => false, 'error' => 'You must change your password before continuing.']);
           exit;
       }
   }
   ```
   An authenticated user holding a temporary password cannot read emails, autosave drafts, or toggle flags via background fetch calls.
5. **Auditing without Credential Exposure:**
   `log_activity()` in `admin/edit-user.php` records:
   `Admin reset password for user ID {targetId}`. The plaintext temporary password is never logged to disk, database, or error streams.

---

## 3. Active Directory vs. Local Database Isolation Rules

In an enterprise Windows Server environment, Couriva operates in a hybrid directory model:

| Identity Class | Authority | Password Reset Procedure | Local Password Allowed? |
|---|---|---|---|
| **Local Account** (`auth_source = 'local'`) | Couriva MariaDB | Administrator resets in `admin/edit-user.php`. User must change upon first sign-in. | Yes (bcrypt hash in `users.password`) |
| **Active Directory Account** (`auth_source = 'ldap'`) | Windows Server Domain Controller | Domain Administrator resets in Active Directory Users & Computers (ADUC) or PowerShell `Set-ADAccountPassword`. | **STRICTLY NO.** Couriva local reset is blocked. |

### Rule: Active Directory Primacy
- If an account is an Active Directory user, Couriva's `admin/edit-user.php` disables the local reset password form and displays a directive to use the Windows Domain Controller.
- If an AD account is locked, disabled, or expired in Active Directory, `AuthService` will fail the domain bind and **never** fall back to local MariaDB authentication.

---

## 4. ANSI X3.5 / ISO 5807 Standard Flowchart Specification

The authentication and recovery workflow conforms to the **ANSI X3.5-1970** and **ISO 5807:1985** international standards for data processing flowcharts.

### Flowchart Purity Standard:
- **Zero Diagram Notes:** Per standard rules, diagram canvases must remain completely free of conversational notes, sticky labels, review callouts, or watermarks. All commentary belongs in the document text.
- **Standardized ANSI/ISO Symbols Utilized:**
  - **Terminal / Terminator (Oval / Rounded Pill):** Start, End, and Entry Points.
  - **Process (Rectangle):** Internal operations, database updates, credential hashing.
  - **Decision (Rhombus / Diamond):** Conditional checks with explicit, labeled exit branches (`Yes` / `No`).
  - **Input / Output (Parallelogram):** User credential entry, form submission, HTTP redirect response.
  - **Data Storage (Cylinder):** Active Directory Domain Controller and MariaDB `webmail` database.
  - **Flowlines:** Solid orthogonal directional arrows indicating sequence.

### Diagram Layout Architecture:
The diagram file `docs/flowcharts/login_flowchart.drawio` generated by `docs/flowcharts/generate_login_flowchart.py` implements the exact 41-node zero-defect structure:

```text
[Start: User Visits /public/login.php]
                  │
                  ▼
         / Enter Credentials /  (Input/Output)
                  │
                  ▼
         < CSRF Token Valid? >  (Decision)
             ├── No ──► / Return HTTP 403 / ──► [End]
             │
             └── Yes ──► < Domain / AD Account? >  (Decision)
                             │
                             ├── Yes ──► [Connect to LDAP Server] (Process)
                             │                 │
                             │                 ▼
                             │       < AD Bind Successful? >  (Decision)
                             │           ├── No ──► / Return "AD Credentials Rejected" / ──► [End]
                             │           └── Yes ──► [Sync Attributes to DB] (Process)
                             │                             │
                             │                             ▼
                             │               [session_regenerate_id(true)] (Process)
                             │                             │
                             │                             ▼
                             │                 [Open /public/inbox.php] ──► [End]
                             │
                             └── No ──► [Query MariaDB users Table] (Data Storage)
                                               │
                                               ▼
                                      < Status == 'active'? >  (Decision)
                                          ├── No ──► / Return Account Status Message / ──► [End]
                                          │
                                          └── Yes ──► < password_verify() OK? >  (Decision)
                                                          ├── No ──► / Return "Invalid email or password" / ──► [End]
                                                          │
                                                          └── Yes ──► [session_regenerate_id(true)] (Process)
                                                                            │
                                                                            ▼
                                                                < must_change_password == 1? >  (Decision)
                                                                    ├── Yes ──► / Redirect to /change-password.php /
                                                                    │                     │
                                                                    │                     ▼
                                                                    │            / Enter New Password /
                                                                    │                     │
                                                                    │                     ▼
                                                                    │           < Validate Complexity & Match >
                                                                    │               ├── No ──► / Show Error / ──► (Loop)
                                                                    │               └── Yes ──► [Update DB & Epoch]
                                                                    │                                 │
                                                                    │                                 ▼
                                                                    │                     [Open /public/inbox.php] ──► [End]
                                                                    │
                                                                    └── No ──► [Open /public/inbox.php] ──► [End]
```

---

## 5. Administrator Verification Protocol (SOP)

When an enterprise user contacts IT operations requesting a password reset, the administrator must adhere to the following Standard Operating Procedure (SOP):

1. **Identity Verification (Out-of-Band):**
   - The administrator must verify the user's identity via in-person presentation of company ID badge or callback to the user's registered corporate extension.
   - Password requests submitted via email or instant messaging without voice/in-person verification must be rejected.
2. **Account Authority Inspection:**
   - In `admin/users.php`, locate the user account.
   - Inspect the `auth_source` column:
     - If `ldap`, inform the user that their password must be reset via Active Directory / Windows Domain Services.
     - If `local`, click **Edit User**.
3. **Temporary Password Generation:**
   - In `admin/edit-user.php`, generate an ephemeral password satisfying enterprise complexity (minimum 10 characters, mixed case, numbers, symbols).
   - Enter the password and click **Reset Password**.
   - Couriva sets `must_change_password = 1` and increments `session_epoch`.
4. **Credential Issuance:**
   - Communicate the temporary password directly to the verified user through a secure out-of-band channel.
   - Instruct the user to sign in immediately and complete the mandatory password update.
