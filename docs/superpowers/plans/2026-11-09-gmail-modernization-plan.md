# Couriva Web Mail Modernization — Gmail Experience Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Transform Couriva into a production-grade webmail platform with modern Material Design aesthetics, floating docked compose, conversation threading, inbox density modes, and Gmail-parity productivity features.

**Architecture:** Build directly on Couriva's proven PHP 8.x/MariaDB architecture, local CSS/JS asset pipeline, and security hardening. Implement a Google Material 3 visual shell, mini-rail collapsible sidebar, centered pill search with advanced filter popover, floating docked multi-window compose, threaded conversation view, and bottom-left Undo toasts.

**Tech Stack:** PHP 8.x, MariaDB/MySQL (PDO), Vanilla JavaScript (ES6+), Bootstrap 5.3, Tailwind CSS (locally compiled), Bootstrap Icons (locally bundled).

**Spec:** `.hermes/plans/2026-11-09-gmail-modernization-plan.md`

## Global Constraints

- **PHP 8.x Compatibility:** Zero syntax or runtime errors across all `.php` files (`php -l` passes).
- **Offline Parity & Security:** No external CDN scripts/fonts required; all fonts, icons, and stylesheets are locally bundled.
- **Fail-Closed Security:** Preserve existing CSRF protection, password masking with eye-toggle, API authentication checks, and attachment validation.
- **Test Integrity:** All existing test suites in `npm test` must continue to pass (100% pass rate).
- **Non-Git Workspace:** Non-git environment (`/opt/lampp/htdocs/Web_Mail_System`); verification relies on automated regression suites and syntax validation.

## Review Focus

1. **Floating Compose Auto-Save Race Conditions:** Closing or minimizing the composer while a draft network request is in-flight must not lose draft ID or corrupt recipient lists.
2. **Mini-Rail Sidebar Layout Shift:** Collapsing sidebar to 72px mini-rail must dynamically recalculate reading pane and email list container widths without horizontal scrollbars.
3. **Conversation Threading Hierarchy:** Threads grouped by parent email or subject must handle orphan replies, multi-recipient branches, and draft replies safely.
4. **Search Filter Popover Escaping & Boundary:** Filter popover must close cleanly on Escape or outside click without triggering unintended search submissions.
5. **Undo Action Timer Expiration:** Undo send / Undo delete toasts must execute reliably within a 5-second window and restore visual list state if triggered.

---

### Task 1: Google Material Shell & Top Navigation

**Files:**
- Modify: `includes/header.php:120-290`
- Modify: `assets/css/style.css:120-300`
- Modify: `assets/js/app.js:60-150`
- Test: `tests/gmail_modernization_regression_test.php`

**Interfaces:**
- Consumes: `$_SESSION['user_full_name']`, `$_SESSION['user_email']`, `$_SESSION['user_theme']`, `csrf_token()`
- Produces: Mini-rail toggle state (`body.sidebar-mini`), centered pill search bar `#gmailSearchBar`, advanced search popover `#searchFilterDropdown`, quick settings drawer `#quickSettingsOffcanvas`, user profile card `#userProfileMenu`.

- [ ] **Step 1: Write the failing test for Material shell markup and toggle contracts**

Create `tests/gmail_modernization_regression_test.php` checking for `#gmailSearchBar`, `#searchFilterDropdown`, `#quickSettingsOffcanvas`, and `#userProfileCard`.

Run: `php tests/gmail_modernization_regression_test.php`
Expected: FAIL with missing elements.

- [ ] **Step 2: Implement centered pill search bar and filter popover in `includes/header.php`**

Replace the current right-aligned search with a Google-style centered pill:
- Magnifying glass icon on the left (`bi-search`).
- Search input `placeholder="Search in mail"`.
- Sliders filter icon on the right (`bi-sliders2`) triggering `#searchFilterDropdown`.
- Floating filter card containing From, To, Subject, Has attachment, Date range, and blue "Search" CTA.

- [ ] **Step 3: Implement Quick Settings Drawer & Google-Style Profile Card in `includes/header.php`**

- Quick Settings gear icon triggering offcanvas drawer with:
  - Density radio buttons: Default, Comfortable, Compact.
  - Theme picker: Light, Dark, System.
  - Reading pane position: No split, Right of inbox, Below inbox.
