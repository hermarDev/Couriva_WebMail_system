# Couriva WebMail — E2E Test Report

- **Target:** http://172.20.10.2/public/login.php (couriva.com does not resolve from operator box 172.20.10.3)
- **Date:** 2026-11-26 (site Date header reported 2026-11-25 23:xx UTC)
- **Tool:** `agent-browser` 0.44.0, three persistent sessions `e2e-user-a`, `e2e-user-b`, `e2e-admin`
- **Accounts used for login:** seno@couriva.com, olivar@couriva.com, admin@couriva.com. No other account was logged into. No account disabled, deleted, promoted, or edited except throwaway-scoped test data below.
- **Evidence:** screenshots in `/tmp/opencode/couriva-e2e/shots/` (28 PNG), raw header dumps `/tmp/opencode/couriva-e2e/headers.txt` (HEAD) and `/tmp/opencode/couriva-e2e/headers-get.txt` (GET), saved B state `/tmp/opencode/couriva-e2e/state-user-b.json`.

## Summary

| Category | Count |
|---|---|
| Checks passed | 33 |
| Checks failed | 1 |
| Deviations (informational) | 2 |
| Not tested (blocked by design / environment) | 3 |

**Top bug:** `BUG-001` — duplicate security headers including two conflicting `Content-Security-Policy` values on every response (violates TC-IIS-07 "single CSP, zero duplicate headers"). No P0 functional failure found in auth, mail, access control, or admin flows.

## Bug findings

### BUG-001 — Duplicate security headers and two conflicting CSPs

- **Severity:** P1
- **Repro steps:**
  1. From operator box run `curl -sSI http://172.20.10.2/public/login.php` (or `curl -sS -D - -o /dev/null ...` for GET).
  2. Count repeated response headers.
- **Expected:** one `Content-Security-Policy`, one each of `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy` (acceptance spec TC-IIS-07: "single CSP. Zero duplicate headers").
- **Actual:** five headers emitted twice. First CSP contains a per-request `'nonce-...'` and allows `cdn.jsdelivr.net`; second CSP has no nonce and slightly different directives. Counts on both HEAD and GET: `x-content-type-options: 2`, `x-frame-options: 2`, `referrer-policy: 2`, `permissions-policy: 2`, `content-security-policy: 2`.
- **Impact:** duplicate/conflicting CSPs are undefined-behavior territory across browsers; a nonce-bearing policy and a nonce-free policy can disagree, weakening the intended script restriction. Duplicate framing/referrer headers also violate the deployment acceptance gate.
- **Evidence:** `/tmp/opencode/couriva-e2e/headers.txt`, `/tmp/opencode/couriva-e2e/headers-get.txt`, screenshot `/tmp/opencode/couriva-e2e/shots/01-login-page.png`.
- **Likely cause (source inspected, not applied):** header set twice — once in PHP and once in IIS `web.config`/`.htaccess`. Needs one authoritative layer.

### DEV-001 — Recovery-probe paths return 302 instead of 404

- **Severity:** P3 / deviation, not a security exposure
- **Repro:** `curl -sS -o /dev/null -w "%{http_code}" http://172.20.10.2/forgot-password.php` (also `/reset-password.php`, `/recover.php`).
- **Expected (TC-RECOV-01):** HTTP 404 for all three.
- **Actual:** HTTP 302 → `/public/login.php`. Verified a catch-all rule: random paths such as `/totally-random-xyz.php` also 302 → login. No public self-service reset endpoint exists, so the security goal is met; only the status code differs.
- **Evidence:** curl output captured in this run's command log; screenshot `/tmp/opencode/couriva-e2e/shots/01-login-page.png` (login landing).

### DEV-002 — `admin/registrations.php` GET returns 200 rather than 410

- **Severity:** P3 / deviation
- **Repro (authenticated as admin):** `fetch('/admin/registrations.php')`.
- **Expected:** retired endpoint → 410 (system guide Part 8/9).
- **Actual:** GET 200 showing a "registration retired" notice; POST 410 and no approve/reject writes. Retirement is functionally enforced on writes.
- **Evidence:** admin session probe output recorded in this run; screenshot `/tmp/opencode/couriva-e2e/shots/06-admin-users.png` (same admin area).

## Passed checks

