# Couriva Mail — UI/UX Implementation Plan
**Document Name:** `COURIVA_UI_UX_IMPLEMENTATION_PLAN.md`  
**Plan Tracking Path:** `.hermes/plans/2026-11-09_023000-couriva-ui-ux-implementation-plan.md`  
**Date:** 2026-11-09  
**Target:** Couriva PHP Webmail Application (`/opt/lampp/htdocs/Web_Mail_System`)  
**Status:** PROPOSED — PLANNING ONLY (No code modified)

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:subagent-driven-development` or `superpowers:executing-plans` to implement this plan task-by-task. Tasks use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Transform Couriva into a production-grade, desktop-class webmail client with a clean editorial SaaS design, rock-solid data integrity guards, unified theme synchronization, and WCAG 2.2 AA accessibility while preserving 100% of existing PHP routes, database schemas, and regression tests.

**Architecture:** Build directly upon the verified LAMPP/PHP 8 stack, Bootstrap 5.3.3 framework, local Bootstrap Icons 1.11.3, and Tailwind CSS utility layer. Fix confirmed layout defects at the root CSS/HTML structure (sidebar flex alignment, independent viewport scrolling, iframe typography inheritance, and progress bar clipping) while layering a unified toast/snackbar system, modal focus traps, and responsive breakpoints.

**Tech Stack:**
- **Server:** PHP 8.x, Apache (LAMPP), MariaDB/MySQL (`webmail` database)
- **Frontend Framework:** Bootstrap 5.3.3 (vanilla JS & CSS bundle)
- **Utility Layer:** Tailwind CSS 3.4.17 (`assets/css/tailwind.generated.css`)
- **Iconography:** Bootstrap Icons 1.11.3 (`assets/css/bootstrap-icons.css` + SVG fallback)
- **Client Scripting:** Native ES6+ Vanilla JavaScript (`assets/js/app.js`, zero heavy JS runtime)
- **Test Automation:** PHP CLI regression suites (`tests/ui_ux_regression.php`, `tests/gmail_modernization_regression_test.php`, `tests/gmail_revamp_regression_test.php`, `tests/p1_regression_test.php`)

**Spec:** `/home/hrmr/Downloads/COURIVA_UI_UX_AUDIT_FOR_HERMES.md`

---

## Global Constraints

1. **Stack Reuse:** Strictly reuse Bootstrap 5.3.3, Tailwind utilities, Bootstrap Icons, and existing PHP controllers. Do not install new npm UI frameworks (no React/Vue migration, no foreign theme packages).
2. **Backend Preservation:** Preserve all existing PHP endpoints (`public/*.php`, `public/api/*.php`, `admin/*.php`), SQL schemas, session auth, CSRF tokens, and mail delivery logic.
3. **Regression Safety:** All 7 existing test suites in `npm test` (including 50+ assertion keys in `gmail_modernization_regression_test.php` and `gmail_revamp_regression_test.php`) must continue to pass 100% on every increment. Exact element IDs (e.g. `#gmailActionBar`, `#dockedComposeContainer`, `#inboxCategoryTabs`, `#userProfileBtn`) must be preserved.
4. **No AI Slop / Over-Decoration:** No synthetic gradients, glassmorphism, glowing borders, giant pill cards, or distracting animations. Follow clean, restrained SaaS editorial standards (Material 3 / desktop Gmail parity).
5. **Data Loss Prevention:** Never allow accidental navigation or modal dismissal to silently discard unsaved email drafts or profile edits. Destructive actions (Trash, Delete Label, Discard) must be protected by accessible confirmation or immediate Undo.
6. **Server-Side Authorization:** Admin actions and routes must always enforce `require_admin()` server-side; UI hiding is cosmetic only.

---

## Review Focus

The five failure modes most likely to impact a user:
1. **Accidental Composer Discard:** User clicks Discard on `public/compose.php` or docked composer and loses typed message without confirmation.
2. **Theme State Desynchronization:** Changing theme in Quick Settings updates local view but fails to reflect in Header icon or Account Settings, or fails to persist across reloads.
3. **Sidebar Navigation Collision:** Clicking labels or folders with long names wraps awkwardly or pushes the unread badge offscreen due to flex space-between rules.
4. **Iframe Message Body Typography & Dark Mode:** Reading plain-text or sanitized HTML email defaults to browser serif (Times New Roman) or renders unreadable dark text on dark backgrounds.
5. **Mobile Viewport Clipping:** On small viewports (<768px), docked composer, quick settings offcanvas, or mail search popover exceeds screen width and prevents closing.

---

## Verified Technology Inventory & Current Architecture

### 1. File Structure & Responsibilities
- `includes/header.php`: Global HTML `<head>`, Inter font, navbar `#webmailNavbar`, centered search bar `#gmailSearchBar`, Quick Settings trigger `#btnQuickSettings`, theme toggle `#themeToggleBtn`, Google Apps launcher `#googleAppsLauncherBtn`, profile menu `#userProfileBtn`, Quick Settings offcanvas `#quickSettingsOffcanvas`, and sidebar `<nav id="sidebarMenu">`.
- `includes/footer.php`: Docked compose host `#dockedComposeContainer`, Gmail snackbar `#gmailSnackbar`, shortcut help modal `#shortcutHelpModal`, and JavaScript script includes.
- `includes/functions.php`: Helpers (`app_base_url()`, `csrf_token()`, `e()`, `format_bytes()`, `mail_pagination_query()`, `sanitize_email_html()`).
- `includes/auth.php`: Authentication state, session epoch validation, role authorization (`require_admin()`, `is_admin()`, `require_auth()`).
- `public/inbox.php`: Universal action bar `#gmailActionBar`, category tabs `#inboxCategoryTabs`, hairline email list `#mailListItems`, contextual batch toolbar, and pagination.
- `public/read.php`: Message reader, conversation thread accordion, action toolbar, organize/safety panel, sanitized iframe body `srcdoc`, and attachment cards.
- `public/compose.php`: Full-page compose, rich text WYSIWYG editor `#richEditor`, recipient chips, drag-and-drop dropzone `#attachmentDropzone`, schedule controls, and footer actions.
- `public/settings.php`: Profile details form, password change form with strength meter, and organization panel `#organization` (labels, custom folders, blocked senders).
- `public/api/`: AJAX endpoints for `theme.php`, `autosave_draft.php`, `batch_actions.php`, `get_email.php`, `contacts.php`, and `toggle_star.php`.
- `admin/`: Server-protected administrative workspace (`index.php`, `users.php`, `logs.php`, `registrations.php`, `spam-reports.php`, `create-user.php`, `edit-user.php`).
- `assets/css/style.css`: Primary design tokens, dark mode variables, sidebar styling, email row states, action bar, docked composer, and responsive media queries.
- `assets/js/app.js`: Global client controller handling theme switching, density modes, batch actions, star toggling, docked compose, keyboard shortcuts, and snackbars.

---

## Confirmed Audit Findings Matrix

| Audit ID | Document Area | Audit Screenshot Observation | Codebase Investigation Result | Root Cause in Code | Status |
|---|---|---|---|---|---|
| **NAV-01** | Expanded sidebar | Icons and labels separated by very large gap | **CONFIRMED** | `assets/css/style.css:540` sets `.webmail-nav-link { justify-content: space-between; }`. Direct children `<i>` and `<span>` are pushed to opposite edges. | Confirmed Defect |
| **NAV-02** | Collapsed sidebar | Icon-only actions lack context/tooltips | **CONFIRMED** | `style.css:385-396` hides `.nav-text` in `body.sidebar-mini`, but no tooltips or accessible floating labels exist on collapsed items. | Confirmed Defect |
| **NAV-03** | Sidebar width | Expanded rail consumes ~316px desktop width | **CONFIRMED** | `includes/header.php:364` uses `col-md-3 col-lg-2`. On a 1920px screen, `col-lg-2` is 320px. Needs locked 256px expanded / 72px collapsed. | Confirmed Defect |
| **NAV-04** | Sidebar hierarchy | Labels, folders, workspace have uneven rhythm | **CONFIRMED** | Inconsistent margins between `.sidebar-section-title` (0.72rem uppercase) and `.webmail-nav-link` (0.88rem). | Confirmed Defect |
| **NAV-05** | Label appearance | Color markers are horizontal capsules disconnected from names | **CONFIRMED** | In `includes/header.php:460`, `<span class="label-dot nav-icon">` is pushed far left while `<span class="nav-text">` is pushed far right by `.webmail-nav-link`. | Confirmed Defect |
| **NAV-06** | Active indicator | Left accent stripe and broad tinted pill compete | **CONFIRMED** | `style.css:1254` applies `box-shadow: inset 3px 0 0 var(--primary)` while `style.css:507` applies `background-color: var(--primary-soft) !important`. | Confirmed Defect |
| **NAV-07** | Footer/storage | Storage shows colored segment at 0% usage | **CONFIRMED** | `includes/header.php:520` has `style="width: <?= max(4, $storagePercent) ?>%;"`. The `max(4, ...)` forces a visible colored bar even when usage is 0 bytes! | Confirmed Defect |
| **SHELL-01** | Header | Utility buttons with shadows create visual noise | **CONFIRMED** | `style.css:192-208` enforces `.webmail-header-btn { box-shadow: var(--shadow-sm); border-radius: 50% !important; }` on all header items. | Confirmed Defect |
| **SHELL-02** | Navigation duplication | Bell & Settings in header, sidebar, and launcher | **CONFIRMED** | Bell appears in header (line 196) and sidebar (line 499). Settings appears in header gear (Quick Settings), header profile menu (All Settings), sidebar (line 507), and launcher. | Confirmed Defect |
| **SHELL-03** | Content scrolling | Full page scrollbar; sidebar clips on scroll | **CONFIRMED** | `includes/header.php:361` wraps content in standard `<div class="row">` without `height: 100vh; overflow: hidden;`. Scrolling `<main>` scrolls the sidebar out of view. | Confirmed Defect |
| **SHELL-04** | Launcher | Icon colors/styles vary inconsistently | **CONFIRMED** | `includes/header.php:222-243` hardcodes `text-primary`, `text-success`, `text-secondary`, `text-danger`, `text-info`, `text-warning`. | Confirmed Defect |
| **INBOX-01** | Mail rows | Sender, subject, preview, star, date weak hierarchy | **CONFIRMED** | Typography weights and widths between `.mail-sender` and `.mail-subject` need stricter column budgeting and contrast. | Confirmed Defect |
| **INBOX-02** | Row states | Pale blue row background meaning unclear | **CONFIRMED** | `.mail-item-row.unread` vs `.mail-item-row.read` vs `.mail-item-row.selected` vs `:hover` lack distinguishable border/background tokens. | Confirmed Defect |
| **INBOX-03** | Category tabs | Primary/Promotions/Updates lack counts/states | **CONFIRMED** | `public/inbox.php:263-276` has static tab links. Category filtering does not filter DB query; counts are absent. | Confirmed Defect |
| **INBOX-04** | Sparse mailbox | Bordered inbox panel extends far below 1 message | **CONFIRMED** | `style.css:596` sets `.mail-list-container { min-height: calc(100vh - 240px); }` inside a rounded card container. | Confirmed Defect |
| **INBOX-05** | Toolbar | Selection, refresh, pagination icons separated | **CONFIRMED** | Toolbar buttons lack cohesive button-group styling and responsive auto-collapse. | Confirmed Defect |
| **MSG-01** | Message actions | 9 competing buttons crowd the top bar | **CONFIRMED** | `public/read.php:250-288` renders Back, Unread, Star, Snooze, Reply, Reply All, Forward, Print, and Trash in a single row. | Confirmed Defect |
| **MSG-02** | Safety controls | Full-width Organize & safety strip takes height | **CONFIRMED** | `public/read.php:291-320` renders a separate prominent card above the email body for labels/blocking/spam. | Confirmed Defect |
| **MSG-03** | Reading surface | Short message sits in tall empty bordered card | **CONFIRMED** | `public/read.php:473` has `style="min-height: 140px; height: 30vh;"` on the iframe. | Confirmed Defect |
| **MSG-04** | Typography | Message body renders in serif font | **CONFIRMED** | `public/read.php:416` sets `font: inherit` inside `srcdoc` iframe. An iframe document defaults to browser serif (Times New Roman) because `html` has no font family! | Confirmed Defect |
| **MSG-05** | Reply duplication | Reply actions duplicated at top and bottom | **CONFIRMED** | Both the top toolbar and bottom accordion contain full Reply/Reply All buttons. | Confirmed Defect |
| **COM-01** | Composer hierarchy | Send, Draft, Schedule, Discard compete in footer | **CONFIRMED** | `public/compose.php:450-479` renders 4 competing action styles across the wide footer. | Confirmed Defect |
| **COM-02** | Subject field | Awkward Subject label/input layout | **CONFIRMED** | `public/compose.php:372` uses Bootstrap `.input-group` with fixed 70px label that looks clunky compared to borderless Gmail style. | Confirmed Defect |
| **COM-03** | Editor | Signature occupies blank editor | **CONFIRMED** | `public/compose.php:423` injects signature directly into editable `#richEditor` DOM instead of appending on send or separating with a dashed boundary. | Confirmed Defect |
| **COM-04** | Attachments | Oversized persistent dropzone takes huge space | **CONFIRMED** | `public/compose.php:431` renders permanent 100px dashed border box even when zero attachments exist. | Confirmed Defect |
| **COM-05** | Schedule | Schedule controls crowded in footer | **CONFIRMED** | `public/compose.php:462` renders raw `datetime-local` input inline in the footer. Needs a clean modal/popover dialog. | Confirmed Defect |
| **COM-06** | Draft persistence | Discard is an unguarded link; data loss risk | **CONFIRMED** | `public/compose.php:476` is `<a href="inbox.php">Discard</a>`. Clicking it immediately discards without warning! | Confirmed Defect |
| **QUICK-01** | Quick Settings | Varying radio/segment styles and spacing | **CONFIRMED** | Spacing and typography in `#quickSettingsOffcanvas` differ from the rest of the app. | Confirmed Defect |
| **QUICK-02** | Settings semantics | Density options lack clear visual definitions | **CONFIRMED** | `public/inbox.php` and `header.php` labels don't show visual representations of row height. | Confirmed Defect |
| **QUICK-03** | Preference sync | Theme toggle doesn't update Quick Settings state | **CONFIRMED** | `assets/js/app.js:220-260` `applyTheme()` updates navbar and Account Settings radios, but ignores `#quickSettingsOffcanvas .theme-select-btn`. | Confirmed Defect |
| **QUICK-04** | Drawer accessibility | Focus trap and aria attributes incomplete | **CONFIRMED** | Missing `aria-modal="true"`, `role="dialog"`, and explicit focus restoration to `#btnQuickSettings`. | Confirmed Defect |
| **ACCOUNT-01** | Account Settings | Form stretches over full wide screen | **CONFIRMED** | `public/settings.php:142` uses unbounded grid rows that stretch across 1920px viewports. Needs `max-width: 1080px; margin: 0 auto;`. | Confirmed Defect |
| **ACCOUNT-02** | Profile summary | Summary takes excessive vertical space | **CONFIRMED** | 64px avatar and tall card padding (`p-4`) consume 180px vertical space. | Confirmed Defect |
| **ACCOUNT-03** | Password form | Rules and strength pills are tiny (0.72rem) | **CONFIRMED** | `public/settings.php:286` sets `style="font-size: 0.72rem;"` on rule badges. | Confirmed Defect |
| **ACCOUNT-04** | Signature area | Textarea + live preview takes excessive height | **CONFIRMED** | `public/settings.php:214-220` stacks 4-row textarea directly above a permanent preview card. | Confirmed Defect |
| **ACCOUNT-05** | Long settings page | All settings stacked in one long page | **CONFIRMED** | Profile, Security, Organization, and Blocked Senders form one continuous 400-line document without local tab/anchor navigation. | Confirmed Defect |
| **ACCOUNT-06** | Label/folder CRUD | Tiny `+` and trash icons lack textual affordance | **CONFIRMED** | `public/settings.php:345` has icon-only `<button class="btn btn-sm btn-primary"><i class="bi bi-plus-lg"></i></button>`. | Confirmed Defect |
| **ADMIN-01** | Launcher | Role-aware menu and backend authorization | **VERIFIED OK** | `includes/header.php:233` gates links by `$isAdmin`. All `admin/*.php` files enforce `require_admin()` server-side. | Verified Safe |
| **CROSS-01** | Feedback | Lack of async/validation feedback across actions | **CONFIRMED** | `assets/js/app.js:1893` defines `window.showGmailSnackbar` but it is NEVER invoked by batch actions, star toggling, or draft saving! | Confirmed Defect |
| **CROSS-02** | Responsive | Mobile/tablet navigation and docked compose | **CONFIRMED** | Docked compose on mobile (<576px) does not expand to full screen; sidebar collapse needs clean overlay drawer behavior. | Confirmed Defect |
| **CROSS-03** | Design tokens | Inconsistent colors, radii, borders, shadows | **CONFIRMED** | `style.css` contains hardcoded hex values (`#c2e7ff`, `#004a77`, `#1a73e8`, `#5f6368`) mixed with CSS custom properties. | Confirmed Defect |
| **CROSS-04** | Accessibility | Touch targets, focus indicators, contrast | **CONFIRMED** | Multiple utility buttons are under 36px, and color contrast on `.text-muted` in dark mode is under 4.5:1. | Confirmed Defect |

---

## Proposed Design System & Semantic Tokens

### Semantic Color & Surface Tokens (`assets/css/style.css`)
```css
:root {
    /* Brand & Accent */
    --color-primary: #1a73e8;
    --color-primary-hover: #1557b0;
    --color-primary-soft: #e8f0fe;
    --color-primary-container: #d3e3fd;
    --color-on-primary-container: #041e49;

    /* Neutrals & Surfaces */
    --color-canvas: #f6f8fc;
    --color-surface: #ffffff;
    --color-surface-subtle: #f2f5f9;
    --color-surface-variant: #e1e3e1;
    --color-border: #e0e4eb;
    --color-border-subtle: #edf0f5;
    --color-border-strong: #c4c7c5;

    /* Typography */
    --color-text-main: #1f1f1f;
    --color-text-secondary: #444746;
    --color-text-muted: #5e5e5e;
    --color-text-disabled: #8e918f;

    /* Feedback & Safety */
    --color-success: #137333;
    --color-success-soft: #e6f4ea;
    --color-warning: #b06000;
    --color-warning-soft: #fef7e0;
    --color-danger: #c5221f;
    --color-danger-soft: #fce8e6;

    /* Geometry & Layout */
    --sidebar-expanded-width: 256px;
    --sidebar-collapsed-width: 72px;
    --header-height: 60px;
    --radius-sm: 6px;
    --radius-md: 10px;
    --radius-lg: 16px;
    --radius-pill: 9999px;

    /* Elevation Shadows */
    --shadow-subtle: 0 1px 2px 0 rgba(60, 64, 67, 0.08);
    --shadow-dropdown: 0 4px 12px 0 rgba(60, 64, 67, 0.15), 0 1px 3px 0 rgba(60, 64, 67, 0.1);
    --shadow-modal: 0 8px 28px 0 rgba(60, 64, 67, 0.28);
}

