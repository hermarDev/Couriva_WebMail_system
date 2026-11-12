# Couriva WebMail — Responsive UI/UX Fix Implementation Contract

**Branch:** main (d8fda68)  
**Agents:** 4 parallel (A=Designer, B=Builder, C=Builder, D=Builder)  
**Shared Invariants:**
- Bootstrap 5.3.3 grid semantics
- CSS custom properties (--primary, --surface, etc.)
- All existing test assertions (npm test)
- CSRF, auth, rate-limit, session epoch logic
- IIS web.config compatibility

---

## File Ownership Matrix (No Overlaps)

| Agent | Profile | Exclusive Files |
|-------|---------|-----------------|
| A | Designer | `assets/css/style.css` (lines 1436-1444, 2306-2341, 2393-2427, 2563-2633, 2970-2979) |
| B | Builder | `public/settings.php` |
| C | Builder | `includes/header.php`, `includes/footer.php`, `assets/js/app.js` |
| D | Builder | `public/inbox.php`, `public/compose.php`, `public/read.php`, `public/contacts.php` |

---

## Agent A — Layout & Container (Designer)

**Scope:** `assets/css/style.css` ONLY

### Acceptance Criteria
- [ ] No horizontal scroll at 360px, 390px, 768px, 1440px, 200% zoom
- [ ] Containers centered with `max-width: 1080px`
- [ ] Responsive main content padding: `px-3 px-md-4` in header/footer
- [ ] Sidebar drawer: focus trap, ESC close, backdrop click-to-close
- [ ] Docked compose mobile: focus trap, backdrop dismiss
- [ ] Add 360px/390px breakpoints for auth, inbox, compose

### Changes Required

1. **`.settings-container`, `.settings-wrapper` (lines 1438-1444)**
   - Change `max-width: 1680px` → `max-width: 1080px`
   - Change `margin-left: 0; margin-right: auto` → `margin: 0 auto`
   - Add `padding: 0 1rem`

2. **Auth mobile styles (lines 2306-2341)**
   - Add `@media (max-width: 390px)` breakpoint for tighter auth card padding
   - Add `@media (max-width: 360px)` breakpoint for minimal auth card padding
   - Ensure no horizontal overflow on iPhone SE (375px) / Galaxy S23 (360px)

3. **Sidebar drawer (lines 2393-2427)**
   - Add focus trap on open (CSS `:focus-within` + JS in Agent C)
   - Backdrop already exists (`body.sidebar-open::before`), ensure click-to-close works
   - ESC close already in JS, verify

4. **Docked compose mobile (lines 2563-2633)**
   - Add focus trap on open
   - Backdrop click-to-dismiss on mobile
   - Ensure 360px/390px breakpoints handle full-screen compose

5. **Quick Settings drawer (line 1448)**
   - Change `width: 350px !important` → `width: min(350px, 90vw) !important`

6. **Global responsive main content padding**
   - Add utility class or update container to use `px-3 px-md-4` pattern

### Verification
```bash
php -l assets/css/style.css  # No syntax errors
npm test                      # All suites PASS
```

---

## Agent B — Settings Page UX (Builder)

**Scope:** `public/settings.php` ONLY

### Acceptance Criteria
- [ ] Settings tabs work with hash nav: `#profile`, `#security`, `#organization`
- [ ] Signature preview collapsible by default (accordion)
- [ ] Password rule pills: `0.72rem` → `0.8125rem` (13px minimum)
- [ ] CRUD buttons have text labels ("New label", "New folder")
- [ ] Profile card: avatar 40px, `p-2` padding

### Changes Required

1. **Section Tabs (Profile, Security, Organization)**
   - Replace single form with three tab panels using Bootstrap nav-pills
   - Hash-based navigation: `#profile`, `#security`, `#organization`
   - Profile tab: first name, last name, email (readonly), timezone, signature, theme
   - Security tab: password change form with strength meter
   - Organization tab: labels, folders, blocked senders (existing tab structure)

2. **Signature Preview Collapsible**
   - Default collapsed (remove `show` class from collapse)
   - Toggle button with `aria-expanded="false"` initially

3. **Password Rule Pills**
   - Update CSS for `#passwordRulesPills .rule-pill`: `font-size: 0.8125rem` (13px)
   - Ensure min-height for touch targets

3. **CRUD Buttons with Text Labels**
   - "New label" instead of "+ New label"
   - "New folder" instead of "+ New folder"
   - Keep icons but add text

4. **Profile Card**
   - Avatar: `width: 40px; height: 40px; font-size: 1.1rem`
   - Card body padding: `p-2` (reduce from `p-3`)

### Verification
```bash
php -l public/settings.php
php tests/auth_registration_ui_test.php
```

---

## Agent C — Mobile Nav & Sync (Builder)

**Scope:** `includes/header.php`, `includes/footer.php`, `assets/js/app.js` ONLY

