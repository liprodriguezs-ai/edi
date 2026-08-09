# Plan 067: Docs & index hygiene — brand name, module reality, .env.example, CORS, plans index

> **Executor instructions**: Follow step by step; verify each step. This is a
> docs/config-only plan — no application logic changes. Update `plans/README.md`
> when done (this plan also edits `plans/README.md` for hygiene — see Step 5).
>
> **Drift check (run first)**:
> `git -C .. diff --stat -- CLAUDE.md .project-context/modules.md` (monorepo root) and
> confirm the lines below still read as quoted.

## Status

- **Priority**: P2
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: docs
- **Planned at**: monorepo `b2c06dc`, backend `8117e66`, frontend `8f3675b`, 2026-07-01

## Why this matters

Several always-loaded reference docs are actively wrong, which misleads every agent (and
human) who trusts them — including this audit, which was handed them verbatim:

1. Root `CLAUDE.md` — the first file loaded into every session — names the product
   **"Vecinia"** (lines 5 and 99), but the live brand is **Kivo7** end-to-end (Firebase
   `kivo7-475fa`, `index.html`, `appConfig.brandName`) and `.project-context/domain.md:3`
   (which `CLAUDE.md:5` itself points to) opens with "Kivo7."
2. `.project-context/modules.md:39` heads a list "Kivo7 Functional Modules (12 — to be
   built)" — every one of those 12 is BUILT (Board, Collections, Treasury, Providers,
   Projects, Announcements, Chat, Access, Reports, Configuration all present and
   non-trivial), and the doc never mentions three shipped modules: Package, Tracking,
   DataImport (nor UserProfile as its own).
3. `.env.example` is missing the Tracking vars and the billing kill-switch (an operator
   can't discover them): `FACEBOOK_PIXEL_ID`, `FACEBOOK_CAPI_ACCESS_TOKEN`,
   `FACEBOOK_TEST_EVENT_CODE`, `GA_MEASUREMENT_ID`, `GA_API_SECRET` (read by
   `config/services.php:55–66`), and `BILLING_ENFORCE_SUBSCRIPTION` (read by
   `config/billing.php:22`, its own comment at :15 calls it "an incident kill-switch").
   (Also `TRACKING_RETENTION_DAYS` if plan 062 lands.)
4. `FACEBOOK_TRACKING.md:65-67` / `GOOGLE_TRACKING.md:64-66` document `CORS_ALLOWED_ORIGINS`
   as a manual prod step; `config/cors.php:22` confirms the default does NOT include the
   production landing domains — if that step is skipped, tracking POSTs fail via CORS
   silently (browser-only error).
5. `plans/README.md` is out of sync: plan 048 (personal rotating "Mi QR") is fully
   implemented but has no status row; two files are both numbered 040
   (`040-access-relation-key-drift.md` and `040-catalog-seeder-idempotent.md`); and the
   ciclo-4 "Direction options" still lists camera-based QR scanning as unbuilt, but
   `html5-qrcode` is installed (`frontend/package.json`) and used in
   `frontend/src/modules/access/components/ScanQrModal.tsx`.
6. ~~`backend/modules_statuses.json:31` enables `"AmenityReservations": true`~~ — **DO NOT
   TOUCH.** The owner confirmed (2026-07-02) this flag is DELIBERATE: it is a placeholder
   for the Amenity Reservations module they are about to build (spec is ready at
   `openspec/changes/amenity-reservations/`). It is NOT dead config. Leave it enabled.

## Current state

- `CLAUDE.md:5` — `**Product**: **Vecinia** — plataforma SaaS multi-tenant...`;
  `CLAUDE.md:99` — "...the Vecinia functional modules (Housing, Collections, Treasury,
  Board, etc.)".
- `.project-context/modules.md:39` — `### Kivo7 Functional Modules (12 — to be built)`.
- `backend/config/services.php` (≈55–66) reads the 5 tracking env keys; `.env.example` lacks
  them (verify: `grep -n "FACEBOOK_\|GA_MEASUREMENT\|GA_API_SECRET\|BILLING_ENFORCE" backend/.env.example`).
- `backend/config/cors.php:22` — `CORS_ALLOWED_ORIGINS` default excludes prod landing.
- `backend/modules_statuses.json:31` — `"AmenityReservations": true`.

## Commands you will need

| Purpose | Command | Expected |
|---------|---------|----------|
| Find stray brand | `grep -rn "Vecinia" CLAUDE.md .project-context docs --include=*.md \| grep -v archive` | after: no active-doc hits |
| Verify env gap | `grep -n "FACEBOOK_\|GA_API_SECRET\|BILLING_ENFORCE" backend/.env.example` | after: keys present |
| JSON valid | `cd backend && php -r "json_decode(file_get_contents('modules_statuses.json'), true, 512, JSON_THROW_ON_ERROR); echo 'ok';"` | `ok` |

## Scope