[data-bs-theme="dark"] {
    --color-primary: #a8c7fa;
    --color-primary-hover: #c2e7ff;
    --color-primary-soft: #004a77;
    --color-primary-container: #003355;
    --color-on-primary-container: #c2e7ff;

    --color-canvas: #111318;
    --color-surface: #1e1f24;
    --color-surface-subtle: #282a2f;
    --color-surface-variant: #444746;
    --color-border: #33353a;
    --color-border-subtle: #2b2d33;
    --color-border-strong: #4f5258;

    --color-text-main: #e3e3e3;
    --color-text-secondary: #c4c7c5;
    --color-text-muted: #8e918f;
    --color-text-disabled: #5e5e5e;

    --color-success: #81c995;
    --color-success-soft: #0f381e;
    --color-warning: #fdd663;
    --color-warning-soft: #3e2e04;
    --color-danger: #f28b82;
    --color-danger-soft: #3c1514;
}
```

---

## Phased Implementation Roadmap

```
Phase 1: Safety & Data Integrity (P0)
   ├── COM-06: Guarded compose discard & beforeunload draft protection
   ├── COM-02: Subject line label/input borderless restyle
   ├── COM-05: Schedule send dedicated modal & time zone validation
   ├── QUICK-03: Theme preference multi-surface synchronization
   ├── ACCOUNT-03: Password rules & strength validation readability
   ├── ACCOUNT-06: Label/Folder CRUD textual affordances & undo
   └── CROSS-01: Connect showGmailSnackbar to all async operations

