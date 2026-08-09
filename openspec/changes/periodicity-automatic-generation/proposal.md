# Proposal: Periodicity-Driven Automatic Charge Generation

## Intent

The Collections module auto-generates ONLY monthly charges. Two defects:

1. **Dead options**: `GenerateMonthlyChargesCommand` hardcodes `where('periodicity_id', Periodicity::MONTHLY)`. `BIMONTHLY` and `YEARLY` are selectable on concepts but NEVER auto-generate. `WEEKLY` and `BIWEEKLY` (quincenal) don't exist.
2. **Frontend hardcoding**: `chargeGeneration.ts` decides the "automático" badge/warning via `periodicity_id === PERIODICITY.MONTHLY` — a brittle UUID comparison.

Fix: make an `is_automatic` flag on the periodicity catalog the single source of truth for "this periodicity generates charges by cron", on both backend and frontend.

## Scope

### In Scope
- **Backend**: ALTER migration adding `is_automatic` (boolean, default false) to `periodicity`; add `WEEKLY` + `BIWEEKLY` rows (fixed UUIDs + model constants) to `Periodicity` and `$fillable`/casts.
- **CatalogSeeder**: set `is_automatic` per row (monthly/weekly/biweekly = true; yearly/bimonthly per design; eventual = false) and add the two new rows.
- **Generation command**: iterate ALL `is_automatic = true` periodicities (not hardcoded MONTHLY); compute period window, `issued_at`, and `due_date` per periodicity type.
- **Frontend**: drive badge + warning from `concept.periodicity.is_automatic`; expose `is_automatic` in the periodicity/catalog type and `useCatalogs`.

### Out of Scope
- New treasurer notification channels or templates (reuse existing summary).
- Reworking `collections.charge_generation_day` UX beyond what new periodicities require.
- Per-concept custom schedules (only catalog-level periodicity drives generation).

## Capabilities

### New Capabilities
- None.

### Modified Capabilities
- `collections-charge-generation`: cron now generates for any `is_automatic` periodicity (not just monthly), with per-periodicity scheduling and idempotency.
- `collections-periodicity-catalog`: catalog gains `is_automatic`; adds WEEKLY + BIWEEKLY rows.

## Approach

- `is_automatic` on the central `periodicity` catalog is the contract. Backend filters concepts by `whereHas('periodicity', is_automatic = true)`; frontend reads `concept.periodicity.is_automatic`.
- Generalize `GenerateMonthlyChargesCommand` into a periodicity-aware loop. Each automatic periodicity gets a per-type rule for: should-run-today, period identity (idempotency key), `issued_at`, `due_date`.
- Pragmatic scheduling (keep it simple): weekly runs on a fixed weekday; biweekly runs on day 15 AND last day of month; monthly keeps `charge_generation_day`. The DESIGN phase decides where each rule lives and the idempotency key shape.

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `Modules/Collections/database/migrations/*_add_is_automatic_to_periodicity_table.php` | New | ALTER `periodicity` add `is_automatic` boolean default false + comment |
| `Modules/Collections/app/Models/Periodicity.php` | Modified | Add `WEEKLY`/`BIWEEKLY` consts; `is_automatic` in `$fillable` + boolean cast |
| `database/seeders/CatalogSeeder.php:274` | Modified | `seedPeriodicities()` adds 2 rows + `is_automatic` per row |
| `Modules/Collections/app/Console/Commands/GenerateMonthlyChargesCommand.php` | Modified | Iterate `is_automatic` periodicities; per-type period/dates |
| `Modules/Collections/database/migrations/2026_05_31_154422_create_charge_batch_table.php` (idempotency index) | Possibly Modified | `charge_batch_monthly_unique` on `(tenant_id, concept, year, month)` may not isolate weekly/biweekly batches in the same month |
| `frontend/src/modules/chargeConcept/helpers/chargeGeneration.ts:15` | Modified | `isAutoMonthly` → driven by `is_automatic` |
| `frontend/src/modules/chargeConcept/{schema.ts,ChargeConceptListPage.tsx}`, `chargeBatch/ChargeBatchFormModal.tsx`, `shared/hooks/useCatalogs.ts` | Modified | Expose/consume `is_automatic`; drop hardcoded UUID |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| BIWEEKLY = "day 15 + last day" → 2 runs/month, EoM varies (28/30/31); single `charge_generation_day` model doesn't fit | High | DESIGN-phase decision: per-periodicity scheduling rule + EoM clamp; flagged as primary design question |
| Idempotency: partial unique key is `(tenant,concept,year,month)`; weekly/biweekly collide within a month | High | DESIGN must adjust the key (add a cycle/week/day discriminator) without breaking the monthly path |
| Central-catalog ALTER affects all tenants at once (stancl-tenancy) | Med | No `tenant_id`; `BaseModel::columns($table,false)`; backfill `is_automatic` for existing rows in the seeder/migration |
| Frontend depends on `periodicity` relation being included on concept payloads | Med | Verify controller `includes()` whitelists `periodicity`; fall back gracefully if absent |

## Rollback Plan

- Backend: migration `down()` drops `is_automatic` and reverts the idempotency index. New periodicity rows are catalog data; re-seed prior set or soft-deactivate via `is_active`. Revert the command to the hardcoded-MONTHLY filter.
- Frontend: revert `chargeGeneration.ts` + type/usage edits. Both layers are independently revertible.

## Dependencies

- None external. Backend migration must deploy before frontend reads `is_automatic`.

## Success Criteria

- [ ] Concepts with any `is_automatic` periodicity auto-generate on schedule; non-automatic never do.
- [ ] WEEKLY + BIWEEKLY periodicities exist, seeded with fixed UUIDs + constants.
- [ ] No duplicate batches for weekly/biweekly within the same month (idempotency holds).
- [ ] Frontend badge/warning reflect `is_automatic` with zero hardcoded periodicity UUIDs.
- [ ] Monthly behavior unchanged (no regression).
