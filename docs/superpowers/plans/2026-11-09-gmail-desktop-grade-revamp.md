# Couriva Web Mail — Desktop-Grade Gmail Revamp Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Transform Couriva Web Mail from a generic Bootstrap admin template into a desktop-grade Gmail web client featuring a universal action bar, hairline email rows with hover quick-actions, category tabs (Primary/Updates/Promotions), and a polished Material 3 header.

**Architecture:** Replace the legacy card-based inbox header and detached pill-shaped email rows with a single-tier flat universal action bar and continuous hairline-divided table-free flex rows. Implement CSS hover swaps for instant email actions (Archive, Trash, Read/Unread, Snooze). Retain existing PHP 8.x/MariaDB backend architecture and local CSS/JS pipeline.

**Tech Stack:** PHP 8.x, Vanilla JS (ES6+), Bootstrap 5.3 + Tailwind utility layer, Bootstrap Icons (local), MariaDB 10.4.

**Spec:** Local Couriva architecture (`includes/header.php`, `public/inbox.php`, `assets/css/style.css`, `assets/js/app.js`).

## Global Constraints
- Absolute file paths only.
- Native asset pipeline only; no external CDNs or unverified external dependencies.
- Zero regressions in existing test suites: `npm test` must pass after every task.
- Dark, light, and system theme compatibility must be maintained across all redesigned components.
- Sensitive credentials must never be written to plaintext or console output.

## Review Focus
1. Email row checkbox clicks must not accidentally trigger navigation to `read.php`.
2. Action bar contextual buttons must appear immediately when 1+ checkboxes are checked and disappear when all are deselected.
3. Hover action buttons on email rows must stop event propagation so clicking "Archive" or "Delete" does not open the email.
4. Active category tabs must preserve query pagination and search parameters.
5. All icon buttons must provide accessible `aria-label` and `title` attributes.

---

### Task 1: Universal Gmail Action Bar & Contextual Selection Toolbar

**Files:**
- Modify: `public/inbox.php:120-190`
- Modify: `assets/css/style.css:320-380`
- Modify: `assets/js/app.js:280-360`
- Test: `tests/gmail_revamp_regression_test.php`

**Interfaces:**
- Consumes: Existing email selection checkboxes (`.email-checkbox`), batch actions endpoint (`public/api/batch_actions.php`).
- Produces: Universal action bar (`#gmailActionBar`) with master split select (`#selectAllCheckbox`, `#selectAllDropdownMenu`), contextual action buttons (`.contextual-actions`), and right-aligned pagination indicator.

- [ ] **Step 1: Write failing test in `tests/gmail_revamp_regression_test.php`**
Assert that `#gmailActionBar` exists, contains `.contextual-actions`, `.action-bar-btn`, and does not render the legacy card header with "Showing X of Y messages".

- [ ] **Step 2: Run test to verify it fails**
Run: `php tests/gmail_revamp_regression_test.php`
Expected: FAIL

- [ ] **Step 3: Implement universal action bar in `public/inbox.php` and `assets/css/style.css`**
Remove the legacy card header (`.card-header`) with accordion filters. Render `#gmailActionBar` with:
- Master select split dropdown (All, None, Read, Unread, Starred).
- Refresh button.
- Contextual action buttons (Archive, Spam, Delete, Mark Read, Mark Unread, Move, Label) hidden by default and shown when `checkedCount > 0`.
- Pagination counter (`1-25 of 148`) with prev/next buttons.

- [ ] **Step 4: Update selection listener in `assets/js/app.js`**
Update checkbox event handlers to toggle visibility of `.contextual-actions` when 1+ emails are checked.

- [ ] **Step 5: Run tests to verify they pass**
Run: `php tests/gmail_revamp_regression_test.php && npm test`
Expected: PASS

---

### Task 2: Hairline-Divided Email Rows with Desktop Hover Quick-Actions

**Files:**
- Modify: `public/inbox.php:190-280`
- Modify: `assets/css/style.css:420-560`
- Modify: `assets/js/app.js:360-440`
- Test: `tests/gmail_revamp_regression_test.php`

**Interfaces:**
- Consumes: Email array from `MailService::getEmailsByFolder` with pre-fetched attachments.
- Produces: Continuous `.mail-row` elements with fixed sender width, inline subject/snippet, and `.row-hover-actions` that fade in on hover.