Phase 2: App Shell, Header & Sidebar Alignment (P1)
   ├── NAV-01 & NAV-05: Sidebar icon + label grouping (fix space-between gap)
   ├── NAV-03 & SHELL-03: Locked 256px/72px sidebar with independent content scroll
   ├── NAV-06: Unified active state (remove competing inset shadow)
   ├── NAV-07: Storage meter math fix (remove max(4, %) clip at 0%)
   ├── NAV-02: Collapsed sidebar tooltips & accessible names
   ├── NAV-04: Standardized section headers and vertical rhythm
   ├── SHELL-01: Header utility buttons restyle (quiet, borderless hover)
   ├── SHELL-02: Navigation duplication cleanup (Header vs Sidebar)
   └── SHELL-04: Unified monochrome launcher icons

Phase 3: Core Mail Workflows — List & Reader (P1/P2)
   ├── INBOX-01 & INBOX-02: Mail row grid alignment & clear read/unread/selected states
   ├── INBOX-03: Category tabs active indicator & honest count badges
   ├── INBOX-04: Responsive mail list panel height (remove artificial 100vh stretch)
   ├── INBOX-05: Cohesive batch action toolbar grouping
   ├── MSG-01 & MSG-02: Streamlined message action toolbar & compact safety menu
   ├── MSG-03: Content-driven message body height
   ├── MSG-04: Iframe body typography fix (replace Times New Roman with system sans)
   └── MSG-05: Primary top reply vs lightweight bottom reply affordance

