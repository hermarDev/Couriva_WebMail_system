# COURIVA Authentication UI/UX — Implementation Contract

Binding contract for `COURIVA_AUTH_UIUX_REDESIGN_PLAN.md` execution.

Base commit: `08c0c825f37ca1f5f9a7c1c98cd4296c37739a7e`
Integration branch: `feat/couriva-auth-redesign`
Integration worktree: `/home/hrmr/.hermes/cache/worktrees/couriva-auth-redesign`

## Verified stack and runtime

- Server-rendered PHP 8.x pages under `public/`; no SPA or framework.
- Bootstrap 5.3.3 CSS/JS from jsDelivr, locally bundled Bootstrap Icons, locally generated Tailwind utility layer, Inter from Google Fonts.
- Shared tokens live in `assets/css/style.css`; primary is `--primary: #2563EB`, dark primary is `#60A5FA`.
- Windows target: IIS 10 + PHP FastCGI (`web.config`), MariaDB/MySQL, Apache/LAMPP also supported locally.
- Existing test command: `npm test`. Existing build command: `npm run build:css`.
- PHP syntax check: `php -l <file>`.
- JS syntax check: `node --check <file>`.
- IIS config validation: `xmllint --noout web.config`.

## Audited backend contracts (must not change)

- Sign-in: `public/login.php` POST requires CSRF, calls `attempt_login()`, redirects to `public/inbox.php`, or to `public/change-password.php` when `must_change_password` is set.
- Authentication requires a full valid email (`FILTER_VALIDATE_EMAIL`). Bare usernames are rejected. No auto-appending at login.
- Registration: `public/register.php` POST requires CSRF, database-backed per-IP throttle (10 attempts / 15 minutes), username regex `^[a-z0-9][a-z0-9._-]{1,30}[a-z0-9]$`, password policy = at least 10 characters with uppercase, lowercase, number, symbol, exact confirmation match, and unique email.
- Current registration policy creates an **active** account and redirects to login with a success flash. Do not display pending/approval copy.
- Recovery: administrator-managed only. Repository has no public forgot/reset endpoint. UI must say access assistance is administrator-managed; do not create a dead or fictional link.
- Sessions, CSRF, rate limiting, redirects, LDAP/local identity rules, password hashing, and authorization remain untouched.

## Shared visual contract

- Restrained Swiss-inspired enterprise layout. No gradients, glassmorphism, glow, oversized illustrations, or new dependencies.
- Reuse existing tokens. Do not introduce a new palette. CTA uses `var(--primary)`.
- Page: `body.auth-page` becomes a full-height column; guest wrapper fills available height; `.auth-stage` centers content vertically and grows; global footer remains last and sits at viewport bottom on short pages, then flows after taller content.
- Card: opaque `var(--surface)`, 1px `var(--border)`, 16px radius, subtle shadow only. Max width 440px sign-in, 520px registration.
- Brand: existing `assets/brand/couriva-logo-black.svg` and `couriva-logo-white.svg`; no new logo. Wordmark remains visible in both themes.
- Navigation: keep one segmented switcher per screen. It must have 44px minimum targets, clear active state, and not be duplicated elsewhere on the same screen.
- Inputs: 46–48px height, visible labels at 13–14px, strong border/focus, normalized autofill background/text, right-side password toggle clearance, invalid state.
- Password visibility: button with accessible name and `aria-pressed`; icon changes between Eye/EyeOff. Preserve entered value.
- Secondary links: visible, readable, minimum 44px target where interactive. No placeholder recovery URL.
- Mobile: no horizontal overflow at 360/390px; registration names stack; suffix remains readable; footer follows content.
- 200% zoom and keyboard-only operation required. Focus rings visible. Reduced motion respected.
- Light and dark themes must both remain legible. Guest pages currently resolve to light on server and honor stored/system theme client-side; preserve this behavior.

## Frozen shared class/component names

Existing selectors workers may use:

- `.auth-page`
- `.auth-stage`
- `.auth-card`, `.auth-card-wide`
- `.auth-card-body`
- `.auth-nav-pills`, `.auth-nav-pill`, `.active`, `aria-current="page"`
- `.auth-heading`, `.auth-wordmark`
- `.auth-note`
- `.auth-submit`
- `.password-toggle`
- `.form-label`, `.form-control`, `.input-group`, `.input-group-text`, `.form-text`, `.alert`

New selectors may be added only by the owning worker for its scope:

- Agent A: `.auth-shell` helper classes only if needed; preferred solution is CSS-only.
- Agent B: `.auth-form-status`, `.auth-recovery-note`, `.auth-submit.is-loading`.
- Agent C: `.auth-password-rules`, `.auth-password-rule`, `.auth-password-rule.is-met`, `.auth-password-match`, `.auth-submit.is-loading`.

## Exact file ownership (no overlap)

### Agent A — shared design system / auth shell

May write only:

- `assets/css/style.css`

Responsibilities:

- Replace only the existing authentication CSS block with the restrained shared contract.
- Full-height shell, footer placement, brand/card/field/button/focus/autofill/theme states.
- Keep all non-auth CSS untouched.
- Do not edit PHP, JS, tests, generated Tailwind, header/footer, or configuration.

### Agent B — sign-in experience

May write only:

- `public/login.php`
- `assets/js/auth-signin.js` (new)
- `tests/auth_signin_ui_test.php` (new)

Responsibilities:

- Semantic sign-in hierarchy using frozen shared selectors.
- Welcome-back copy, accurate email label, password toggle, recovery note reflecting administrator-managed policy.
- Accessible loading/disabled submit state and server error rendering without changing backend behavior.
- `autocomplete="email"` and `autocomplete="current-password"` retained.
- Sign-in-specific regression test.

Forbidden: shared CSS, registration files, app-wide JS, backend/auth services, router, database, config.

### Agent C — registration experience

May write only:

- `public/register.php`
- `assets/js/auth-registration.js` (new)
- `tests/auth_registration_ui_test.php` (new)

Responsibilities:

- Registration hierarchy and responsive name fields.
- Username input shows local part only; visible `@couriva.local` suffix; server normalization retained.
- Live checklist derived exactly from existing policy; confirmation match feedback after interaction.
- Loading/disabled submit, error/success states consistent with **active account** policy.
- `autocomplete="username"` / `autocomplete="new-password"` retained.
- Registration-specific regression test.

Forbidden: shared CSS, sign-in files, app-wide JS, backend/auth services, router, database, config.

## Lead-owned files

- `AUTH_IMPLEMENTATION_CONTRACT.md`
- `AUTH_REDESIGN_IMPLEMENTATION_REPORT.md`
- `includes/header.php`, `includes/footer.php` only if a proven blocker requires it
- `assets/js/app.js` only if global integration requires it
- `tests/ui_ux_regression.php`
- `tests/auth_ui_ux_regression_test.php`
- `docs/screenshots/auth-redesign-*.png`

## Required worker evidence

Each worker must return and commit:

1. Commit hash and exact changed-file list.
2. Focused test command and exact result.
3. `php -l` / `node --check` results for owned source.
4. Screenshot path(s) at 1440x900 and 390x844 for its screen, captured from its own worktree.
5. Backend/security invariants checked.
6. Unresolved questions or caveats.

## Integration order

1. Agent A shared CSS.
2. Agent B sign-in template/script/test.
3. Agent C registration template/script/test.
4. Lead review, cherry-pick in order, run focused then full checks, browser QA, report.

No worker may merge, push, deploy, modify `main`, or change backend/security policy.
