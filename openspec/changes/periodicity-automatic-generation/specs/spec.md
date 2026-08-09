# Delta Spec: Periodicity-Driven Automatic Charge Generation

**Change**: `periodicity-automatic-generation`
**Date**: 2026-06-13
**Status**: Draft

---

## 1. What Must Be True After This Change Is Applied

This section is authoritative. Every assertion here defines a verifiable post-condition.

### 1.1 Periodicity Catalog — `is_automatic` Flag

- The `periodicity` table MUST have an `is_automatic` boolean column, NOT NULL, defaulting to `false`.
- The column MUST carry a `->comment(...)` per the project data-dictionary rule.
- The change is applied via a new ALTER migration; the original `create_periodicity_table` migration is NOT modified.
- `Periodicity::$fillable` MUST include `'is_automatic'`.
- `Periodicity::casts()` MUST cast `'is_automatic'` to `'boolean'`.
- `is_automatic = true` means the cron generates batches for concepts that use this periodicity. `is_automatic = false` means the periodicity is manual-only; the cron never touches those concepts.

### 1.2 Periodicity Catalog — New Rows

- Constants `Periodicity::WEEKLY` and `Periodicity::BIWEEKLY` MUST exist in the model, each with a fixed UUID value that is stable across environments (matching the seeder).
- The seeder `seedPeriodicities()` MUST insert/upsert all six rows with the correct `is_automatic` value:

| Constant | Name | `is_automatic` |
|---|---|---|
| `MONTHLY` | Mensual | `true` |
| `BIMONTHLY` | Bimestral | `true` |
| `YEARLY` | Anual | `true` |
| `EVENTUAL` | Eventual | `false` |
| `WEEKLY` | Semanal | `true` |
| `BIWEEKLY` | Quincenal | `true` |

- BIMONTHLY and YEARLY are `is_automatic = true`: the DESIGN phase resolved the proposal's open question by promoting them to automatic with deterministic stateless schedules (BIMONTHLY = odd months, YEARLY = January; see design §4). Confirmed by product decision 2026-06-13.
- EVENTUAL is the ONLY non-automatic periodicity: `is_automatic = false` (manual-only by definition; the cron never touches eventual concepts).

### 1.3 Generation Command — Generalized Loop

- The command (renamed or kept; the signature change is a design decision) MUST iterate ALL periodicities where `is_automatic = true` instead of hard-coding `Periodicity::MONTHLY`.
- For each automatic periodicity, concepts are fetched with:
  - `periodicity_id` = that periodicity's id
  - `is_active = true`
  - `valid_from <= today`
  - `valid_to IS NULL OR valid_to >= today`
  - `deleted_at IS NULL`
- Monthly scheduling rule is PRESERVED: a monthly batch is created only on `today->day === tenant.collections.charge_generation_day` (clamped to 1–28). No monthly batch is created outside that day.
- Bimonthly scheduling rule: fires on `today->day === generationDay` (clamped 1–28) AND only in odd months (Jan, Mar, May, Jul, Sep, Nov) → 6 runs/year, evenly spaced. `cycle = null`.
- Yearly scheduling rule: fires on `today->day === generationDay` (clamped 1–28) AND only in January (`today.month === 1`) → once per year. `cycle = null`.
- Weekly scheduling rule: one batch per week, on a fixed weekday defined by the periodicity (design decision: day stored or constant). A weekly batch is created only on that weekday.
- Biweekly scheduling rule: a batch is created on day 15 of the month AND on the last day of the month. "Last day" is `Carbon::today()->isLastOfMonth()` (covers 28/30/31 correctly; February is handled).
- For each batch, `issued_at` = today's date; `due_date` = per-periodicity rule (design decision).
- The `title` format adapts to periodicity type; the human-readable label MUST reflect period identity (e.g. "Semanal semana 24 2026", "Quincenal 1a quincena junio 2026", "Mensual junio 2026").
- Treasurer notification summary continues to fire after each tenant's generation run (reusing the existing channel); no new notification templates are introduced.

### 1.4 Idempotency — Uniqueness Constraint