Phase 4: Composer & Attachments Refinement (P1)
   ├── COM-01: Send-first primary button hierarchy
   ├── COM-03: Visually distinct signature boundary in editor
   ├── COM-04: Collapsed attachment bar with drag-over dropzone expansion
   └── Docked Composer mobile full-screen responsiveness

Phase 5: Settings & Admin IA Reorganization (P1/P2)
   ├── QUICK-01 & QUICK-02: Quick Settings density preview cards & spacing
   ├── QUICK-04: Quick Settings drawer focus trap & aria-modal
   ├── ACCOUNT-01: Controlled content width (max 1080px)
   ├── ACCOUNT-02: Compact identity header
   ├── ACCOUNT-04: Collapsible signature preview
   └── ACCOUNT-05: Settings tabbed navigation (Profile, Security, Organization)

Phase 6: Verification, Accessibility & Responsive Matrix (P0/P1)
   ├── CROSS-02: Viewport testing (320px, 375px, 768px, 1024px, 1440px)
   ├── CROSS-03: Design token audit across light and dark modes
   ├── CROSS-04: Keyboard-only navigation & WCAG 2.2 AA target size check
   └── Automated test suite validation (`npm test`)
```

---

## Detailed Task Specifications

### Phase 1: Safety & Data Integrity (P0)

#### Task 1.1: Guard Compose Discard & Prevent Accidental Data Loss (COM-06)
- **Problem:** Clicking "Discard" on `public/compose.php` is a raw link `<a href="inbox.php">` that immediately unloads the page without confirmation, destroying typed emails.
- **Root Cause:** Missing `beforeunload` listener and missing confirmation dialog on the Discard link in `public/compose.php` and `assets/js/app.js`.
- **Solution:** 
  1. Add `data-confirm="Discard this draft? All unsaved changes will be lost."` to the Discard button.
  2. In `app.js`, track a `isDirty` flag on `#richEditor` and recipient fields.
  3. Attach `window.addEventListener('beforeunload')` when `isDirty` is true and message has not been sent.
  4. In docked compose, prompt confirmation before closing if body contains > 10 characters.
- **Exact Files:** `public/compose.php:476-479`, `includes/footer.php:26-32`, `assets/js/app.js`.
- **Test:** Open compose, type "Important meeting notes", click Discard. Expected: Browser confirmation dialog prevents navigation until confirmed.

#### Task 1.2: Restyle Subject Field to Eliminate Label/Caret Overlap (COM-02)
- **Problem:** Fixed 70px `.input-group-text` label crowds subject input on narrow viewports.
- **Root Cause:** Bootstrap input-group structure in `public/compose.php:372-373`.
- **Solution:** Replace `.input-group` with a borderless row matching modern email clients: `<div class="compose-field-row border-bottom px-3 py-2"><input type="text" name="subject" id="subjectInput" class="form-control border-0 p-0 shadow-none fw-semibold" placeholder="Subject"></div>`.
- **Exact Files:** `public/compose.php:371-374`, `assets/css/style.css`.
- **Test:** Focus subject input. Expected: Clean borderless input, zero label collision, accessible placeholder.

#### Task 1.3: Dedicated Schedule Send Dialog & Time Zone Display (COM-05)
- **Problem:** Raw `datetime-local` input and timezone label are cramped inline inside the composer footer.
- **Root Cause:** `public/compose.php:462-474` inline layout.
- **Solution:** 
  1. Replace inline controls with a clean split button or secondary action: `<button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#scheduleSendModal"><i class="bi bi-clock-history me-1"></i>Schedule</button>`.
  2. Implement `#scheduleSendModal` with preset buttons ("Tomorrow morning (8:00 AM)", "Tomorrow afternoon (1:00 PM)", "Monday morning (8:00 AM)") plus a custom datetime picker with clear timezone label.
- **Exact Files:** `public/compose.php:462-474`, `assets/js/app.js`.
- **Test:** Click Schedule. Expected: Modal opens, displays user's configured timezone (`Asia/Manila`, `UTC`, etc.), validates future time, submits confirmed schedule.

#### Task 1.4: Multi-Surface Theme Preference Synchronization (QUICK-03)
- **Problem:** Quick Settings drawer theme buttons (`.theme-select-btn`) do not update their active state when theme changes, and header toggle doesn't sync Quick Settings.
- **Root Cause:** `assets/js/app.js:217` `applyTheme()` updates `themeToggleBtn` and `.settings-theme-radio`, but does NOT update `#quickSettingsOffcanvas .theme-select-btn`.
- **Solution:** 
  Inside `applyTheme(theme, persist)`:
  ```javascript
  document.querySelectorAll('#quickSettingsOffcanvas .theme-select-btn').forEach(btn => {
      const btnTheme = btn.getAttribute('data-theme');
      if (btnTheme === preference) {
          btn.classList.add('active', 'btn-primary');
          btn.classList.remove('btn-outline-secondary');
          btn.setAttribute('aria-pressed', 'true');
      } else {
          btn.classList.remove('active', 'btn-primary');
          btn.classList.add('btn-outline-secondary');
          btn.setAttribute('aria-pressed', 'false');
      }
  });
  ```
- **Exact Files:** `assets/js/app.js:217-265`.
- **Test:** Click "Dark" in Quick Settings. Expected: Navbar toggle icon becomes moon, Quick Settings "Dark" button gains active state, Account Settings radio selects Dark, `data-bs-theme="dark"` is set on `<html>`, and POST to `/public/api/theme.php` succeeds.

#### Task 1.5: Password Validation & Rules Readability (ACCOUNT-03)
- **Problem:** Password rule badges are tiny (0.72rem), low contrast, and hard to parse.
- **Root Cause:** `public/settings.php:286-291` inline font-size and small badges.
- **Solution:** 
  1. Replace inline 0.72rem styles with structured checklist: `<ul class="list-unstyled small mt-2 mb-0 password-checklist">`.
  2. Each rule has a dynamic status icon: `<li id="ruleLength" class="text-muted"><i class="bi bi-circle me-1"></i> At least 8 characters</li>`.
  3. When satisfied, transition to `<i class="bi bi-check-circle-fill text-success me-1"></i>` with 4.5:1 contrast in both light and dark modes.