- Profile avatar popover card showing large user initial, full name, email, "Manage Account" link, and "Sign out" button.

- [ ] **Step 4: Implement Mini-Rail Toggle in `assets/js/app.js` and `assets/css/style.css`**

- Hamburger icon collapses sidebar from 256px to 72px (`body.sidebar-mini`).
- In mini mode, text labels hide, icons center, and floating tooltips appear on hover.
- Store preference in `localStorage.getItem('couriva_sidebar_mini')`.

- [ ] **Step 5: Run tests and verify**

Run: `php tests/gmail_modernization_regression_test.php`
Expected: PASS Task 1 assertions.

---

### Task 2: Left Sidebar Navigation & Storage Quota Meter

**Files:**
- Modify: `includes/header.php:290-380`
- Modify: `assets/css/style.css:300-450`
- Modify: `assets/js/app.js:150-220`
- Test: `tests/gmail_modernization_regression_test.php`

**Interfaces:**
- Consumes: Folder badge counts (`$unreadInboxCount`, `$draftCount`, `$spamCount`, `$snoozedCount`), `$organizationLabels`, `$userStorageBytes`, `$storageQuotaBytes`.
- Produces: Floating pill Compose button `#btnOpenCompose`, pill folder item states, collapsible `#moreFoldersCollapse`, storage meter `#storageUsageMeter`.

- [ ] **Step 1: Write the failing test for sidebar navigation and storage meter**

Add tests asserting `#btnOpenCompose` exists with `.btn-compose-fab`, `.nav-pill-item` styling, `#moreFoldersCollapse` accordion, and `#storageUsageMeter`.

Run: `php tests/gmail_modernization_regression_test.php`
Expected: FAIL.

- [ ] **Step 2: Refactor Compose button into Material FAB Pill**

In `includes/header.php`:
- Render `#btnOpenCompose` with `.btn-compose-fab`: white/elevated pill in light mode, dark elevated pill in dark mode, colored pen icon (`bi-pencil-fill`), and text "Compose".
- In mini-rail mode, collapse smoothly to 56x56 round FAB with pen icon only.

- [ ] **Step 3: Implement Pill Active States & Collapsible "More/Less" Folders**

In `includes/header.php`:
- Nav items use rounded-r-full pill geometry (`.nav-pill-item`) with light-blue tint active state (`#c2e7ff` / `#004a77` dark).
- Primary folders: Inbox, Starred, Sent, Drafts.
- Collapsible section under `#moreFoldersCollapse` toggled by "More / Less" link: Snoozed, Scheduled, Archive, Spam, Trash.

- [ ] **Step 4: Implement Storage Usage Progress Meter**

In `includes/header.php`:
- Render `#storageUsageMeter` at bottom of sidebar showing clean storage bar, used space (e.g. `12.4 MB of 500 MB used`), and percentage bar.

- [ ] **Step 5: Run tests and verify**

Run: `php tests/gmail_modernization_regression_test.php`
Expected: PASS Task 2 assertions.

---

### Task 3: Inbox Density Modes, Category Tabs & Attachment Chips

**Files:**
- Modify: `public/inbox.php:80-380`
- Modify: `assets/css/style.css:450-650`
- Modify: `assets/js/app.js:800-980`
- Test: `tests/gmail_modernization_regression_test.php`

**Interfaces:**
- Consumes: `$emails`, `$mail['attachment_count']`, `$mail['attachments']`, density preference from `localStorage`.
- Produces: `#inboxDensityWrapper` (`density-default`, `density-comfortable`, `density-compact`), `.attachment-chip`, `#selectAllDropdown`, hover quick actions.

- [ ] **Step 1: Write the failing test for density modes, attachment chips, and select-all dropdown**

Add tests checking for density wrapper attributes, attachment pill markup `.attachment-chip`, and select-all dropdown items (All, None, Read, Unread, Starred).

Run: `php tests/gmail_modernization_regression_test.php`
Expected: FAIL.

- [ ] **Step 2: Implement Select-All Dropdown Menu in Toolbar**

In `public/inbox.php`:
- Add dropdown arrow next to select-all checkbox `#selectAllEmails` with options:
  - All, None, Read, Unread, Starred, Unstarred.
- Wire selection triggers in `assets/js/app.js`.

- [ ] **Step 3: Implement Attachment Pill Chips in Email Rows**