- The existing partial unique index `charge_batch_monthly_unique` on `(tenant_id, charge_concept_id, year, month) WHERE deleted_at IS NULL AND month IS NOT NULL` is NOT sufficient for weekly or biweekly batches (multiple batches per month per concept).
- A new discriminator column or an expanded unique key MUST be introduced to prevent duplicates for non-monthly periodicities. Specifically:
  - Monthly: still unique on `(tenant_id, charge_concept_id, year, month)`.
  - Weekly: unique on `(tenant_id, charge_concept_id, year, week_number)` where `week_number` is the ISO week of the year.
  - Biweekly: unique on `(tenant_id, charge_concept_id, year, month, fortnight)` where `fortnight` is `1` (day 15 run) or `2` (end-of-month run).
- The design phase decides whether to achieve this via: (a) a new nullable `week` column + a new nullable `fortnight` column + separate partial indexes, or (b) a `cycle_key` text column + a single unique index. Either approach is acceptable; the spec only mandates that no duplicate batch can be created for the same concept + period window per tenant.
- The existing catch-and-reactivate pattern (catch `UniqueConstraintViolationException`, find the existing batch, call `activateBatch()`) MUST be preserved and generalized for all periodicity types.

### 1.5 API — `periodicity` Relation Included on ChargeConcept Payloads

- `ChargeConceptController::includes()` already whitelists `'periodicity'`. This MUST NOT be removed.
- When a consumer calls `POST /charge_concept/search` with `"includes": ["periodicity"]`, the response MUST contain `concept.periodicity.is_automatic` as a boolean.
- When the `periodicity` include is present, `concept.periodicity` MUST be a full periodicity object (not null) for every active concept.

### 1.6 Frontend — `is_automatic` Drives Badge and Warning

- The helper `isAutoMonthly` (or its successor) MUST determine automaticity by reading `concept.periodicity.is_automatic` (a boolean), NOT by comparing `concept.periodicity_id` to a hardcoded UUID.
- The "automático" badge in `ChargeConceptListPage` MUST display if and only if `concept.periodicity.is_automatic === true`.
- The warning callout in `ChargeBatchFormModal` (or equivalent new-cobro form) MUST display if and only if `concept.periodicity.is_automatic === true`.
- Non-automatic concepts (EVENTUAL — the only `is_automatic = false` periodicity) MUST show no badge and no warning.
- The TypeScript type for `Periodicity` (or `ICatalogPeriodicity`) MUST include `is_automatic: boolean`.
- `useCatalogs` (or the hook that returns periodicities) MUST expose `is_automatic` so the UI can derive automation state from the catalog without fetching a full concept.

---

## 2. Acceptance Scenarios (Given / When / Then)

### Scenario 1 — Catalog Has `is_automatic`

**Given** the migration `add_is_automatic_to_periodicity_table` has run  
**When** the seeder `seedPeriodicities()` has executed  
**Then**:
- `SELECT is_automatic FROM periodicity WHERE id = Periodicity::MONTHLY` → `true`
- `SELECT is_automatic FROM periodicity WHERE id = Periodicity::WEEKLY` → `true`
- `SELECT is_automatic FROM periodicity WHERE id = Periodicity::BIWEEKLY` → `true`
- `SELECT is_automatic FROM periodicity WHERE id = Periodicity::BIMONTHLY` → `true`
- `SELECT is_automatic FROM periodicity WHERE id = Periodicity::YEARLY` → `true`
- `SELECT is_automatic FROM periodicity WHERE id = Periodicity::EVENTUAL` → `false`

---

### Scenario 2 — Monthly Generation Regression (Existing Behavior Preserved)

**Given** tenant A has `collections.charge_generation_day = 5`  
**And** today is the 5th of the month  
**And** there is an active charge concept with `periodicity_id = Periodicity::MONTHLY`  
**And** no `charge_batch` exists for that concept / year / month  
**When** the generation command runs  
**Then** exactly one `charge_batch` is created for that concept with `year = current_year` and `month = current_month`