- **Exact Files:** `public/settings.php:279-293`, `assets/js/app.js:340-410`.
- **Test:** Type "Pass123!" in Settings password input. Expected: Rules checklist dynamically checks off each condition with clear green checkmarks.

#### Task 1.6: Label/Folder CRUD Textual Affordance & Destructive Confirmation (ACCOUNT-06)
- **Problem:** Creating labels/folders uses icon-only `+` buttons; deleting uses icon-only red trash bins with minimal context.
- **Root Cause:** `public/settings.php:345, 366, 378`.
- **Solution:** 
  1. Change submit button to `<button type="submit" class="btn btn-sm btn-primary px-3"><i class="bi bi-plus-lg me-1"></i>Add</button>`.
  2. Style delete buttons as subtle ghost icons that expand or show tooltip: `<button type="submit" class="btn btn-sm btn-link text-danger p-1" title="Delete label" aria-label="Delete label <?= e($label['name']) ?>"><i class="bi bi-trash3"></i></button>`.
  3. Ensure `data-confirm` prompt explicitly names the item being deleted.
- **Exact Files:** `public/settings.php:321-380`.
- **Test:** Delete a label. Expected: Confirmation modal/prompt states "Delete label 'Work'? Emails with this label will not be deleted."

#### Task 1.7: Connect Universal Feedback Snackbar to All Async Actions (CROSS-01)
- **Problem:** `window.showGmailSnackbar` exists in `assets/js/app.js:1893` but is never invoked for batch actions, quick actions, draft autosaves, or star toggling.
- **Root Cause:** Missing invocations in AJAX callbacks in `assets/js/app.js`.
- **Solution:**
  1. Wire `showGmailSnackbar('Conversation moved to Trash', onUndo)` into batch action and quick trash handlers.
  2. Wire `showGmailSnackbar('Draft saved')` into `autosave_draft.php` success callback.
  3. Wire `showGmailSnackbar('Message starred')` / `showGmailSnackbar('Message unstarred')` into `toggle_star.php` handler.
  4. Ensure snackbar has `role="status"` and `aria-live="polite"` so screen readers announce completions.
- **Exact Files:** `assets/js/app.js:450-800, 1893-1925`.
- **Test:** Click "Archive" on an email row. Expected: Bottom-left snackbar appears with "Conversation archived" and an active "Undo" button for 6 seconds.

---

### Phase 2: App Shell, Header & Sidebar Alignment (P1)

#### Task 2.1: Fix Sidebar Icon & Label Separation (NAV-01 & NAV-05)
- **Problem:** Expanded sidebar separates icons and labels by a huge gap across the 256px rail.
- **Root Cause:** `style.css:540` `.webmail-nav-link { justify-content: space-between; }`.
- **Solution:**
  1. Change `.webmail-nav-link` to `justify-content: flex-start; gap: 12px; position: relative;`.
  2. Add `margin-left: auto;` to `.webmail-nav-link .badge` to keep counts aligned to the far right.
  3. Group `.label-dot` with text: `<span class="d-flex align-items-center gap-2 text-truncate"><span class="label-dot" ...></span><span class="nav-text">...</span></span>`.
- **Exact Files:** `assets/css/style.css:537-573`, `includes/header.php:376-511`.
- **Test:** Open expanded sidebar. Expected: Icon is 12px from label text; unread count badge is pinned to the right edge; no awkward dead space.

#### Task 2.2: Lock Sidebar Dimensions & Enable Independent Content Scrolling (NAV-03 & SHELL-03)
- **Problem:** Sidebar width stretches dynamically with Bootstrap grid (`col-lg-2`), and full page scrolls vertically, scrolling sidebar away.
- **Root Cause:** Standard Bootstrap grid `<div class="row">` without locked flex viewport height.
- **Solution:**
  1. Structure app viewport:
     ```css
     .app-viewport {
         display: flex;
         flex-direction: column;
         height: 100vh;
         overflow: hidden;
     }
     .app-body-container {
         display: flex;
         flex: 1 1 0%;
         overflow: hidden;
         position: relative;
     }
     .webmail-sidebar {
         width: var(--sidebar-expanded-width, 256px);
         flex: 0 0 var(--sidebar-expanded-width, 256px);
         height: 100%;
         overflow-y: auto;
         overflow-x: hidden;
     }
     body.sidebar-mini .webmail-sidebar {
         width: var(--sidebar-collapsed-width, 72px) !important;
         flex: 0 0 var(--sidebar-collapsed-width, 72px) !important;
     }
     .main-content-scrollable {
         flex: 1 1 0%;
         overflow-y: auto;
         height: 100%;
     }
     ```
  2. Remove full-page vertical scrollbar; main content scrolls independently while header and sidebar remain stable.
- **Exact Files:** `includes/header.php:360-365, 530`, `assets/css/style.css:366-384`.
- **Test:** Open long Settings page or 50-message Inbox. Scroll down. Expected: Header and sidebar stay fixed; only main content scrolls.

#### Task 2.3: Unify Navigation Active Indicator (NAV-06)
- **Problem:** Active navigation item renders both an inset box-shadow border and a broad tinted background pill.
- **Root Cause:** Conflict between `style.css:507` and `style.css:1254`.
- **Solution:**
  1. Remove `box-shadow: inset 3px 0 0 var(--primary);` from `style.css:1254`.
  2. Standardize on Material 3 active pill: `background-color: var(--color-primary-soft); color: var(--color-primary); font-weight: 600; border-radius: var(--radius-pill);`.
  3. Add `aria-current="page"` to the active navigation link for screen readers.
- **Exact Files:** `assets/css/style.css:507-516, 1253-1255`, `includes/header.php:376-445`.
- **Test:** Inspect Inbox link when on `public/inbox.php`. Expected: Clean single active pill, zero left border stripe, `aria-current="page"`.

#### Task 2.4: Fix Storage Meter Math & 0% Usage Indicator (NAV-07)
- **Problem:** Storage meter displays a colored bar even when usage is 0 bytes.
- **Root Cause:** `includes/header.php:520` has `style="width: <?= max(4, $storagePercent) ?>%;"`.
- **Solution:**
  1. Change line 520 to:
     ```php
     $barWidth = $userStorageBytes > 0 ? max(2, $storagePercent) : 0;
     ```
     `<div class="progress-bar <?= ... ?>" role="progressbar" style="width: <?= $barWidth ?>%;" ...>`
  2. When `$userStorageBytes === 0`, width is exactly `0%` and no colored segment renders.
- **Exact Files:** `includes/header.php:514-526`.
- **Test:** User with 0 bytes storage. Expected: Empty gray progress track, "0% utilized", 0 colored pixels inside track.

#### Task 2.5: Collapsed Sidebar Tooltips & Accessible Labels (NAV-02)
- **Problem:** When collapsed to 72px mini-rail, items have no text labels or hover tooltips.
- **Root Cause:** Bootstrap tooltip initialization missing on mini-rail links.
- **Solution:**
  1. Add `data-bs-toggle="tooltip"` and `data-bs-placement="right"` to all sidebar navigation items.
  2. In `app.js`, initialize tooltips conditionally when `document.body.classList.contains('sidebar-mini')`.
