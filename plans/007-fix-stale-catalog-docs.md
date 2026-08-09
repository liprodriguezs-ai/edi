# Plan 007: Correct the stale "catalogs are tenant-scoped" documentation

> **Executor instructions**: Follow step by step; verify each step. On a "STOP
> condition", stop and report. Update this plan's row in `plans/README.md`.
>
> **Drift check (run first)**: from the repo root,
> `git diff --stat 7624705..HEAD -- .project-context/modules.md .project-context/domain.md CLAUDE.md`
> Mismatch with "Current state" = STOP.

## Status

- **Priority**: P2
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: docs
- **Planned at**: monorepo commit `7624705`, 2026-06-11

## Why this matters

The project context docs state that the per-entity **status catalogs**
(`charge_status`, `payment_status`, `charge_batch_status`,
`community_project_status`, `project_contribution_status`, `board_period_status`)
are **tenant-scoped** with `unique(['tenant_id','name'])`. They are **not** — all
of these are CENTRAL tables (`BaseModel::columns($table, false)`, no
`BelongsToTenant`), seeded once in `CatalogSeeder`. This is documentation that is
*actively wrong*: an agent or developer following it will add `BelongsToTenant`
and a `tenant_id` unique index to a new status catalog that must stay central,
producing a broken migration. The error has already propagated into the root
`CLAUDE.md`. This plan corrects the three documents to reflect the real, current
split.

**Ground truth (current architecture, verified):**
- **CENTRAL catalogs** (no `tenant_id`, shared by all tenants, seeded by
  `backend/database/seeders/CatalogSeeder.php`): the 6 status FSM catalogs above,
  plus `unit_relationship`, `board_role`, `amount_mode`, `periodicity`,
  `payment_method`, `expense_status`, `beneficiary_type`, `bank_account_type`,
  `participation_type`, `chat_channel_type`, `visitor_status`, `qr_type`,
  `access_entry_type`, `access_verification`, `blacklist_type`,
  `announcement_priority`, `announcement_status`, `tenant_payment_type` (≈24 total).
- **TENANT-SCOPED catalogs** (have `tenant_id` via `BelongsToTenant`, seeded
  per-tenant by `backend/database/seeders/TenantCatalogSeeder.php`, manageable by
  the cluster admin): `unit_type`, `unit_status`, `employee_position`,
  `pay_frequency`, `employee_shift`, `bank_account_purpose`,
  `charge_concept_category`, `community_project_category`, `provider_type`,
  `provider_service_type`, `visitor_motive`, `announcement_type`,
  `expense_category`, `unit_feature` (14 total). Behavior that used to be implied
  lives in columns now: `requires_floor` (unit_type), `is_billable` (unit_status),
  `grants_role` (employee_position), `key` (announcement_type,
  charge_concept_category — "system rows" guarded by the `HasCatalogKey` trait).

To re-confirm before editing, you can read the migration headers: each
`backend/Modules/*/database/migrations/*create_<catalog>_table.php` uses
`BaseModel::columns($table, false)` for central or `true` for tenant-scoped, and
`backend/database/migrations/2026_06_11_120000_make_editable_catalogs_tenant_scoped.php`
is the migration that moved the 12 editable catalogs to tenant-scoped.

## Current state

- `.project-context/modules.md` (~lines 58–70) — a section titled "Key Catalog
  Tables (tenant-scoped, per-entity pattern)" listing `charge_status`,
  `payment_status`, `charge_batch_status`, `community_project_status`,
  `project_contribution_status`, `unit_status`, `board_period_status` as
  tenant-scoped with `unique(['tenant_id','name'])`. **Wrong for 6 of 7** (only
  `unit_status` is now tenant-scoped).
- `.project-context/domain.md` (~lines 136–138) — rule "Statuses y enumeraciones
  = tablas de catálogo por entidad, tenant-scoped". **Wrong as a blanket rule.**
- `CLAUDE.md` (root) section "3. STRICT GENERAL RULES", item 8
  ("Statuses/enums = per-entity tenant-scoped catalog tables") — repeats the
  error; the "Canonical catalog tables" list there names central tables as
  tenant-scoped.

## Commands you will need

| Purpose                  | Command (from repo root)                       | Expected            |
|--------------------------|------------------------------------------------|---------------------|
| Confirm central status   | `grep -n "columns(\$table" backend/Modules/Collections/database/migrations/*charge_status*` | shows `, false)` |
| Confirm tenant-scoped    | `grep -rln "BelongsToTenant" backend/Modules/Housing/app/Models/UnitStatus.php` | matches |
| Find the migration       | `ls backend/database/migrations/2026_06_11_120000_make_editable_catalogs_tenant_scoped.php` | exists |