### Acceptance Criteria
- [ ] Header action buttons: 36px → 44px (`.webmail-header-btn`)
- [ ] Quick Settings drawer: width `min(350px, 90vw)`
- [ ] Theme sync: Navbar icon + Quick Settings + Settings radios all update together
- [ ] Sidebar drawer a11y: focus trap, ESC, backdrop (CSS in Agent A, JS handlers here)
- [ ] Docked compose mobile a11y: focus trap, backdrop click-to-close

### Changes Required

**`includes/header.php`:**
1. **Header buttons** (lines 209, 217, 222, 229, 266): Change `style="width: 36px; height: 36px"` → `style="width: 44px; height: 44px"` and `class="btn btn-sm"` → `class="btn btn-sm"` (keep btn-sm, adjust via CSS)
2. **Quick Settings offcanvas** (line 311): Change `style="width: 340px"` → `style="width: min(350px, 90vw)"`
3. **Theme toggle button** (line 222): Ensure `aria-label` updates dynamically

**`includes/footer.php`:**
1. **Docked compose** (line 16): Add `tabindex="-1"` and focus management attributes
2. **Quick Settings drawer** already in header.php

**`assets/js/app.js`:**
1. **Sidebar drawer focus trap** (lines 58-101): Add focus trap logic when sidebar opens
   - Capture focus on open, restore on close
   - Tab cycling within sidebar only
2. **Docked compose focus trap**: Add similar focus trap for mobile full-screen compose
3. **Theme sync** (lines 567-661): Verify `applyTheme()` updates:
   - Navbar theme toggle icon + label (✓ already)
   - Quick Settings theme cards (✓ already)
   - Settings page radios `.settings-theme-radio` (✓ already)
   - Add verification that all three sync on any change
4. **Backdrop click handlers**: Ensure sidebar and compose dismiss on backdrop click (already in lines 79-83, verify)

### Verification
```bash
php -l includes/header.php
php -l includes/footer.php
# No syntax check for JS, but ensure no JS errors in browser console
```

---

## Agent D — Cross-Page Responsive (Builder)

**Scope:** `public/inbox.php`, `public/compose.php`, `public/read.php`, `public/contacts.php` ONLY

### Acceptance Criteria
- [ ] Inbox action bar stacks on mobile, shows text labels for batch actions
- [ ] Compose editor toolbar wraps responsively
- [ ] Recipient chips horizontal scroll on mobile
- [ ] Read page attachments: `col-6 col-sm-6 col-md-4 col-lg-3`
- [ ] Contacts forms: `col-sm-6 col-md-6` grid

### Changes Required

**`public/inbox.php`:**
1. **Action bar** (lines 160-252): Make responsive
   - Mobile: stack left/right sections, show text labels next to icons for batch actions
   - Use `d-flex flex-wrap` and `flex-column` on mobile
   - Add `d-none d-sm-inline` for text labels on batch buttons

**`public/compose.php`:**
1. **Editor toolbar** (lines 380-420): Add `flex-wrap` to `.editor-toolbar`
2. **Recipient chips** (lines 330-367): Add horizontal scroll container on mobile
   - `.recipient-chips-container { overflow-x: auto; }` on mobile
3. **Field rows**: Ensure labels stack above inputs on mobile

**`public/read.php`:**
1. **Attachments grid** (lines 482-500): Change `col-sm-6 col-md-4 col-lg-3` → keep but verify
   - Current: `col-sm-6 col-md-4 col-lg-3` ✓ already correct
   - Ensure no overflow on 360px

**`public/contacts.php`:**
1. **Form grid** (lines 183-191, 226-234): Change `col-md-6` → `col-sm-6 col-md-6`
   - Phone/Company fields stack on mobile, side-by-side on tablet+

### Verification
```bash
php -l public/inbox.php
php -l public/compose.php
php -l public/read.php
php -l public/contacts.php
npm test  # Full suite
```

---

## Integration & Verification (Lead)

1. Wait for all 4 DONE messages
2. Run full verification:
   ```bash
   cd /opt/lampp/htdocs/Web_Mail_System
   npm test
   php tests/auth_signin_ui_test.php
   php tests/auth_registration_ui_test.php
   php tests/p1_regression_test.php
   php tests/ui_ux_regression.php
   ```
3. Visual spot-check at: 360px, 390px, 768px, 1440px, 200% zoom
   - Pages: login, register, settings, inbox, compose, read
4. Single commit, push to origin/main
5. Cleanup worker tabs

---

## Blocker Escalation
If any agent reports coordination conflict or test failure they cannot resolve:
- STOP that agent
- Report exact error to Lead
- Lead adjudicates and reassigns scope

---

## Success Definition
- ✅ `npm test` exits 0
- ✅ No horizontal scroll at 360px/390px/768px/1440px/200% zoom
- ✅ All 6 critical audit issues resolved
- ✅ Main branch updated, pushed to origin
- ✅ Worker tabs cleaned up