In `public/inbox.php`:
- Query attachments for listed emails in `MailService::searchEmails()`.
- For emails with attachments, render clickable pill chips under the subject line in Default density mode:
  - Icon based on MIME type (PDF, Image, Word, Archive).
  - Truncated filename (e.g. `Quarterly_Report.pdf`).
  - Direct download/preview link.

- [ ] **Step 4: Implement 3 Density Modes in CSS & JS**

In `assets/css/style.css` and `assets/js/app.js`:
- `.density-default`: 48px row height, shows attachment chips, relaxed padding.
- `.density-comfortable`: 40px row height, hides chips (shows paperclip icon only).
- `.density-compact`: 32px row height, tight padding for maximum visible rows.
- Listen to Quick Settings drawer density changes and synchronize `localStorage.getItem('couriva_density')`.

- [ ] **Step 5: Refine Row Hover Quick Actions**

In `public/inbox.php` and `assets/css/style.css`:
- On hover, date text smoothly fades and is replaced by 4 icon buttons: Archive, Delete, Mark Read/Unread, Snooze.

- [ ] **Step 6: Run tests and verify**

Run: `php tests/gmail_modernization_regression_test.php`
Expected: PASS Task 3 assertions.

---

### Task 4: Floating Docked Multi-Window Compose Experience

**Files:**
- Create: `public/api/compose_modal.php`
- Modify: `includes/footer.php:1-50`
- Modify: `assets/css/style.css:650-850`
- Modify: `assets/js/app.js:220-480,720-800`
- Test: `tests/gmail_modernization_regression_test.php`

**Interfaces:**
- Consumes: `public/api/autosave_draft.php`, `public/api/contacts.php`, `public/compose.php` handlers.
- Produces: Floating docked composer `#dockedComposeContainer`, minimize/maximize/close window controls, rich text bottom toolbar.

- [ ] **Step 1: Write the failing test for docked compose container and actions**

Add tests for `#dockedComposeContainer`, `#dockedComposeHeader`, minimize button `.compose-minimize-btn`, maximize button `.compose-maximize-btn`, and floating dock bar.

Run: `php tests/gmail_modernization_regression_test.php`
Expected: FAIL.

- [ ] **Step 2: Create Floating Docked Compose Shell in `includes/footer.php`**

Render a persistent docked compose host container:
- Fixed to bottom-right (`bottom: 0; right: 24px; z-index: 1055; width: 560px; height: 520px; border-radius: 12px 12px 0 0`).
- Header: Window title ("New Message" or Draft subject), Minimize button, Fullscreen toggle button, Close button.
- Body: Recipient chips (To, Cc, Bcc), Subject input, Rich text editor area, Attachment dropzone list.
- Footer: Blue Send button with split dropdown for "Schedule send", formatting icon, paperclip attach icon, trash draft icon, and live "Saved" status indicator.

- [ ] **Step 3: Implement Docked Compose State Engine in `assets/js/app.js`**

- Intercept "Compose" button clicks across all pages to open the floating modal without navigating away.
- Window states:
  - `normal`: 560x520px bottom-right.
  - `minimized`: Collapsed to 240px bottom pill dock showing subject line and close button.
  - `fullscreen`: Centered modal dialog 80vw x 85vh with backdrop.
- Support opening drafts from Inbox / Drafts folder directly into docked compose.
- Retain real-time draft autosave with `public/api/autosave_draft.php`.

- [ ] **Step 4: Run tests and verify**

Run: `php tests/gmail_modernization_regression_test.php`
Expected: PASS Task 4 assertions.

---

### Task 5: Conversation Threading & Inline Message Reader

**Files:**
- Modify: `app/Services/MailService.php:80-250`
- Modify: `public/read.php:40-350`
- Modify: `assets/css/style.css:850-1050`
- Modify: `assets/js/app.js:1140-1360`
- Test: `tests/gmail_modernization_regression_test.php`

**Interfaces:**
- Consumes: `emails.parent_email_id`, `emails.message_id`, `emails.subject`.
- Produces: `MailService::getConversationThread($emailId, $userId)`, threaded message stack `.thread-message-card`, inline quick reply box `#inlineReplyContainer`.

- [ ] **Step 1: Write the failing test for conversation thread grouping**

Add tests verifying `MailService::getConversationThread()` returns chronological messages grouped by `parent_email_id` or normalized thread subject.