| ID | Check | Expected | Actual | Evidence |
|---|---|---|---|---|
| T01 | Login page renders, CSRF token + hidden field, no registration link | 200, form | Pass | `shots/01-login-page.png` |
| T02 | Sensitive resource isolation | 404 for `/config/database.php`, `/storage/attachments/`, `/database/schema.sql`, `/.env`, `/docs/flowcharts/audit_report.txt` | All 404 | curl log |
| T03 | `GET /public/register.php` | 410 | 410 | browser fetch |
| T04 | `POST /public/register.php` | 410 | 410 | browser fetch |
| T05 | `POST /admin/registrations.php` (admin) | 410 | 410 | browser fetch |
| T06 | `POST /admin/create-user.php` | 403, no user created | 403; account count stayed 22, no `probe-delete-me` user | browser fetch + admin dashboard |
| T07 | `POST /admin/users.php` `action=create_user` | 403, no user created | 403; no user created | browser fetch |
| T08 | Unauthenticated protected URLs | redirect to login | `/public/inbox.php`, `/admin/*` → login | browser fetch |
| T09 | Bad password | rejected | "Invalid email or password." | browser fetch |
| T10 | Login without CSRF | rejected | "Session security token expired. Please try again." | browser fetch |
| T11 | Login with forged CSRF | rejected | same message | browser fetch |
| T12 | Admin login | no forced password change | landed on inbox; no STOP needed | `shots/04-admin-landing.png` |
| T13 | Non-admin (User A) hitting 6 admin URLs | 403 | 403 on all, redirect to inbox | browser fetch |
| T14 | API CSRF (missing + forged) | 403 JSON | `{"success":false,"error":"Invalid CSRF token"}` | browser fetch |
| T15 | A → B send | delivered | A Sent 1/1, B Inbox 1/1 | `shots/10-userB-inbox-received.png` |
| T16 | B replies to A | delivered | A Inbox shows `Re: E2E_A_TO_B_070549` | browser snapshot |
| T17 | B forwards to augusto@sent | in B Sent | `Fwd: E2E_A_TO_B_070549`, To augusto@couriva.com | browser snapshot |
| T18 | Upload `e2e-payload.php` | rejected | alert: prohibited type, file not staged | `shots/12-php-upload-attempt.png` |
| T19 | A → augusto with `.txt` attachment | staged and sent | "1 file(s) attached (51 B)", sent message id 55 | `shots/13-attach-staged.png`, `shots/14-attach-private-read.png` |
| T20 | B (non-party) downloads A's attachment id 7 | 403 | 403 "Access denied to attachment." | `shots/15-cross-user-denied.png` |
| T21 | A downloads own attachment id 7 | 200 | `text/plain`, 51 bytes | browser fetch |
| T22 | A → B attachment; B downloads id 10 | 200 | correct file bytes returned | `shots/16-userB-attachment-read.png` |
| T23 | Admin dashboard | renders | 22 accounts, 21 messages, attachment stats | `shots/05-admin-dashboard.png` |
| T24 | Admin user governance | renders, roles/status shown | 22 rows, protected admins marked | `shots/06-admin-users.png` |
| T25 | Admin system logs | renders | audit log page loaded | `shots/07-admin-logs.png` |
| T26 | Draft save + reopen | persisted and prefilled | draft 59 reopened with To/Subject/Body intact | `shots/19-drafts.png` |
| T27 | Report spam | moved to Spam with `[SPAM]` prefix | B Spam shows `[SPAM] E2E_A_TO_B_070549` | `shots/21-spam.png`, `shots/21a-report-spam-modal.png` |
| T28 | Search | finds matching mail | `q=Reply body` → 1 result; `q=E2E_ATTACH` → 0 (inbox-scoped) | `shots/20-search-results.png` |
| T29 | Add contact + autocomplete | saved and suggested | "E2E Augusto Contact" saved; To typeahead suggested it | `shots/23-contacts.png` |
| T30 | Notifications | lists unread mail | "2 unread · 2 total" | `shots/22-notifications.png` |
| T31 | Settings + theme | renders, theme toggles | `data-bs-theme` dark then restored light | `shots/24-settings.png`, `shots/25-dark-theme.png` |
| T32 | Mobile viewport 390×844 | usable inbox + compose | hamburger nav, FAB compose, controls reachable | `shots/26-mobile-inbox.png`, `shots/27-mobile-compose.png` |
| T33 | Logout invalidates session | protected route redirects | after `/public/logout.php`, inbox → login; restoring saved cookie still → login | browser run log |

## Not tested / blocked

- **N01 — Forced password-change gate (P0/P1).** Admin login did **not** force a change, so no STOP was needed. To exercise the gate, an admin must reset a **local, non-protected** throwaway user. Inspection of `/admin/edit-user.php?id=12` (`test.test@couriva.com`) and IDs 5,6,7,13,14,15 showed all non-admin users are `auth_source=ldap`; the web UI refuses resets for AD users ("Passwords must be reset in the Windows Domain Controller"). The only local users (ID 3 `admin@couriva.com`, ID 29 `admin.backup@couriva.com`) are protected admins. Therefore no eligible throwaway exists for this test. **Needed to complete:** one local non-admin throwaway account (created via `bin/create-admin.php` equivalent / CLI on the server, or an existing local non-admin), plus its temporary password. No user was modified to force this state.
- **N02 — Prior-session invalidation after admin password reset (P0).** Same prerequisite as N01.
- **N03 — AD-specific behaviors** (out-of-OU rejection, lockout, auto-provision GUID): requires Domain Controller control not available from this box.

## Test data left in place (per instruction)

- Sent: A→B `E2E_A_TO_B_070549` (message id 49), A→augusto `E2E_ATTACH_PRIVATE_070808` (id 55, attachment id 7), A→B `E2E_ATTACH_TO_B_070841` (id 58, attachment id 10), B→A reply `E2E_B_REPLY_070620`, B→augusto forward `E2E_B_FWD_070635`.
- Draft: A draft id 59 `E2E_DRAFT_071015`.
- Spam: B report of message id 49.
- Contact: A contact "E2E Augusto Contact" (augusto@couriva.com).
- Theme restored to light. No account disabled, deleted, promoted, or password-changed. `test.test@couriva.com` untouched.

## Screenshot index

`01-login-page.png`, `02-userA-inbox.png`, `03-userB-inbox.png`, `04-admin-landing.png`, `05-admin-dashboard.png`, `06-admin-users.png`, `07-admin-logs.png`, `08-compose.png`, `09-after-send.png`, `10-userB-inbox-received.png`, `11-userB-read-message.png`, `12-php-upload-attempt.png`, `13-attach-staged.png`, `14-attach-private-read.png`, `15-cross-user-denied.png`, `16-userB-attachment-read.png`, `17-admin-edit-throwaway.png`, `18-change-password-page.png`, `19-drafts.png`, `20-search-results.png`, `21a-report-spam-modal.png`, `21-spam.png`, `22-notifications.png`, `23-contacts.png`, `24-settings.png`, `25-dark-theme.png`, `26-mobile-inbox.png`, `27-mobile-compose.png`. All under `/tmp/opencode/couriva-e2e/shots/`.
