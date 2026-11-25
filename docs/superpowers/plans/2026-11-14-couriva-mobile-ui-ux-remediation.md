# Couriva WebMail — Mobile UI/UX Remediation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or Herdr multi-agent parallel execution. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remediate mobile UI/UX and responsive defects across Couriva WebMail (<768px viewports: 360px Galaxy S23, 390px iPhone 14/15, 430px iPhone 14 Pro Max) based on Impeccable audit findings without regressing existing desktop suites.

**Architecture:** Maintain strict decoupled file ownership across 3 parallel agents:
- Agent A (Designer): `assets/css/style.css` (CSS Grid attachment area, action bar horizontal overflow, full-screen mobile docked compose, registration card viewport bounding)
- Agent B (Builder 1): `public/inbox.php`, `public/read.php`, `public/compose.php` (compose toolbar flex-nowrap rail, paperclip markup alignment, action bar selection visibility, mobile read action bar 3-dot overflow menu, pagination aria-labels)
- Agent C (Builder 2): `includes/header.php`, `includes/footer.php`, `assets/js/app.js` (mobile Compose FAB, mobile list-mode email row tap delegation, category/search filter synchronization)

**Tech Stack:** PHP 8.2, Bootstrap 5.3.3, Vanilla ES6 JavaScript, Custom CSS Tokens, MariaDB 10.4.

---

## Global Constraints

- Preserve all existing tests in `npm test`: zero test regressions permitted.
- Minimum 44×44px touch targets on all interactive controls (`button`, `a`, `input`, `.form-check-input`).
- Zero horizontal document overflow (`scrollWidth <= innerWidth`) on viewports 360px, 390px, 430px, 768px.
- Full compatibility with both Light (`[data-bs-theme="light"]`) and Dark (`[data-bs-theme="dark"]`) themes.
- Touch interactions: allow smooth horizontal scrolling (`-webkit-overflow-scrolling: touch; scrollbar-width: none;`) where horizontal toolbars/tabs are used.

---

## File Ownership Matrix (No Overlaps)

| Agent | Profile | Role | Assigned Files |
|-------|---------|------|----------------|
| **Agent A** | `designer` | CSS & Responsive Layout | `assets/css/style.css` |
| **Agent B** | `builder` | Mailbox Views & Templates | `public/inbox.php`, `public/read.php`, `public/compose.php` |
| **Agent C** | `builder` | Shell, Navigation & Scripts | `includes/header.php`, `includes/footer.php`, `assets/js/app.js` |

---

## Task 1: CSS Grid & Mobile Layout Remediation (Agent A — Designer)

**Files:**
- Modify: `assets/css/style.css`
- Test: `npm test` & `browser_exec` DOM checks

**Interfaces:**
- Consumes: Existing `.mail-item-row`, `#gmailActionBar`, `#dockedComposeContainer`, `.auth-card-wide` selectors
- Produces: Corrected `.mail-item-row .mail-attachment-icon` grid-area, `#gmailActionBar` mobile overflow prevention, mobile full-screen compose container, and bounded `.auth-card-wide` height

- [ ] **Step 1: Fix Mobile Email Row CSS Grid Attachment Icon Displacement**
  In `assets/css/style.css:1735-1798`:
  Define `.mail-item-row .mail-attachment-icon` (or `.mail-item-row > span.text-muted`) with `grid-area: date; margin-right: 2.2rem !important; justify-self: end; font-size: 0.85rem;` so attachment paperclips share the date column cleanly without triggering implicit grid rows.

- [ ] **Step 2: Fix Action Bar Overflow During Selection on Mobile**
  In `assets/css/style.css:3060-3075`:
  Under `@media (max-width: 575.98px)`:
  - Add `#contextualActionsBar { max-width: calc(100vw - 80px); overflow-x: auto; -webkit-overflow-scrolling: touch; scrollbar-width: none; }`
  - When `.selection-active` or `#contextualActionsBar:not(.d-none)` is active on mobile, hide `.action-bar-right` or allow smooth scroll so pagination is not awkwardly distorted.

- [ ] **Step 3: Fix Default Docked Compose Mobile Geometry**
  In `assets/css/style.css:2660-2680`:
  Under `@media (max-width: 767.98px)`:
  Override `#dockedComposeContainer`:
  `inset: 0 !important; width: 100vw !important; height: 100dvh !important; max-width: 100% !important; border-radius: 0 !important; right: 0 !important; bottom: 0 !important;`

- [ ] **Step 4: Bound Registration Card Dynamic Viewport Height**
  In `assets/css/style.css:2365-2415`:
  Under `@media (max-width: 767.98px)`:
  Ensure `.auth-card.auth-card-wide` has `max-height: min(90dvh, calc(100dvh - 2rem)); display: flex; flex-direction: column;`.
  Ensure `.auth-card-wide .auth-card-body` has `overflow-y: auto; overscroll-behavior: contain;`.

- [ ] **Step 5: Verify CSS changes**
  Run `php -l assets/css/style.css` (or node check) and `npm test`.

---

## Task 2: Mailbox Views & Template Modernization (Agent B — Builder 1)

**Files:**
- Modify: `public/inbox.php`, `public/read.php`, `public/compose.php`
- Test: `npm test` & focused template assertions