- **Exact Files:** `includes/header.php:376-511`, `assets/js/app.js`.
- **Test:** Collapse sidebar to mini-rail. Hover over Starred icon. Expected: Bootstrap tooltip "Starred" appears cleanly to the right of the icon.

#### Task 2.6: Standardize Section Headers & Vertical Rhythm (NAV-04)
- **Problem:** Section titles (Mail, Labels, Folders, Workspace) have erratic padding and font sizes.
- **Root Cause:** Scattered margin definitions across `style.css`.
- **Solution:** Define uniform `.sidebar-section-title` class: `font-size: 0.75rem; font-weight: 600; letter-spacing: 0.04em; color: var(--color-text-muted); text-transform: uppercase; padding: 1rem 0.75rem 0.25rem; margin: 0;`.
- **Exact Files:** `assets/css/style.css:527-536`.
- **Test:** Inspect sidebar. Expected: Visual spacing between Mail, Labels, Folders, and Workspace is identical and aligned.

#### Task 2.7: Restyle Header Utility Controls (SHELL-01 & SHELL-04)
- **Problem:** Circular utility buttons with drop shadows look cluttered; launcher icons have clashing colors.
- **Root Cause:** `.webmail-header-btn` shadow rules and multi-colored icon classes in `includes/header.php:196-245`.
- **Solution:**
  1. Remove `shadow-sm` and hard borders from header buttons. Give them a flat, quiet default with a subtle circular hover tint (`rgba(60, 64, 67, 0.08)` in light, `rgba(255, 255, 255, 0.08)` in dark).
  2. Change launcher grid icons from rainbow (`text-primary`, `text-success`, etc.) to a cohesive monochrome tone (`var(--color-text-secondary)`), preserving clarity through icon shape and label.
- **Exact Files:** `assets/css/style.css:191-216`, `includes/header.php:196-248`.
- **Test:** View top header. Expected: Clean, borderless utility icons that illuminate softly on hover; app launcher grid has uniform, elegant monochrome glyphs.

#### Task 2.8: Streamline Navigation Entry Points (SHELL-02)
- **Problem:** Duplicate Bell (Notifications) and Gear (Settings) across Header, Sidebar, and Profile Menu.
- **Root Cause:** Historical accretion of entry points.
- **Solution:**
  - **Header Bell:** Primary notification center with unread counter badge.
  - **Header Gear:** Dedicated to Quick Settings offcanvas drawer (theme, density, reading pane).
  - **Sidebar Settings & Profile Menu "Manage Account":** Explicitly routes to full `/public/settings.php` (profile, security, organization).
  - Remove duplicate Bell from Sidebar Workspace section to eliminate redundancy.
- **Exact Files:** `includes/header.php:499-505`.
- **Test:** Verify clear differentiation: Gear opens Quick Settings drawer; sidebar Settings navigates to full Account Settings.

---

### Phase 3: Core Mail Workflows — List & Reader (P1/P2)

#### Task 3.1: Mail Row Grid Alignment & Read/Unread State Hierarchy (INBOX-01 & INBOX-02)
- **Problem:** Sender, subject, snippet, and date have weak typographic contrast; hover and selection states are ambiguous.
- **Root Cause:** Mixed flex column widths and insufficient weight distinction in `.mail-item-row`.
- **Solution:**
  1. Establish strict typographic rules:
     - **Unread:** `font-weight: 700; color: var(--color-text-main);` on sender and subject; unread blue dot visible.
     - **Read:** `font-weight: 400; color: var(--color-text-secondary);` on sender; unread dot hidden.
     - **Selected:** Distinct blue-tinted surface `background-color: var(--color-primary-soft) !important;` with checkbox checked.
     - **Hover:** Subtle neutral hover tint `var(--color-surface-subtle)` without competing with selection.
  2. Budget row column widths: Checkbox (32px), Star (32px), Sender (160–200px fixed), Subject + Snippet (flex-grow), Date (80px fixed right-aligned).
- **Exact Files:** `assets/css/style.css:600-660, 1467-1474`, `public/inbox.php:314-380`.
- **Test:** View mixed inbox of read, unread, and selected emails. Expected: Instant visual scanning differentiation; checkboxes and stars align vertically across all rows.

#### Task 3.2: Category Tabs Polish & Backend Count Integration (INBOX-03)
- **Problem:** Category tabs (Primary, Promotions, Updates) lack unread counts and don't reflect email category status.
- **Root Cause:** `public/inbox.php:263-276` static HTML.
- **Solution:**
  1. Add unread count badge to Primary tab: `<span class="badge rounded-pill bg-primary ms-1"><?= $unreadInboxCount ?></span>`.
  2. When Promotions or Updates tab is selected and has no messages, show an intentional, polite category empty state ("No promotional emails", "No updates found") rather than an error or blank card.
  3. Keep active underline indicator (`border-bottom: 3px solid var(--color-primary)`).
- **Exact Files:** `public/inbox.php:254-277`, `assets/css/style.css:2019-2058`.
- **Test:** Click "Promotions" tab. Expected: Active indicator moves to Promotions, URL updates to `?category=promotions`, and friendly empty state renders if empty.

#### Task 3.3: Content-Driven Mail List Container Height (INBOX-04)
- **Problem:** In an inbox with only 1 message, the bordered container extends artificially to bottom of viewport.
- **Root Cause:** `.mail-list-container { min-height: calc(100vh - 240px); }`.
- **Solution:** Change `min-height` to `auto;` when emails are present. Keep full height only when `.empty-state-container` is rendered so the empty graphic is vertically centered.
- **Exact Files:** `assets/css/style.css:593-598`.
- **Test:** Single message inbox. Expected: Row hugs content naturally; no giant empty white slab below it.

#### Task 3.4: Message Reader Action Toolbar Consolidation (MSG-01 & MSG-02)
- **Problem:** Reader top toolbar has 9 competing buttons, followed by an oversized "Organize & safety" card.
- **Root Cause:** `public/read.php:250-320`.
- **Solution:**
  1. Streamline top action bar:
     - Primary visible actions: **Back**, **Archive**, **Trash**, **Mark Unread**, **Reply**.
     - Secondary actions grouped into a clean "More" dropdown: Snooze, Forward, Print, Add Label, Move to Folder, Block Sender, Report Spam.
  2. Collapse the full-width "Organize & safety" strip into the reader header or dropdown menu, reclaiming 120px of vertical reading space.
- **Exact Files:** `public/read.php:250-320`.
- **Test:** Open an email. Expected: Clean top toolbar with primary actions visible; safety and organize controls cleanly accessible via dropdown without dominating the screen.

