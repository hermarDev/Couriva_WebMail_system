# Couriva WebMail — fresh E2E evidence and requested fixes

Captured: 2026-11-26 ~09:30 +0800. Target: http://172.20.10.2/public/login.php
Session used: User A `seno@couriva.com` (password unchanged). Tool: agent-browser 0.44.0.
Red boxes in screenshots are `agent-browser highlight` overlays marking the exact failing elements.

## Fix 1 — CRITICAL: checkboxes have no accessible name (axe `label`, 2 nodes)

- Screenshot: `05-a11y-checkbox-no-label.png`
- Failing nodes: `input[value="71"]`, `input[value="51"]` — class `.email-select-checkbox` in the inbox message list (`public/inbox.php`).
- Expected: each checkbox has a programmatic name.
- Fix: add a unique `id` and visually-hidden `<label for="...">Select message from <sender> — <subject></label>`, or `aria-label="Select message from <sender> — <subject>"`. Do not rely on the surrounding row text.
- Note: "Select all emails" checkbox already has a name; these per-row ones do not.

## Fix 2 — SERIOUS: low text contrast (axe `color-contrast`, 2 nodes)

- Screenshots: `06-a11y-snippet-contrast.png` (snippet), `07-a11y-date-contrast.png` (date).
- Failing nodes:
  - `.mail-subject-snippet > .mail-snippet` — e.g. "— Reply All body E2E_REPLYALL_074225"
  - `.read > .mail-date.me-2` — e.g. "Nov 25"
  (both in the inbox row; the selected row has a tinted background, worsening the ratio)
- Expected: >= 4.5:1 against the actual row background in light and dark themes.
- Fix: darken the snippet/date text tokens in `assets/css/style.css`, or lighten the row background; verify both themes.

## No action needed (re-verified fixed)
- Security headers: single CSP (nonce) and one each of nosniff/frame-options/referrer-policy/permissions-policy — `08-security-headers-current.txt`.
- Category tabs: Primary shows messages, Promotions/Updates empty (intentional UI-only views per presentation slide A01) — `02`, `03`, `04`.
- Login page axe: 0 violations — `login-a11y-current.json`.

## Verify after fix
1. Log in as User A, open `/public/inbox.php`.
2. `agent-browser --session e2e-user-a a11y --json` -> `violations: 0`.
3. Re-capture the inbox screenshot; confirm the red-boxed items now pass.

Full JSON evidence: `inbox-a11y-current.json`, `login-a11y-current.json`.
Earlier full report: `/tmp/opencode/couriva-e2e/report.md`.
