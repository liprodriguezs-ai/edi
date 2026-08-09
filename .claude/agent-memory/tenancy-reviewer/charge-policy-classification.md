---
name: charge-policy-classification
description: Collections Slice A — charge_policy is TENANT-scoped; the concept→policy→batch→charge chain and its tenant-isolation rules
metadata:
  type: project
---

# charge_policy classification (Collections "Slice A", reviewed 2026-06-25)

**Decision:** `charge_policy` is TENANT-SCOPED — correct.
- Migration `2026_05_31_154421a_create_charge_policy_table.php` uses `BaseModel::columns($table, true)` (adds tenant_id + FK + index).
- Model `Modules/Collections/app/Models/ChargePolicy.php` uses `BelongsToTenant`.
- Holds per-cluster charge CONFIG (amounts, grace, periodicity, validity window, collection_mode). Admin-editable, no fixed UUID constants → tenant data, not a central catalog.

**Why tenant-scoped:** it carries money amounts and a validity window the cluster admin sets; nothing in code branches on its identity as a FSM. Contrast with `charge_status`/`payment_status` (central FSM catalogs).

**Chain after Slice A re-architecture:** `charge_concept` (identity: clave/name/category) → `charge_policy` (config) → `charge_batch` → `charge`. All four are tenant-scoped (BelongsToTenant verified). The old amount/periodicity/validity columns moved OFF concept ONTO policy.

**Unique indexes correctly tenant-prefixed:**
- `charge_policy_active_unique` = partial unique `(tenant_id, charge_concept_id, periodicity_id) WHERE deleted_at IS NULL AND is_active = true`.
- `charge_batch_period_unique` repointed to `(tenant_id, charge_policy_id, year, month, COALESCE(cycle,-1))`.

**FK note (acceptable, app-guarded):** `charge_policy.charge_concept_id` is a plain `->foreign()` to `charge_concept`. Both tables are tenant-scoped; a DB-level FK does NOT enforce same-tenant. The cross-tenant guard lives at the app layer: `ChargePolicyCreateRequest` / `ChargeBatchCreateRequest` use `Rule::exists('charge_concept'|'charge_policy','id')->where('tenant_id', tenant('id'))`. This is the project's standard pattern — single-DB row-level isolation, FK is referential-only.

**Direct (one-shot) generation flow respects current tenant:** `ChargePolicyService::create()` runs inside `DB::transaction` in the controller's `performStore` (tenant context already initialized by `initializeTenancy` middleware). `generateDirectBatch()` creates ChargeBatch + charges via Eloquent (BelongsToTenant stamps tenant_id). No cross-tenant path.

**Cron path (`activePoliciesFor`)** queries `ChargePolicy::query()->where(...)->whereHas('concept', ...)` — fully TenantScope-bound, runs inside `$tenant->run()`. Good.
