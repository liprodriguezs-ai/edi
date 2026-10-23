# Tenant timezone + UTC storage

Objective: every tenant has its own IANA timezone; all instants are stored in UTC; the frontend converts for display/input; date/time logic is centralized (backend `TenantTime`, frontend `tenantTime.ts`).
Branch: `main` working tree, NO commits (479 pre-existing uncommitted files would be swept in; prior user directive "no-git working tree"). Delivery strategy: exception-ok (user commits).
Test runners: backend `DB_HOST=127.0.0.1 DB_PORT=5451 DB_USERNAME=rodriguez DB_PASSWORD=password php artisan test --compact <path>` (from `backend/`); frontend `pnpm exec vitest run`, `pnpm exec tsc -p tsconfig.app.json --noEmit` (from `frontend/`).

## Specs

- **S1** (L1, verbatim): "que cada tenant maneje su zona horaria". `tenant.timezone` (already exists, IANA string, default `America/Mexico_City`) is validated against `timezone_identifiers_list()` on provision AND update, and can be edited from the super-admin tenant form and the provisioning form (UI in Spanish). Assumption: tenant self-service editing is out of scope (no configuration module exists today) — follow-up.
- **S2** (L1, verbatim): "la fecha se guarde en utc". `APP_TIMEZONE=UTC`, pgsql connection timezone `UTC`. Every instant column (`timestamp without time zone`) holds UTC.
- **S3** (L2, user decision): existing data must be converted. A data migration converts every `timestamp without time zone` column from a configured source zone (`LEGACY_STORAGE_TIMEZONE`, e.g. `America/Mexico_City`) to UTC using Postgres tz rules (handles pre-2022 Mexican DST). No-op when the source zone is unset or `UTC`. Reversible `down()`.
- **S4** (L1, verbatim): "en el front hagamos la conversion". The API returns instants as ISO-8601 UTC (`...Z`); the frontend renders them in the tenant zone, which it reads from the session (`/auth/me` → `tenant.timezone`). Date-only fields (birth_date, visit_date, due_date, session_date, applied_at, enrolled_at, referred_at, ...) are civil dates `YYYY-MM-DD`, never shifted by any zone, and are serialized by the API as `YYYY-MM-DD`.
- **S5** (L1, verbatim): "necesitamos centralizar todo". Backend: one stateless helper `QCH\Core\Support\TenantTime` (zone, today, dayBounds, month helpers, wallTime) — no per-request global timezone change. Every server-side "today"/"current month" (validation `before_or_equal:today`, follow-up overdue, indicator periods, scheduled reports, enrolment defaults) uses the tenant zone. Frontend: one module `shared/utils/tenantTime.ts` (temporal-polyfill) + `dateHelper.ts` delegating to it; no raw `new Date()`/`toISOString()` date math outside the helpers.
- **S6**: docs — AGENTS.md hard rule + `.project-context/conventions.md` describe the convention.

## Tasks

| ID | Specs | Route | Status | Evidence |
|---|---|---|---|---|
| T1 | S2, S3, S5 (backend core: TenantTime, config, data migration) | delegated (writer reason: parallel units backend/frontend) | done | TenantTime 22 unit tests, conversion 6/6, full suite 939 tests / 4 pre-existing menu 403 failures |
| T2 | S1, S4, S5 (backend: tenant tz validation, today logic, date serialization) | delegated (same backend writer, after T1) | done | tz validation 6/6, civil-today visit 422/201 at 03:00Z, birth_date serializes Y-m-d |
| T3 | S1, S4, S5 (frontend: tenantTime.ts, session tz, dateHelper, raw usages, tz select) | delegated (writer reason: parallel units) | done | tsc 0, build 0, vitest 332/333 (1 pre-existing: instrument/InstrumentVersionDetailPage expects 'Cargando…' vs PageLoader, untracked module); parent spot-check shared/utils+tenant 89/89 |
| T4 | S2, S3 (dev env: .env switch, DB backup, run migration) | inline (parent) | done | backup `../edi-app-backups/edi-before-utc-20261020185918.dump`; .env APP_TIMEZONE=UTC + LEGACY_STORAGE_TIMEZONE=America/Mexico_City; ran only the conversion migration via --path (4 appointment migrations left pending); user.created_at 16:44→22:44, child.birth_date/visit.visit_date md5 identical |
| T5 | S6 docs | inline (parent) | done | AGENTS.md rule 9, conventions.md "Dates, times and tenant timezone" |

## Log

- **L1** (2026-10-20, user, verbatim): "necesito que corrijamos la zona horaria si sabes como?? que cadat tenant maneje su zona horaria, y la fecha se guarde en utc, y en el front hagamos la conversion necesitamos centralizar todo, algo de esto hay en el proyecto de whatsapp /Users/rodriguez/code/whatspapp"
- **L2** (2026-10-20, user answer): "¿Hay datos guardados que haya que conservar ... con fechas en hora de México?" → "Sí, hay que convertir".
- L3 evidence: `.env` has `APP_TIMEZONE="America/Mexico_City"`, `.env.testing` UTC; 0 `timestampTz` columns; `tenant.timezone` exists and is in `/auth/me` payload but frontend `SessionTenant` lacks it; 12 `before_or_equal:today` rules + `Carbon::today()`/`now()->startOfMonth()` use global zone; frontend Dashboard/Indicators compute month via `toISOString().slice(0,7)` (UTC). Reference implementation: whatspapp `backend/core/src/Support/TenantTime.php`, `frontend/src/shared/utils/tenantTime.ts` (stores Mexico wall clock — we deliberately store UTC instead).
- Risk: items 1 (stored-data conversion), 3 (API date serialization format), 5 (new dependency temporal-polyfill).
- L4 (2026-10-20): writers done; independent verifier launched (Risk items 1, 3, 5). TenantController::performUpdate now runs TenantUpdateRequest (previously unvalidated). FollowUp overdue schedule daily→hourlyAt(15).
- L5 (2026-10-20): independent verifier: S1–S6 PASS, 0 blockers; backend focused 108 passed, frontend tsc 0 / vitest 340 + 1 pre-existing instrument failure. Advisory fixed: Visit/StimulationAttendance/StimulationGroupMember factories now use TenantTime::today(Tenant::DEFAULT_TIMEZONE) (ChildRecord+Stimulation tests 70 passed). Open advisories: pre-conversion datetimes inside `audits` JSON keep Mexico wall clock (render ~6h early in AuditDiffModal); exact 00:00Z instants render as civil date in 'mediumDate'. Follow-ups: tenant self-service timezone setting (no configuration module); `core` ReportController filenames/MySQL-only birthday filter; prod needs LEGACY_STORAGE_TIMEZONE set BEFORE migrate. Not committed (pre-existing dirty tree).