**Interfaces:**
- Consumes: Bootstrap 5.3 utilities, Couriva CSS tokens
- Produces: Paperclip class hook `.mail-attachment-icon`, horizontal compose toolbar rail, responsive read view action bar with 3-dot dropdown, corrected pagination aria-labels

- [ ] **Step 1: Assign Explicit Class to Attachment Paperclip in `inbox.php`**
  In `public/inbox.php:387-392`:
  Change `<span class="text-muted me-3"><i class="bi bi-paperclip"></i></span>` to `<span class="text-muted mail-attachment-icon me-1" title="Has attachments"><i class="bi bi-paperclip"></i></span>`.

- [ ] **Step 2: Fix Pagination Chevron Aria Labels in `inbox.php`**
  In `public/inbox.php:250-255`:
  Change chevron-left link: `title="Newer" aria-label="Newer emails"`.
  Change chevron-right link: `title="Older" aria-label="Older emails"`.

- [ ] **Step 3: Fix Filter Panel Auto-Expansion on Query in `inbox.php`**
  In `public/inbox.php:105`:
  Include `!empty($criteria['q'])` in the collapse condition:
  `class="collapse <?= (!empty($criteria['q']) || !empty($criteria['date_from']) || !empty($criteria['date_to']) || $criteria['has_attachment'] !== '' || $criteria['is_unread'] !== '') ? 'show' : '' ?> mb-3 d-md-none" id="filterPanel"`
  Also add `<input type="hidden" name="category" value="<?= e($activeCategory) ?>">` inside `#filterPanel` form so category is preserved on filter submit.

- [ ] **Step 4: Streamline Compose Formatting Toolbar in `compose.php`**
  In `public/compose.php:375`:
  Change `class="editor-toolbar p-2 bg-body-tertiary border-bottom d-flex flex-wrap align-items-center gap-1 flex-column flex-md-row"` to:
  `class="editor-toolbar p-2 bg-body-tertiary border-bottom d-flex align-items-center gap-1 flex-nowrap overflow-x-auto"` with `style="scrollbar-width: none;"`.

- [ ] **Step 5: Consolidate Mobile Action Bar in `read.php`**
  In `public/read.php:215-285`:
  On mobile (<768px), keep primary actions (Back, Star, Reply, Trash) visible and consolidate secondary actions (Print, Snooze, Spam, Move to folder, Labels) into a responsive 3-dot dropdown (`.d-md-none dropdown`) while keeping full button row on `d-none d-md-flex`.

- [ ] **Step 6: Verify template changes**
  Run `php -l public/inbox.php && php -l public/read.php && php -l public/compose.php && npm test`.

---

## Task 3: Shell, Navigation & Mobile Interactivity (Agent C — Builder 2)

**Files:**
- Modify: `includes/header.php`, `includes/footer.php`, `assets/js/app.js`
- Test: `npm test` & browser interaction checks

**Interfaces:**
- Consumes: Mobile event listeners in `app.js`, layout containers in `header.php`/`footer.php`
- Produces: Mobile Floating Compose Action Button (FAB), full-row click delegation in list mode on mobile, dismissal button in mobile drawer

- [ ] **Step 1: Add Mobile Floating Action Button (FAB) for Compose**
  In `includes/footer.php` (or `public/inbox.php`):
  Add an accessible mobile compose FAB rendered only for authenticated users:
  ```html
  <?php if (is_authenticated()): ?>
  <button type="button" class="btn btn-primary rounded-circle shadow-lg d-md-none btn-compose-fab position-fixed" id="mobileComposeFab" aria-label="Compose email" title="Compose email" style="bottom: 20px; right: 20px; width: 56px; height: 56px; z-index: 1040;">
      <i class="bi bi-pencil-fill fs-5"></i>
  </button>
  <?php endif; ?>
  ```

- [ ] **Step 2: Wire Mobile Compose FAB in `app.js`**
  In `assets/js/app.js`:
  Bind `#mobileComposeFab` click event to invoke `openDockedCompose()` or trigger `#btnOpenCompose.click()`.

- [ ] **Step 3: Enable Whole-Row Click Navigation on Mobile List Mode**
  In `assets/js/app.js:1913-1940`:
  Extend the click listener on `.mail-item-row`:
  If `window.innerWidth < 768` and `currentMode !== 'split'`, clicking the row (outside of `.form-check`, `.star-btn`, and `.label-chip`) navigates to `read.php?id=` + emailId.

- [ ] **Step 4: Add Mobile Drawer In-Header Dismiss Button**
  In `includes/header.php:427-435`:
  Ensure the mobile drawer `#sidebarMenu` contains a visible close button (`<button type="button" class="btn-close d-md-none" data-bs-toggle="collapse" data-bs-target="#sidebarMenu" aria-label="Close navigation"></button>`).

- [ ] **Step 5: Verify shell & JS changes**
  Run `php -l includes/header.php && php -l includes/footer.php && npm test`.

---

## Verification & Acceptance

1. **Automated Test Suite**:
   `npm test` passes with 100% green assertions.
2. **Deterministic Browser Checks**:
   - iPhone 14 Pro (430×932), iPhone 14 (390×844), Galaxy S23 (360×800):
   - Document `scrollWidth <= innerWidth` across all pages.
   - All interactive touch targets >= 44×44px.
   - Compose toolbar fits on a single horizontal scrolling row on mobile.
   - Mobile FAB is visible on Inbox and opens compose.
   - Selecting emails does not push pagination or action bar offscreen.