**In scope** (docs/config only):
- `CLAUDE.md` (brand name, 2 spots)
- `.project-context/modules.md` (flip "to be built" → built; add Package/Tracking/DataImport/UserProfile)
- `backend/.env.example` (add the 5 tracking keys + `BILLING_ENFORCE_SUBSCRIPTION` + `TRACKING_RETENTION_DAYS` with placeholder/comment values — NEVER real secrets)
- ~~`backend/modules_statuses.json`~~ — OUT OF SCOPE now: leave `AmenityReservations: true` as-is (intentional placeholder, owner-confirmed)
- `plans/README.md` (add 048 row; note the duplicate-040; strike the stale camera-scan bullet)
- Optionally a boot-time warning if `CORS_ALLOWED_ORIGINS` still equals the localhost/
  staging default while `APP_ENV=production` (small, in a service provider) — OPTIONAL; if
  it grows beyond a few lines, defer and just fix the docs.

**Out of scope**:
- Archived docs under `openspec/changes/archive/**` (historical).
- Application logic; the actual ConfigurationController refactor (separate index item).
- Renaming the committed duplicate-040 plan FILES (churn on done plans) — just disambiguate
  the references in the index text.

## Steps

### Step 1: Fix the brand name

Change `CLAUDE.md:5` and `CLAUDE.md:99` "Vecinia" → "Kivo7". Then
`grep -rn "Vecinia" CLAUDE.md .project-context docs --include=*.md | grep -v archive` and
fix any remaining active-doc hits.

**Verify**: the grep returns no active-doc hits.

### Step 2: Correct modules.md

Reframe the "12 — to be built" section as built (a table with status), add rows for
Package, Tracking, DataImport, and UserProfile, and move the genuinely-future items (already
listed under "Not in v1.0" in the same file) into a clearly separate roadmap section.

**Verify**: every module in `backend/Modules/` is represented; no built module is described
as "to be built."

### Step 3: Complete .env.example

Add, with placeholder values and a short comment each (NO real secrets):
`FACEBOOK_PIXEL_ID`, `FACEBOOK_CAPI_ACCESS_TOKEN`, `FACEBOOK_TEST_EVENT_CODE`,
`GA_MEASUREMENT_ID`, `GA_API_SECRET`, `BILLING_ENFORCE_SUBSCRIPTION=true` (with the
kill-switch comment from `config/billing.php:15`), and `TRACKING_RETENTION_DAYS` (if plan
062 landed).

**Verify**: `grep -n "FACEBOOK_\|GA_API_SECRET\|BILLING_ENFORCE" backend/.env.example` shows the keys.

### Step 4: modules_statuses.json — LEAVE `AmenityReservations` AS-IS

DO NOT remove or disable `"AmenityReservations": true`. The owner confirmed it is an
intentional placeholder for the module being built next (spec ready at
`openspec/changes/amenity-reservations/`). This step is intentionally a no-op — do not
touch `modules_statuses.json`.

### Step 5: Fix the plans index hygiene

In `plans/README.md`: add a status row for plan 048 (DONE — personal rotating Mi QR, backend
`AccessService::resolveMyQr`/`handlePersonalScan` + `AccessLogController::myQr` +
`GET access/my-qr` + frontend `MyQrPage`); add a one-line note that two files share number
040 (access-relation-key-drift vs catalog-seeder-idempotent — both DONE, kept as-is); and
strike/update the ciclo-4 "Direction options" camera-scan bullet (html5-qrcode is installed
and used in `ScanQrModal.tsx`).

**Verify**: `grep -n "048" plans/README.md` returns the new row.

### Step 6 (optional): CORS prod boot-warning

If quick, add a boot check (service provider) that logs a warning when
`config('cors.allowed_origins')` still equals the localhost/staging default and
`app()->environment('production')`. If it's more than a few lines, DEFER and note it.

## Done criteria

- [ ] No active doc says "Vecinia"
- [ ] modules.md reflects the 30 built modules; no built module marked "to be built"
- [ ] `.env.example` documents the tracking keys + `BILLING_ENFORCE_SUBSCRIPTION` (+ retention)
- [ ] `modules_statuses.json` left untouched — `AmenityReservations: true` stays (intentional placeholder)
- [ ] `plans/README.md` has a 048 row, the dup-040 note, and no stale camera-scan claim
- [ ] No real secret values anywhere (placeholders/comments only)
- [ ] `plans/README.md` status row for THIS plan (067) updated

## STOP conditions

- Any file's content diverges materially from the quoted lines (drift) — re-locate before editing.
- You find a real secret currently committed in `.env.example` or elsewhere while editing —
  STOP, do NOT paste its value anywhere, report it (type + location) and recommend rotation.

## Maintenance notes

- These docs are injected into every agent session — keeping them accurate has outsized
  leverage. Re-check `.project-context/*` whenever a module ships.
- Reviewer: confirm no secret value landed in `.env.example` (placeholders only).
- The ConfigurationController god-controller split and a frontend-CI job are tracked as
  separate index items, not here.
