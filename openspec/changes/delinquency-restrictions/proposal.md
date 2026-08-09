# SDD Proposal — Delinquency-Based Feature Restrictions

- **Change:** `delinquency-restrictions`
- **Project:** `residia-monorepo` (Kivo7)
- **Phase:** proposal
- **Artifact store:** hybrid
- **Date:** 2026-07-14
- **Source of truth:** formalizes the user-approved plan "Restricción de funcionalidades por morosidad" (`~/.claude/plans/oye-mira-necesitamos-un-zippy-shannon.md`). Its "Decisiones tomadas (no reabrir)" section is settled — this proposal mirrors it 1:1 and does not re-litigate it.

---

## 1. Intent

### Problem

Kivo7 knows exactly which units are delinquent (the `charge_status` FSM plus the worst-wins aggregate on `unit.charge_status_id`) but that knowledge restricts nothing. A delinquent resident reserves amenities, generates visitor QRs, and receives packages exactly like a unit in good standing. No resident/unit-level blocking concept exists anywhere in the backend — the only block today is the whole-cluster subscription block (`tenant.status = blocked`), which is a different thing. Administrators have no collections-pressure lever beyond manually chasing debtors.

### Product outcome

The cluster administrator configures, from a Configuration section, **which features are restricted for delinquent units** and **from what minimum overdue amount**. Restriction lifts immediately when a payment drops the balance below the threshold (payment application already recomputes the unit aggregate in the same transaction).

## 2. Settled decisions (approved — do not reopen)

1. **Criterion**: unit is delinquent when `unit.charge_status_id === ChargeStatus::OVERDUE` **AND** overdue balance `>=` configured minimum. Minimum `0` = being marked overdue is enough.
2. **Blocking scope** (exact):

| Feature | Blocked | Never blocked |
|---|---|---|
| Access | Generating visitor access (`POST /visitor`); authorizing walk-in entries (`approve`/`reject`) | Resident's OWN entry (`GET /access/my-qr`, `my-log`) |
| Packages | Registering a package for the unit (`PackageService::register` + `batchRegister`) | Picking up already-registered packages (`handover`, `verifyCode`) |
| Amenities | Creating the reservation | Browsing catalog / detail / availability |