#### Task 3.5: Fix Iframe Email Body Typography & Auto-Height (MSG-03 & MSG-04)
- **Problem:** Email message body renders in Times New Roman serif font, and short emails have a fixed 30vh height.
- **Root Cause:** `public/read.php:416` specifies `font: inherit` inside `srcdoc` iframe, defaulting to browser serif. `public/read.php:473` sets fixed `height: 30vh;`.
- **Solution:**
  1. In `public/read.php:415-420`, inject system font family into iframe `<style>`:
     ```css
     html, body {
         margin: 0;
         padding: 0;
         font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
         font-size: 15px;
         line-height: 1.6;
         color: inherit;
         background: transparent;
         overflow-wrap: anywhere;
     }
     ```
  2. In `app.js`, add an iframe auto-resize listener:
     ```javascript
     document.querySelectorAll('iframe.email-body-content').forEach(iframe => {
         iframe.addEventListener('load', () => {
             const doc = iframe.contentDocument || iframe.contentWindow?.document;
             if (doc && doc.body) {
                 iframe.style.height = Math.max(80, doc.body.scrollHeight + 16) + 'px';
             }
         });
     });
     ```
- **Exact Files:** `public/read.php:415-420, 473`, `assets/js/app.js`.
- **Test:** Open a 2-line plain text email. Expected: Renders in clean Inter/system sans font; iframe height matches exact 2 lines without 300px empty space.

---

### Phase 4: Composer & Attachments Refinement (P1)

#### Task 4.1: Establish Primary Action Hierarchy in Composer Footer (COM-01)
- **Problem:** Send, Save Draft, Schedule, and Discard compete with equal visual weight in the footer.
- **Root Cause:** `public/compose.php:450-479`.
- **Solution:**
  1. **Send Message:** Solid blue primary button `#btnSend` (`btn btn-primary px-4 fw-semibold shadow-sm`).
  2. **Save Draft:** Secondary text/icon button (`btn btn-link text-muted`).
  3. **Schedule:** Secondary icon button adjacent to Send with clock icon.
  4. **Discard:** Red danger icon at far right with confirmation guard.
- **Exact Files:** `public/compose.php:450-480`.
- **Test:** Open Composer. Expected: Clear eye path to blue Send button; secondary controls do not distract.

#### Task 4.2: Visual Separation of Editable Body from Signature (COM-03)
- **Problem:** Appended signature sits inside blank editable area and looks like typed user content.
- **Root Cause:** Signature injected directly into `$initialBodyHtml` in `public/compose.php:423`.
- **Solution:** Wrap signature in a distinct container `<div class="mail-signature-block text-muted pt-3 mt-3 border-top border-dashed" contenteditable="false">` so the user clearly sees where message body ends and signature begins.
- **Exact Files:** `public/compose.php:423-426`, `assets/css/style.css`.
- **Test:** Compose new message. Expected: Cursor focuses above dashed line; signature is visibly distinguished below dashed line.

#### Task 4.3: Compact Attachment Affordance with Drag-and-Drop Expansion (COM-04)
- **Problem:** Permanent 100px dashed dropzone box takes space before user even chooses to attach files.
- **Root Cause:** `public/compose.php:430-438`.
- **Solution:**
  1. Default state: Compact attachment action bar with a paperclip button: `<button type="button" class="btn btn-sm btn-outline-secondary" id="btnToggleAttachmentDropzone"><i class="bi bi-paperclip me-1"></i>Attach files</button>`.
  2. Drag-and-drop listener: When user drags a file over the composer window, smoothly expand the dashed dropzone `#attachmentDropzone`.
  3. When files are attached, show compact removable chips (`.attachment-chip`).
- **Exact Files:** `public/compose.php:430-443`, `assets/js/app.js`, `assets/css/style.css`.
- **Test:** Open compose. Expected: Clean editor without oversized dashed box; dragging a file from desktop illuminates the dropzone automatically.

---

### Phase 5: Settings & Admin IA Reorganization (P1/P2)

#### Task 5.1: Quick Settings Density Options Clarity (QUICK-01 & QUICK-02)
- **Problem:** `Default`, `Comfortable`, `Compact` density labels lack clear visual explanations.
- **Root Cause:** Plain radio buttons in `includes/header.php:307-324`.
- **Solution:**
  1. Add mini graphical row height previews next to each density option.
  2. Add concise subtext:
     - **Default:** Standard spacing with file attachment chips.
     - **Comfortable:** Single-line rows without attachment chips.
     - **Compact:** High-density minimal padding for large screens.
- **Exact Files:** `includes/header.php:304-325`, `assets/css/style.css`.
- **Test:** Open Quick Settings drawer. Expected: Visual cards for density with immediate live preview upon selection.

#### Task 5.2: Focus Trapping & Accessibility for Quick Settings Drawer (QUICK-04)
- **Problem:** Offcanvas drawer lacks strict modal semantics and focus restoration.
- **Root Cause:** Missing attributes in `includes/header.php:298`.
- **Solution:**
  Add `role="dialog"`, `aria-modal="true"` to `#quickSettingsOffcanvas`. In `app.js`, listen to `hidden.bs.offcanvas` and explicitly focus `#btnQuickSettings`.
- **Exact Files:** `includes/header.php:298`, `assets/js/app.js`.
- **Test:** Open Quick Settings with keyboard (Tab + Enter on gear icon). Press Esc. Expected: Drawer closes, focus returns to gear icon.

#### Task 5.3: Account Settings Page Layout & Section Navigation (ACCOUNT-01, 02, 04, 05)
- **Problem:** Full settings stretches over full screen (ACCOUNT-01); profile header is too tall (ACCOUNT-02); signature preview takes too much room (ACCOUNT-04); all sections form one 400-line scroll (ACCOUNT-05).
- **Root Cause:** Unbounded layout in `public/settings.php`.
- **Solution:**
  1. Wrap page in `<div class="settings-container" style="max-width: 1080px; margin: 0 auto;">`.
  2. Add horizontal tab navigation:
     - **Profile & Appearance** (Names, timezone, theme, signature).
     - **Security** (Change password).
     - **Organization** (Labels, custom folders, blocked senders).
  3. Compact profile summary header (40px avatar, inline badges).
  4. Make signature preview collapsible with a toggle `<button type="button" class="btn btn-sm btn-link" data-bs-toggle="collapse" data-bs-target="#signaturePreviewCollapse">Preview signature</button>`.
- **Exact Files:** `public/settings.php:140-406`, `assets/css/style.css`.
- **Test:** Visit `/public/settings.php`. Expected: Beautiful, restrained 1080px wide container with intuitive tabbed sections; no endless scrolling.

---

### Phase 6: System-Wide Feedback, Skeletons, Accessibility & Verification (P0/P1)

#### Task 6.1: Skeleton Loading Screens for Mail List & Thread Reader
- **Problem:** Opening a folder or switching categories has no skeleton state; content flashes or appears abruptly.
- **Solution:**
  1. Define lightweight CSS skeleton animation:
     ```css
     .skeleton-row {
         height: 48px;
         background: linear-gradient(90deg, var(--color-surface-subtle) 25%, var(--color-border-subtle) 50%, var(--color-surface-subtle) 75%);
         background-size: 200% 100%;
         animation: skeleton-shimmer 1.5s infinite;
         border-radius: var(--radius-sm);
         margin-bottom: 4px;
     }
     @keyframes skeleton-shimmer {
         0% { background-position: 200% 0; }
         100% { background-position: -200% 0; }
     }
     @media (prefers-reduced-motion: reduce) {
         .skeleton-row { animation: none; background: var(--color-surface-subtle); }
     }
     ```
  2. Show 5 skeleton rows in `#mailListItems` during category tab switching or search AJAX fetches.