## Scope

**In scope**:
- `.project-context/modules.md`
- `.project-context/domain.md`
- `CLAUDE.md` (root) — section 3 item 8 and any "Canonical catalog tables" list

**Out of scope**:
- Any code or migration change (this is docs-only).
- `backend/CLAUDE.md` (Laravel Boost-maintained — do not hand-edit).
- The skill/agent files under `.agents/` or `.claude/` beyond the root `CLAUDE.md`.

## Git workflow

- Branch: `advisor/007-fix-stale-catalog-docs` (in the monorepo root repo — these
  files are tracked at root, not in a submodule)
- Conventional commit: `docs: correct central vs tenant-scoped catalog classification`
- No push/PR unless instructed.

## Steps

### Step 1: Rewrite the modules.md catalog section

Replace the "Key Catalog Tables (tenant-scoped, per-entity pattern)" section
with two clearly separated tables:
- **Central catalogs (global, no `tenant_id`)** — list the status FSM catalogs
  and the other central catalogs from "Ground truth" above; note they're seeded
  once by `CatalogSeeder` and are read-only for tenant roles.
- **Tenant-scoped catalogs (`BelongsToTenant`, per-tenant, admin-manageable)** —
  list the 14, note they're seeded per-tenant by `TenantCatalogSeeder`, mention
  the behavior columns (`requires_floor`, `is_billable`, `grants_role`, `key`)
  and the `HasCatalogKey` system-row mechanism.

### Step 2: Fix the domain.md rule

Replace the blanket "statuses = tenant-scoped" rule with the accurate split:
status FSM catalogs are CENTRAL; editable label catalogs are tenant-scoped.
Reference `TenantCatalogSeeder` and `CatalogSeeder` as the two seed paths.

### Step 3: Fix root CLAUDE.md section 3 item 8

Correct item 8 and its "Canonical catalog tables" list so it does not instruct
adding `BelongsToTenant` / `unique(['tenant_id','name'])` to central status
catalogs. State the rule as: "status FSM catalogs are central (seeded once);
admin-editable label catalogs are tenant-scoped via `BelongsToTenant` +
`TenantCatalogSeeder`."

**Verify** (after steps 1–3):
- `grep -n "charge_status" .project-context/modules.md` — `charge_status` is now
  listed under central, not tenant-scoped.
- `grep -rn "tenant-scoped" .project-context/domain.md` — no remaining claim that
  all statuses are tenant-scoped.
- `grep -n "unique(\['tenant_id','name'\])" CLAUDE.md` — no longer prescribed for
  central status catalogs.

## Test plan

No automated test (docs). Verification: a reader cross-referencing the corrected
docs against `CatalogSeeder.php` (central) and `TenantCatalogSeeder.php`
(tenant-scoped) finds them consistent. Spot-check 3 catalogs in each bucket
against their migration's `columns($table, false|true)`.

## Done criteria

ALL must hold:

- [ ] `.project-context/modules.md` lists the 6 status FSM catalogs as central, not tenant-scoped.
- [ ] `.project-context/domain.md` no longer states statuses are uniformly tenant-scoped.
- [ ] Root `CLAUDE.md` item 8 reflects the central/tenant split and references both seeders.
- [ ] Spot-check: every catalog the docs now label "central" has `columns($table, false)` in its migration; every "tenant-scoped" one has `BelongsToTenant`.
- [ ] No code/migration files modified (`git status` shows only the 3 docs).
- [ ] `plans/README.md` status row updated.

## STOP conditions

- The docs already match the corrected classification (someone fixed them — drift).
- A catalog you're about to classify has migration evidence that contradicts the
  "Ground truth" list above (e.g. a catalog listed as central actually has
  `BelongsToTenant`) — STOP and report the specific catalog; the ground truth may
  have shifted.

## Maintenance notes

- When a new catalog is added, classify it in BOTH docs at creation time. The
  decision rule: a catalog the cluster admin should edit and whose rows carry no
  branching business logic → tenant-scoped; an FSM/behavior selector the backend
  branches on → central.
- A reviewer should confirm the corrected lists match `CatalogSeeder` (central)
  and `TenantCatalogSeeder` (tenant) exactly — those seeders are the contract.