3. **No resident-level exceptions.** A delinquent board member is blocked like anyone else. Existing `super_admin`/`lawyer` middleware bypasses are retained (platform support, not a resident exception).
4. **UI pattern**: nothing hidden, nothing `disabled`. The option is visible and opens; the notice appears **when the action is initiated** (inside the wizard/modal).
5. **Privacy — two message registers:**
   - Resident (own debt): explicit — overdue amount + how to settle.
   - Guard (someone else's debt): subtle, MUST NOT mention debt or amount — only "Esta unidad no puede recibir paquetes".

## 3. Scope

### In scope

1. **Settings**: six `delinquency.*` slugs in the generic tenant-scoped `setting` table — master switch, `min_overdue_amount` (the project's **first money-valued setting**: `TYPE_INTEGER`, minor units), three per-feature toggles, resident notice text. **No new tables, no migrations.**
2. **`DelinquencyRestrictionService`** (Collections module) — the single source of truth for the blocking rule and the per-unit overdue balance, reusing the public `ChargeStatusService::effectiveExpected(Charge)`.
3. **Service-layer guards** at five hook points: `ReservationService::create`, `AccessService::generateQr`, `VisitorController::approve/reject`, `PackageService::register`, `PackageController::batchRegister`. 403 with `code: 'DELINQUENT_RESTRICTED'`; payload register depends on audience (resident vs guard).
4. **Endpoints**: `GET/PUT config/delinquency-settings` (Configuration, existing `read/update.configuration` gates); `GET my-restrictions` (resident state); `GET package/restricted-units` (guard, gated `create.package`, returns unit ids only — deliberately NOT via the ungated `/selects`).
5. **Seeded defaults** in `CollectionsDatabaseSeeder` (withTrashed create/restore pattern) + backfill command for existing tenants.
6. **Frontend**: `DelinquencyConfigSection` ("Morosidad") with pesos ↔ minor-units conversion; `useMyRestrictions()` hook; explicit notice inside `ReserveAmenityWizard` and `VisitorFormModal`; subtle inline note + blocked submit in `PackageForm`/`BulkIntakeForm`, hooked into the existing lazy `enableUnits()` trigger.

### Out of scope

- Payment plans as an exception (`ChargeStatus::PAYMENT_PLAN` is seeded but has no flow).
- Manual per-unit exemption; pre-block warning (N days ahead).
- Caching the overdue balance (point actions hit existing indexes; measure before optimizing).
- Any change to `my-qr`, `my-log`, `verifyCode`, `handover`, amenity browse/detail/availability.

## 4. Capabilities

> Contract with sdd-spec. `openspec/specs/` is currently empty — no existing capabilities to modify.

### New Capabilities

- `delinquency-restrictions`: configurable per-tenant blocking of visitor-access generation, package intake, and amenity reservation for units that are OVERDUE with balance at or above a configured minimum, with audience-dependent messaging (explicit to the resident, subtle to the guard).

### Modified Capabilities

- None.

## 5. Approach

- **Guard lives in the service layer** — verified: `FormRequest::authorize()` is dead code on Orion routes (`DelegatingModuleRequest` does not delegate it) and there are no Policies (`TraitController::authorize()` only checks Sanctum token abilities).
- **`OVERDUE` only, never `PARTIAL`** — `PARTIAL` means "paid something but still within term". Do NOT copy the `[OVERDUE, PARTIAL]` filter from `PaymentController::delinquency()` / `computeAging()`.
- Single blocking rule in one place: `restrictions_enabled && block_{feature} && status === OVERDUE && overdueBalance >= min_overdue_amount`.
- Overdue balance reuses `ChargeStatusService::effectiveExpected()` (the outstanding formula is already duplicated 4×; do not add a 5th).
- Money crosses the API as `TenantMoney` (project HARD rule); frontend edits pesos and converts via `shared/utils/money.ts`.
- Infra cost: `useMyRestrictions` is one query per resident session; `restricted-units` fires only on select open. No polling, no eager catalogs.

## 6. Affected areas

| Area | Impact |
|---|---|
| `backend/Modules/Collections` (Support, Services, Controller, Command, seeder, routes) | New — settings, service, `my-restrictions`, backfill, defaults |
| `backend/Modules/Configuration` (controller + routes) | Modified — `getDelinquencySettings`/`updateDelinquencySettings` |
| `backend/Modules/AmenityReservations`, `Access`, `Package` (services/controllers/routes) | Modified — guard hooks + `restricted-units` |
| `frontend/src/modules/configuration` | New section + registry entry |
| `frontend/src/modules/{collections,reservation,access,package}` | Modified — hook, wizard/modal notices, intake forms |

## 7. Risks & open questions

| Risk | Level | Mitigation |
|---|---|---|
| Walk-in `approve`/`reject` counted as "generating access" was an explicit assumption in the plan | Low | Approved as-is; if reversed later it is a one-line removal |
| Privacy leak: guard-facing 403 or `restricted-units` exposing amounts/debt wording | Med | Contract fixes payload per audience; explicit tests assert no amount/debt words; endpoint gated `create.package`, never `/selects` |
| First money-valued setting — pesos ↔ minor-units conversion errors | Low | Reuse `pesosToMinorUnits`/`minorUnitsToPesos`; rounding test (5000 → 500000) |
| Blocking a correct unit due to stale aggregate | Low | Payment recompute is same-transaction (immediate unblock); temporal transition is the existing daily cron — accepted 1-day granularity |

## 8. Rollback plan

No migrations and all settings default to **disabled**. Operational rollback: turn off `delinquency.restrictions_enabled` (per tenant, instant). Code rollback: revert the commits; leftover `setting` rows are inert data in the generic table and need no cleanup.

## 9. Success criteria

- [ ] Admin configures Morosidad (master + threshold in pesos + three toggles + notice text); only the diff is sent on save.
- [ ] OVERDUE unit at/above threshold: reservation create, visitor QR, walk-in approve/reject, and package register/batch all return 403 `DELINQUENT_RESTRICTED`; below threshold or `PARTIAL` within term: nothing blocks.
- [ ] Never blocked: `my-qr`/`my-log` 200 for the delinquent unit; `handover` of a registered package 200.
- [ ] Privacy: `package_intake` 403 contains no amount and no debt wording; `GET /package/restricted-units` as resident → 403.
- [ ] Buttons stay visible and enabled; notices render on action initiation. Immediate unblock after a payment drops the balance below the threshold.
- [ ] Tenant isolation: tenant A settings never affect tenant B. `php artisan test` (single process), `pnpm build`, `pnpm lint` green.

## 10. Next phases

`sdd-spec` and `sdd-design` (can run in parallel), then `sdd-tasks` → `sdd-apply`. Recommend `/review-stack` + `@infra-cost-reviewer` before merge.