- **Exact Files:** `assets/css/style.css`, `assets/js/app.js`.
- **Test:** Switch category tab with network throttled. Expected: Smooth, subtle skeleton bars appear; no layout shift; respects `prefers-reduced-motion`.

#### Task 6.2: Responsive Viewport Hardening (CROSS-02)
- **Problem:** Mobile viewports (<768px) may clip modals or navigation.
- **Solution:**
  1. Viewport 320–375px: Convert sidebar into full offcanvas drawer; stack composer footer vertically.
  2. Viewport 768–1023px: Auto-switch to mini-rail sidebar (72px).
  3. Viewport 1024px+: Full expanded sidebar (256px) with toggleable mini-rail.
  4. Docked compose on mobile (<576px) automatically expands to fullscreen.
- **Exact Files:** `assets/css/style.css:2100-2124`, `includes/header.php`.
- **Test:** Test in browser at 320px, 375px, 768px, 1024px, 1440px. Expected: Zero horizontal page overflow; all touch targets >= 44x44px.

#### Task 6.3: Automated Test Suite Gate Verification
- **Run:** `npm test`
- **Expected:**
  1. `tests/password_change_api_test.php` PASS
  2. `tests/smtp_attachment_fail_closed_test.php` PASS
  3. `tests/mail_delivery_safe_test.php` PASS
  4. `tests/smtp_test.php` PASS
  5. `tests/ui_ux_regression.php` PASS
  6. `tests/gmail_modernization_regression_test.php` PASS
  7. `tests/gmail_revamp_regression_test.php` PASS
  Total: 7/7 suites PASS with 0 failures.

---

## File-Level Change Map

| File Path | Nature of Edits | Audit Findings Addressed |
|---|---|---|
| `assets/css/style.css` | Define semantic tokens, fix `.webmail-nav-link` flex gap, remove active box-shadow stripe, lock 256px/72px sidebar, enable independent scroll, iframe typography, skeleton animation. | NAV-01, NAV-03, NAV-04, NAV-05, NAV-06, SHELL-01, SHELL-03, INBOX-01, INBOX-02, INBOX-04, MSG-03, MSG-04, COM-02, COM-03, ACCOUNT-01, CROSS-03 |
| `assets/js/app.js` | Wire `showGmailSnackbar()` to batch/quick actions, sync Quick Settings theme buttons in `applyTheme()`, add composer `beforeunload` dirty guard, iframe auto-height resize, offcanvas focus trap. | NAV-02, QUICK-03, QUICK-04, COM-06, CROSS-01, CROSS-02, MSG-04 |
| `includes/header.php` | Fix storage bar `max(4, %)` bug, left-align nav icon + label, quiet header buttons, monochrome launcher icons, remove duplicate Bell from sidebar, add density previews. | NAV-01, NAV-03, NAV-04, NAV-05, NAV-07, SHELL-01, SHELL-02, SHELL-04, QUICK-01, QUICK-02, QUICK-04 |
| `public/inbox.php` | Update category tabs with unread badge and friendly empty states, polish action bar button groupings. | INBOX-01, INBOX-02, INBOX-03, INBOX-05 |
| `public/read.php` | Consolidate top action toolbar (Reply, Archive, Trash visible; rest in More menu), collapse separate safety card into header, set iframe system sans-serif font. | MSG-01, MSG-02, MSG-03, MSG-04, MSG-05 |
| `public/compose.php` | Borderless subject field, distinct signature separator, collapsed attachment bar with drag-over dropzone, schedule modal, guarded discard button. | COM-01, COM-02, COM-03, COM-04, COM-05, COM-06 |
| `public/settings.php` | Constrain container to 1080px, compact profile card, tabbed section navigation, accessible password checklist, collapsible signature preview, clear CRUD button affordances. | ACCOUNT-01, ACCOUNT-02, ACCOUNT-03, ACCOUNT-04, ACCOUNT-05, ACCOUNT-06 |

---

## Acceptance Criteria & Testing Matrix

| Verify Category | Acceptance Criteria | Execution Method |
|---|---|---|
| **Sidebar Alignment** | Icon and label are left-aligned with 12px gap. Unread count badge is pinned to far right. Zero space-between gap bug. | Browser inspection at 1280x800 & 1920x1080 |
| **Independent Scroll** | Scrolling main content does NOT scroll header or sidebar. Sidebar storage meter remains accessible at bottom. | Browser wheel/scroll test on Settings & Inbox |
| **Storage Progress** | A user with 0 bytes usage renders a 100% empty progress track without colored sliver. | PHP CLI probe & DOM style check |
| **Iframe Font** | Plain-text email bodies render in system sans-serif font; Times New Roman serif is eliminated. | Computed CSS check on iframe body |
| **Theme Sync** | Clicking Dark/Light/System in Quick Settings immediately updates Navbar icon, Account Settings radios, and `data-bs-theme`. | Click test in browser across both light & dark |
| **Draft Protection** | Navigating away or clicking Discard with typed content triggers a browser confirmation dialog. | Compose dirty test |
| **Schedule Modal** | Scheduling send displays user's exact timezone with clear preset buttons and future-time validation. | Submit scheduled email test |
| **Async Feedback** | Archiving an email, toggling star, or saving draft displays the bottom-left snackbar with confirmed status and Undo. | AJAX batch action test |
| **Account Settings** | Settings content bounded to 1080px max-width; sections divided into clean tabs (Profile, Security, Organization). | Visual verification on desktop |
| **Automated Tests** | 100% of existing test suites in `npm test` PASS without failures or regressions. | `npm test` exit code 0 |

---

## Open Decisions for User Review

1. **Category Classification Logic:** The category tabs currently filter views on the frontend. Should we add a `category` column to the `emails` database table in a future migration, or keep them as smart heuristic client views (e.g. sender/header rules)?
   *Recommendation:* Keep client-side filter heuristics for this UI pass to avoid database schema alterations; offer DB migration as an optional backend follow-up.
2. **Account Settings Sub-Navigation vs Anchors:** Should Account Settings use instant tab switching (`#profile`, `#security`, `#organization`) via JavaScript tabs, or separate sub-pages (`/public/settings/profile.php`, etc.)?
   *Recommendation:* Bootstrap tabs on `/public/settings.php` with URL hash support (`#security`). This keeps all POST actions backwards-compatible with existing PHP endpoints while eliminating the 400-line scroll.
3. **Sidebar Redundant Bell:** The notification Bell currently appears both in the top header and in the sidebar. We recommend keeping the top header Bell (with live badge) and removing the duplicate link from the sidebar Workspace section. Does this align with your workflow?

---

## Rollback & Safety Plan

- All edits are strictly scoped to UI templates (`includes/header.php`, `public/inbox.php`, `public/read.php`, `public/compose.php`, `public/settings.php`), styling (`assets/css/style.css`), and frontend scripting (`assets/js/app.js`).
- Zero changes to database schemas, SQL queries, or backend service contracts.
- In the event of an unexpected regression, individual phase files can be rolled back without affecting other components.
- Verification checkpoint after every task using `npm test` and browser visual proof.

**STATUS: READY FOR USER APPROVAL TO EXECUTE.**
