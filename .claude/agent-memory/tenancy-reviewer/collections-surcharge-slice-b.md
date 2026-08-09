---
name: collections-surcharge-slice-b
description: Tenancy review of Collections Slice B (recargos moratorios / late-fee surcharge) — classification + isolation findings
metadata:
  type: project
---

# Collections Slice B — Recargos moratorios (late-fee surcharge)

Reviewed 2026-06-25 (uncommitted diff). Tenant-isolation verdict: SAFE.

**Classification (confirmed correct):**
- `surcharge_mode` = CENTRAL catalog. `BaseModel::columns($table, false)`, extends `CentralCatalogModel`, fixed UUIDs `PERCENT`/`FIXED`, seeded once by central `CatalogSeeder::seedSurchargeModes()`. Mirrors `amount_mode`. Code branches on the UUID → central is right.
- `charge_surcharge_rule` = TENANT-scoped. `BaseModel::columns($table, true)` (tenant_id + FK + index) + `BelongsToTenant`. Owns the per-policy late-fee schedule. Correct.
- `charge` ledger cols (`surcharge_amount`, `surcharge_recomputed_at`, `tax_amount`) added via ALTER migration; `charge` already `BelongsToTenant`. Correct.

**Isolation mechanics verified:**
- `ChargePolicyService::syncSurchargeRules()` scopes all reads/writes/deletes through `$policy->surcharge_rules()` (tenant-scoped parent relation). A `surcharge_rules.*.id` from another tenant does NOT match `$policy->surcharge_rules()->whereKey($id)` → falls through to create (stamped with current tenant_id). The `whereNotIn('id', $keptIds)->delete()` is also relation-scoped. No cross-tenant rule write/delete possible.
- Accrual engine (`ChargeStatusService::accrueSurcharge`/`computeSurcharge`) reads only the eager-loaded `charge.policy.surcharge_rules` collection; the daily cron (`RefreshChargeStatusesCommand`) iterates `Tenant::all()` + `$tenant->run()`, so `Charge::query()->with('policy.surcharge_rules')` is tenant-scoped per iteration. No leak.
- `surcharge_rules.*.surcharge_mode_id` exists-rule has NO tenant filter — CORRECT (surcharge_mode is central).
- PaymentController register/submit now scope `unit_id` and `bank_account_id` exists-rules by `tenant('id')` (hardening, good). `submit` default-account fallback resolves `BankAccount` via tenant-scoped model → only own cluster's account.

**Minor (NOT isolation):** `charge_surcharge_rule` has only a plain index on `(charge_policy_id, from_month)`, no unique — two active rules with the same `from_month` on one policy are possible; `selectSurchargeRule` picks one arbitrarily (sortByDesc on from_month, ties unordered). Data-integrity nit, not tenant-isolation.