**Given** today is the 6th of the month (not the generation day)  
**When** the generation command runs  
**Then** no monthly `charge_batch` is created for tenant A

---

### Scenario 3 — Only EVENTUAL Never Auto-Generates; BIMONTHLY/YEARLY Fire on Schedule

**Given** there is an active charge concept with `periodicity_id = Periodicity::EVENTUAL`  
**When** the generation command runs on any day  
**Then** no `charge_batch` is created for that concept by the cron  
*(EVENTUAL is the only `is_automatic = false` periodicity, so it is excluded from the loop)*

**Given** an active concept with `periodicity_id = Periodicity::BIMONTHLY`  
**And** today is `generationDay` in an odd month (Jan/Mar/May/Jul/Sep/Nov)  
**When** the generation command runs  
**Then** exactly one `charge_batch` (cycle = null) is created; in even months no batch is created

**Given** an active concept with `periodicity_id = Periodicity::YEARLY`  
**And** today is `generationDay` in January  
**When** the generation command runs  
**Then** exactly one `charge_batch` (cycle = null) is created; in any other month no batch is created

---

### Scenario 4 — Weekly Generation Runs on the Correct Weekday Only

**Given** an active concept with `periodicity_id = Periodicity::WEEKLY`  
**And** today is the configured weekly generation weekday (e.g., Monday)  
**When** the generation command runs  
**Then** a `charge_batch` is created for ISO week W of year Y for that concept

**Given** today is NOT the configured weekly generation weekday  
**When** the generation command runs  
**Then** no weekly `charge_batch` is created

---

### Scenario 5 — Biweekly Generation Runs on Day 15 and Last Day of Month

**Given** an active concept with `periodicity_id = Periodicity::BIWEEKLY`  
**And** today is the 15th of the month  
**When** the generation command runs  
**Then** a `charge_batch` is created representing the first fortnight of that month

**Given** today is the last day of the month (28, 29, 30, or 31 depending on month)  
**When** the generation command runs  
**Then** a `charge_batch` is created representing the second fortnight of that month

**Given** today is neither the 15th nor the last day of the month  
**When** the generation command runs  
**Then** no biweekly `charge_batch` is created

---

### Scenario 6 — February End-of-Month Handled Correctly

**Given** an active concept with `periodicity_id = Periodicity::BIWEEKLY`  
**And** today is February 28th in a non-leap year (last day of February)  
**When** the generation command runs  
**Then** a `charge_batch` is created for the second fortnight of February  
**And** `issued_at = 2026-02-28`  
**And** no batch is attempted for Feb 29 or Feb 30 (those dates do not exist)

**Given** today is February 29th in a leap year  
**When** the generation command runs  
**Then** a `charge_batch` is created for the second fortnight of February

---

### Scenario 7 — Idempotency: Monthly (Existing Path)

**Given** a `charge_batch` already exists for concept C / year Y / month M (the batch is not soft-deleted)  
**When** the generation command runs again for the same day  
**Then** no second `charge_batch` is created for C / Y / M  
**And** a `UniqueConstraintViolationException` is caught  
**And** `activateBatch()` is called on the existing batch (reactivation is a no-op if already active)

---

### Scenario 8 — Idempotency: Weekly

**Given** a `charge_batch` already exists for concept C / ISO week W / year Y  
**When** the generation command runs again on the same day (same ISO week)  
**Then** no second `charge_batch` is created for C / W / Y  
**And** the existing batch is found and `activateBatch()` is called idempotently

---

### Scenario 9 — Idempotency: Biweekly

**Given** a `charge_batch` already exists for concept C / year Y / month M / fortnight 1 (day-15 run)  
**When** the generation command runs again on the 15th  
**Then** no second `charge_batch` is created  
**And** the catch-and-reactivate path is executed

**Given** a `charge_batch` already exists for concept C / year Y / month M / fortnight 2 (end-of-month run)  
**When** the generation command runs again on the last day of the month  
**Then** no second `charge_batch` is created

**Given** fortnight-1 batch exists for concept C / July  
**When** the end-of-month generation runs on July 31st  
**Then** a new `charge_batch` is created for fortnight 2 (these are distinct batches; they do NOT collide)

