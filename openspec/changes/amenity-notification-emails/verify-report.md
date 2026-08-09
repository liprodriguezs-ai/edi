# Verification Report — amenity-notification-emails

**Change**: `amenity-notification-emails`
**Mode**: Hybrid (Engram + OpenSpec file)
**Date**: 2026-07-19
**Verdict**: **PASS WITH WARNINGS**

---

## 1. Completeness (tasks.md)

| Phase | Tasks | Checked in tasks.md | Status |
|---|---|---|---|
| Phase 1 — Backend engine | 1.1–1.4 | 4/4 `[x]` | Verified against code |
| Phase 2 — Templates | 2.1–2.6 | 6/6 `[x]` | Verified against code/artifacts |
| Phase 3 — Tests | 3.1–3.5 | 5/5 `[x]` | Verified — re-ran and green |
| Phase 4 — QA vs Mailpit | 4.1–4.3 | **0/3 — still `[ ]`** | Work reportedly done (external evidence), **checkboxes never updated** |

**CRITICAL-01 — Phase 4 tasks unchecked despite reported completion.** `openspec/changes/amenity-notification-emails/tasks.md` lines 296, 308, 321 still show `- [ ]` for all three Phase-4 QA tasks. The orchestrator's brief states Phase-4 QA vs Mailpit already passed (all 5 events delivered styled HTML, correct Spanish copy, no raw `{{ }}`, no fallback-log entries, unit/resident shown on `created`) and that dev DB was independently verified via tinker across all 10 tenants. I did not re-run the Mailpit E2E myself (out of scope per the brief) and I have no way to independently confirm that evidence beyond the orchestrator's report and the apply-progress Engram entry (#1665), which only covers the backfill migration fix, not a fresh Phase-4 re-run after that fix. Per the sdd-verify hard rule ("Unchecked tasks always remain CRITICAL, even when other artifacts are missing or warnings-only"), this is flagged CRITICAL as a **process/documentation gap**, not a functional failure — the task list must be updated to `[x]` (with a note referencing the QA evidence) before this change is archived, and ideally the corrected migration's Phase-4 Mailpit check should be re-run for at least the `created` event on a pre-S10 tenant, since the backfill fix landed after the original Phase-4 pass and that specific scenario (pre-existing tenant, zero notification_setting rows) is the one thing the original Phase-4 evidence could not have covered.

---

## 2. Spec compliance matrix

| # | Requirement (spec.md) | Implementation | Test evidence | Status |
|---|---|---|---|---|
| 1 | Email slug flattens dotted event keys | `BladeEmailChannel::renderContent()` — `str_replace(['_','.'], '-', $templateSlug)` (`backend/app/Notifications/Channels/BladeEmailChannel.php:76`) | `AmenityReservationEmailRenderTest::test_dotted_event_key_resolves_to_flat_view` — asserts `email::amenity-reservation-created` exists, `email::amenity-reservation.created` does not, and `email::payment-accepted` (pre-existing flat key) still resolves | **PASS** — re-run, green |
| 2 | Central catalog exposes email capability for the 5 events only | `CatalogSeeder.php` lines 604–746: `supports_email=true` + `blade_email` in `default_channels` on exactly `created/approved/payment_confirmed/payment_rejected/surcharge_added`; the other 6 (`rejected`, `payment_required`, `deposit_confirmed`, `cancelled`, `payment_reminder`, `expired`) confirmed `supports_email=false` | Covered indirectly by wiring tests (channel dispatch depends on this flag) | **PASS** — source-verified line by line |
| 3 | Existing tenants receive the email channel via one-shot migration | `2026_07_19_000001_enable_amenity_reservation_emails_on_existing_settings.php` — two-step `up()`: (1) `TenantNotificationSettingSeeder` backfill per tenant, (2) explicit `whereIn(...)->update(['channel_email'=>true])` on the 5 events; `down()` restores `false`, deliberately keeps backfilled rows | `EnableAmenityReservationEmailsMigrationTest` — 4 passed / 26 assertions (flip-only-five, idempotent re-run, `down()` restores, and the corrective-batch backfill scenario for a pre-S10 tenant) | **PASS** — re-run, green |
| 4 | Board email context identifies unit and resident | `ReservationService::notifyReservationCreated()` — `unit_label`/`resident_name` added to the email-only context, both with `?? ''` fallback; in-app `notifyEvent()` title/body strings only reference `amenity_name`/`reservation_date` | `AmenityReservationNotificationWiringTest::test_create_notifies_board_and_administrator_but_not_the_creating_resident` (regression guard, task 3.4) — part of the 17-passed wiring run | **PASS** — re-run, green |
| 5 | Designed templates render real HTML, no plain-text fallback | 5 `.tsx` sources in `frontend/emails/`, compiled to 5 flat `.blade.php` files in `backend/Modules/Email/resources/views/` — all 5 carry React Email generated markers (`<!--$-->`), confirming they were compiled, not hand-written | `AmenityReservationEmailRenderTest` — 6 tests, 24 assertions, all render real HTML with expected Spanish copy, no raw `{{`/`}}` | **PASS** — re-run, green |
| 6 | Admin config exposes the email toggle without frontend changes | Data-driven rendering (existing) picks up `supports_email` automatically | Zero diff confirmed in `git status` for `NotificationsConfigSection.tsx` / `ConfigurationResource.ts`; toggle visibility reportedly confirmed live by the orchestrator in Phase 4.2 | **PASS** — zero-edit confirmed by me; UI visibility relies on Phase-4 evidence (see CRITICAL-01) |
| 7 | Feature tests cover dispatch and real rendering per event | `AmenityReservationNotificationWiringTest` (dispatch, `Queue::fake`/`Mail::fake` per event) + `AmenityReservationEmailRenderTest` (real render) | 17 passed (54 assertions) + 6 passed (24 assertions) | **PASS** — re-run, green |
| 8 | End-to-end delivery verified against Mailpit | N/A (E2E, not code) | Reported PASS by orchestrator for the original Phase-4 pass; not independently re-verified by me, and not re-run after the backfill migration fix (see CRITICAL-01) | **ACCEPTED WITH CAVEAT** — see CRITICAL-01 |

---

## 3. Test execution (re-run by me, this verification pass)

All run via **host PHP** (`php artisan test`), one process at a time, per project convention (`backend-tests-run-on-host-php`):

| Filter | Result | Duration |
|---|---|---|
| `AmenityReservationEmailRenderTest` | **6 passed / 24 assertions** | 5.90s |
| `AmenityReservationNotificationWiringTest` | **17 passed / 54 assertions** | 48.11s |
| `EnableAmenityReservationEmailsMigrationTest` | **4 passed / 26 assertions** | 15.83s |

These numbers match the apply-progress record exactly (Engram #1665: 4 passed/26 assertions for the migration test after the corrective batch). No regressions found. Full backend suite was **not** re-run per the scoping instructions (documented as 1973 passed / 6 pre-existing out-of-scope failures from a separate session's uncommitted seeder WIP).

---

## 4. Frontend gate

`pnpm typecheck` (`tsc -p tsconfig.app.json --noEmit`) was run from `frontend/`. It failed with 2 errors, but **both are pre-existing and unrelated** to this change:

```
src/components/signup-form.tsx(3,10): error TS6133: 'OAuthButtons' is declared but its value is never read.
src/components/signup-form.tsx(40,10): error TS6133: 'handleOAuth' is declared but its value is never read.
```

`signup-form.tsx` is modified WIP from an unrelated, uncommitted billing feature (`git status` shows it as `M`, unstaged) — not part of this change's diff. More importantly, **`tsconfig.app.json`'s `include` is `["src"]` only** — `frontend/emails/*.tsx` is never covered by this command at all, so it cannot serve as a type-check gate for the 5 new templates regardless of outcome. The real proof that the 5 templates compile is that `pnpm copy-emails` already succeeded and produced the 5 correctly-named, React-Email-marked `.blade.php` files with correct rendered content (confirmed both by source inspection and by the re-run `AmenityReservationEmailRenderTest`).

**WARNING-01**: `pnpm typecheck` is not a meaningful gate for `frontend/emails/` — worth noting for future SDD changes touching this directory so the wrong signal isn't relied on again.

---

## 5. Working-tree scope hygiene

`git status` (inside the `backend` submodule) shows 5 additional **untracked** blade files and 1 static asset that are NOT part of this change, produced by the same `pnpm copy-emails` run (identical timestamp, 03:29) because their `.tsx` sources already exist in `frontend/emails/` from unrelated, uncommitted work in the shared working tree:

```
?? Modules/Email/resources/views/loyalty-recovery.blade.php
?? Modules/Email/resources/views/loyalty-reward-earned.blade.php
?? Modules/Email/resources/views/loyalty-welcome.blade.php
?? Modules/Email/resources/views/payment-receipt.blade.php
?? Modules/Email/resources/views/static/40_122677.svg
```

Task 2.6 instructed `git checkout -- <blade>` for out-of-scope regenerated files, but that only works for **tracked, modified** files — these are **untracked, new** files, so `git checkout --` is a no-op against them; they were never cleaned up.

**WARNING-02**: these 4 blade files + 1 static asset must be explicitly excluded (`git add` only the intended paths, or `git clean`/`rm` if confirmed to belong to other unrelated in-flight work) before this change is committed/archived, or they will leak into this PR's diff and violate the accepted `exception-ok` scope (which was sized against the amenity-reservation-specific ~965 authored lines, not these extras). This is a shared-working-tree hazard already known from a prior session (`checkout-shared-multisession-wip` memory), not a defect introduced by this change's implementation.

---

## 6. Design coherence

No `design.md` deviations found. Confirmed against the design/tasks:
- ADR-5 (no CTA in v1 templates) — verified: none of the 5 `.tsx` files use `ButtonBase`.
- ADR-4 (`reservationContext()` stays thin, enrichment only in `notifyReservationCreated()`) — verified: `unit_label`/`resident_name` are added locally in `notifyReservationCreated()`, not in the shared `reservationContext()` helper.
- Comment-block rewrite in `CatalogSeeder.php` (~line 596) matches the design's required content (5-of-11 email support, dot-flattening explanation).

---

## Issues Summary

| Severity | Issue |
|---|---|
| CRITICAL | CRITICAL-01 — Phase 4 tasks (4.1–4.3) remain unchecked in `tasks.md` despite reported completion; task list must be updated (and ideally the `created`-event Mailpit check re-verified for a pre-S10 tenant, since the backfill fix landed after the original Phase-4 pass) before archive |
| WARNING | WARNING-01 — `pnpm typecheck` does not cover `frontend/emails/`; not a valid gate for template compilation, rely on `copy-emails` + render tests instead |
| WARNING | WARNING-02 — 4 unrelated untracked blade files + 1 static asset in the backend working tree from `copy-emails`, must be excluded from this change's commit scope |

## Final Verdict

**PASS WITH WARNINGS.** All code-level requirements are implemented correctly and match the spec; all three targeted test suites were independently re-run and are green (27 tests / 104 assertions total, 0 failures, no regressions); the 5 email templates are genuinely React-Email-compiled (not hand-written); zero-edit constraint on the frontend config UI is confirmed. The only blocking item before archive is administrative: reconcile `tasks.md` Phase 4 checkboxes with the actual QA state, and scope the working-tree diff to exclude the unrelated loyalty/payment-receipt blade artifacts.

## Artifacts
- `openspec/changes/amenity-notification-emails/verify-report.md` (this file)
- Engram `sdd/amenity-notification-emails/verify-report`