Run: `php tests/gmail_modernization_regression_test.php`
Expected: FAIL.

- [ ] **Step 2: Implement Thread Fetching in `app/Services/MailService.php`**

Add `getConversationThread(int $emailId, int $userId): array`:
- Find root email ID (`parent_email_id` chain or matching subject key).
- Fetch all visible messages in thread in chronological order.
- Mark all unread messages in the thread as read.

- [ ] **Step 3: Build Gmail Accordion Stack in `public/read.php` and Split Reading Pane**

- Render conversation subject as main header with label pills and action toolbar.
- Older messages render as collapsed single-line cards: Sender name, snippet preview, timestamp. Clicking card expands it.
- Latest message expanded by default showing full headers, security status, and formatted body.
- Attachment preview cards with image thumbnails and download buttons.

- [ ] **Step 4: Implement Inline Quick Reply / Forward Box**

At the bottom of the conversation thread:
- Collapsed placeholder: "Reply" and "Forward" pill buttons.
- On click, smoothly expands in-place into rich reply editor with sender already addressed and Send button.

- [ ] **Step 5: Run tests and verify**

Run: `php tests/gmail_modernization_regression_test.php`
Expected: PASS Task 5 assertions.

---

### Task 6: Gmail Keyboard Shortcuts, SnackBar Undo & Shortcut Help

**Files:**
- Modify: `includes/footer.php:50-100`
- Modify: `assets/css/style.css:1050-1200`
- Modify: `assets/js/app.js:1400-1590`
- Test: `tests/gmail_modernization_regression_test.php`

**Interfaces:**
- Consumes: Batch action endpoints, delete/trash/archive handlers.
- Produces: Floating undo snackbar `#undoToastContainer`, keyboard shortcut listener, help modal `#shortcutHelpModal`.

- [ ] **Step 1: Write the failing test for Undo SnackBar and Keyboard Shortcuts**

Add tests for `#undoToastContainer`, shortcut key mappings (`c`, `e`, `#`, `s`, `j`, `k`, `/`, `?`), and `#shortcutHelpModal`.

Run: `php tests/gmail_modernization_regression_test.php`
Expected: FAIL.

- [ ] **Step 2: Implement Bottom-Left Undo SnackBar in `includes/footer.php` and `assets/js/app.js`**

- Dark rounded pill toast at bottom-left: "Conversation moved to Trash. [Undo]" or "Message sent. [Undo]".
- Clicking "Undo" sends rollback request to backend and restores list row immediately.
- Auto-dismisses after 6 seconds.

- [ ] **Step 3: Implement Gmail Keyboard Shortcuts Engine**

In `assets/js/app.js`:
- Listen for keyboard navigation when focus is not inside an `input`, `textarea`, or contenteditable:
  - `c`: Open floating compose.
  - `j` / `k`: Move highlight to next/previous email row in list.
  - `Enter` / `o`: Open currently highlighted email.
  - `x`: Toggle selection checkbox of highlighted email.
  - `e`: Archive selected emails.
  - `#`: Move selected emails to Trash.
  - `s`: Toggle star on highlighted email.
  - `/`: Focus search bar.
  - `?`: Open keyboard shortcuts cheat sheet modal.

- [ ] **Step 4: Build Keyboard Shortcuts Cheat Sheet Modal**

In `includes/footer.php`:
- Accessible modal displaying grouped shortcuts (Navigation, Actions, Selection, Compose).

- [ ] **Step 5: Run tests and verify**

Run: `php tests/gmail_modernization_regression_test.php`
Expected: PASS Task 6 assertions.

---

### Task 7: CSS Build & Complete Suite Verification

**Files:**
- Modify: `assets/css/tailwind.css`
- Run: `npm run build:css`
- Run: `npm test`
- Run: `php tests/gmail_modernization_regression_test.php`

- [ ] **Step 1: Compile CSS assets**

Run: `npm run build:css`
Expected: `assets/css/tailwind.generated.css` generated cleanly.

- [ ] **Step 2: Run all regression suites**

Run: `npm test`
Expected: 100% PASS across all 5 core suites.

- [ ] **Step 3: Run comprehensive modernization regression suite**

Run: `php tests/gmail_modernization_regression_test.php`
Expected: 100% PASS across all tasks.