---

### Scenario 10 — ChargeConcept API Includes `periodicity.is_automatic`

**Given** a charge concept with `periodicity = MONTHLY`  
**When** `POST /charge_concept/search` is called with `{ "includes": ["periodicity"] }`  
**Then** the response body contains:
```json
{
  "data": [
    {
      "periodicity": {
        "id": "97b53d7a-48bf-47e5-b29b-865d17913253",
        "is_automatic": true
      }
    }
  ]
}
```

**Given** a charge concept with `periodicity = EVENTUAL`  
**When** `POST /charge_concept/search` is called with `{ "includes": ["periodicity"] }`  
**Then** `concept.periodicity.is_automatic` is `false`

---

### Scenario 11 — Frontend Badge Driven by `is_automatic`, Not UUID

**Given** a concept list page is rendered  
**And** concept A has `periodicity.is_automatic = true`  
**And** concept B has `periodicity.is_automatic = false`  
**When** the list renders  
**Then** concept A shows the "automático" badge  
**And** concept B shows no badge  
**And** no comparison `periodicity_id === PERIODICITY.MONTHLY` appears in badge-rendering logic

---

### Scenario 12 — Frontend New-Cobro Warning Driven by `is_automatic`

**Given** the new-cobro form (ChargeBatchFormModal or equivalent) is open  
**And** the selected concept has `periodicity.is_automatic = true`  
**When** the form renders  
**Then** the warning callout is visible

**Given** the selected concept has `periodicity.is_automatic = false`  
**When** the form renders  
**Then** the warning callout is NOT visible

---

### Scenario 13 — Tenant Isolation: Generation Does Not Cross-Contaminate

**Given** tenant A has concept C-A (monthly, generation day 1) and tenant B has concept C-B (monthly, generation day 1)  
**When** the generation command runs on day 1 of the month  
**Then** `charge_batch` rows for C-A carry `tenant_id = A`  
**And** `charge_batch` rows for C-B carry `tenant_id = B`  
**And** no batch from tenant A appears in tenant B's data and vice versa

---

### Scenario 14 — Concepts Outside Validity Window Are Skipped

**Given** an active monthly concept whose `valid_to` is yesterday  
**When** the generation command runs  
**Then** no `charge_batch` is created for that concept (expired validity window excludes it)

**Given** a monthly concept whose `valid_from` is tomorrow  
**When** the generation command runs today  
**Then** no `charge_batch` is created (not yet in effect)

---

### Scenario 15 — `periodicity` Relation Cannot Be Removed from Controller `includes()`

**Given** `ChargeConceptController::includes()` returns `['category', 'amountMode', 'periodicity']`  
**When** the change is applied  
**Then** `'periodicity'` MUST still be present in the `includes()` whitelist  
*(Removing it would break frontend `is_automatic` reads without a 422 at the API layer)*

---

## 3. Out-of-Scope Assertions

The following are explicitly NOT specified here:

- New notification templates or new delivery channels for treasurer summaries.
- Reworking `collections.charge_generation_day` setting UX.
- Per-concept custom schedules (cron is catalog-level only).

---

## 4. Open Questions for Design Phase

These were surfaced as HIGH risks in the proposal. The spec mandates the WHAT; the design resolves the HOW.

| ID | Question | Impact |
|---|---|---|
| DQ-1 | Which column(s) extend the idempotency key for weekly/biweekly? (new `week` + `fortnight` nullable columns vs. a single `cycle_key` text column) | Schema migration shape, index definition, ChargeBatch `$fillable` |
| DQ-2 | What is the fixed weekday for weekly generation — hardcoded Monday or configurable per-tenant setting? | Command scheduling logic, tenant Setting schema |
| DQ-3 | What is `due_date` for weekly and biweekly batches — a fixed offset (+7d, +15d) or end-of-period? | `ChargeBatch` creation logic |
| DQ-4 | Rename `collections:generate-monthly` to `collections:generate-charges` or keep + add new command? | Scheduler registration, backward-compat with cron configs |