- [ ] **Step 1: Write failing test in `tests/gmail_revamp_regression_test.php`**
Assert that `.mail-row` exists, has `.row-hover-actions` containing Archive (`data-row-action="archive"`), Delete (`data-row-action="trash"`), Mark Read/Unread, and Snooze buttons.

- [ ] **Step 2: Run test to verify it fails**
Run: `php tests/gmail_revamp_regression_test.php`
Expected: FAIL

- [ ] **Step 3: Implement `.mail-row` structure in `public/inbox.php`**
Replace floating card wrappers with a continuous `.mail-list-table`:
- Hairline border bottom (`1px solid var(--border)`).
- Left: Checkbox + Star icon.
- Sender: 160px-180px fixed-width column with bold text for unread messages.
- Center: Subject in bold + inline separator `—` + muted text snippet.
- Right: Attachment paperclip, timestamp, and `.row-hover-actions` container.

- [ ] **Step 4: Add hover swap CSS and row action JS**
In `assets/css/style.css`:
- `.mail-row:hover .row-date { display: none; }`
- `.mail-row:hover .row-hover-actions { display: flex; }`
- Add selection highlight (`background-color: var(--primary-soft)`).
In `assets/js/app.js`:
- Handle quick-action clicks with `e.stopPropagation()` and trigger corresponding API calls.

- [ ] **Step 5: Run tests to verify they pass**
Run: `php tests/gmail_revamp_regression_test.php && npm test`
Expected: PASS

---

### Task 3: Gmail Category Tabs (Primary, Updates, Promotions)

**Files:**
- Modify: `public/inbox.php:140-180`
- Modify: `app/Services/MailService.php:240-310`
- Modify: `assets/css/style.css:560-640`
- Test: `tests/gmail_revamp_regression_test.php`

**Interfaces:**
- Consumes: Current folder and active category from `$_GET['category']`.
- Produces: `.gmail-category-tabs` with active indicator bar, icon, title, and unread badge.

- [ ] **Step 1: Write failing test in `tests/gmail_revamp_regression_test.php`**
Assert that `.gmail-category-tabs` contains `#tabPrimary`, `#tabUpdates`, `#tabPromotions`.

- [ ] **Step 2: Run test to verify it fails**
Run: `php tests/gmail_revamp_regression_test.php`
Expected: FAIL

- [ ] **Step 3: Implement category filter in `app/Services/MailService.php`**
Support filtering emails by category (Primary, Updates, Promotions) based on sender domain/type or default to Primary.

- [ ] **Step 4: Implement Category Tab Bar in `public/inbox.php` and `assets/css/style.css`**
Render 3 tabs under the action bar with Material 3 styling:
- Primary (`bi-inbox-fill`)
- Updates (`bi-info-circle-fill`)
- Promotions (`bi-tag-fill`)
Active tab displays an accent bottom highlight bar (`border-bottom: 3px solid var(--primary)`).

- [ ] **Step 5: Run tests to verify they pass**
Run: `php tests/gmail_revamp_regression_test.php && npm test`
Expected: PASS

---

### Task 4: Material 3 Header Polish & M3 Design Tokens

**Files:**
- Modify: `includes/header.php:190-275`
- Modify: `assets/css/style.css:20-120`
- Test: `tests/gmail_revamp_regression_test.php`

**Interfaces:**
- Consumes: Authenticated user session (`$userInitials`, `$isAdmin`).
- Produces: Streamlined top header with 32px circular avatar button, discrete Google-style admin launcher icon, and Material 3 color variables.

- [ ] **Step 1: Write failing test in `tests/gmail_revamp_regression_test.php`**
Assert that user avatar button is a clean circular button without truncated text label, and admin launcher uses a clean icon dropdown.

- [ ] **Step 2: Run test to verify it fails**
Run: `php tests/gmail_revamp_regression_test.php`
Expected: FAIL

- [ ] **Step 3: Refine header markup in `includes/header.php`**
Replace the wide pill button (`System Administ...`) with an elegant 34px circular avatar button (`.user-avatar-btn`).
Replace the speedometer admin button with a clean 9-dot grid icon (`bi-grid-3x3-gap-fill`) or shield icon.

- [ ] **Step 4: Update Material 3 design tokens in `assets/css/style.css`**
Set Google Material 3 tokens for surfaces, containers, and borders in both light and dark mode. Rebuild Tailwind CSS bundle.

- [ ] **Step 5: Run full test suite & CSS build**
Run: `npm run build:css && npm test`
Expected: All tests PASS with 0 errors.
