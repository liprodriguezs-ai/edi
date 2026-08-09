---
name: collections-cross-connection-cases
description: Collections module — documented intentional central references and User-scope bypasses that are CORRECT (not leaks)
metadata:
  type: project
---

# Collections — documented correct cross-references (not leaks)

These are intentional and correct — do NOT flag as isolation breaches in future reviews:

- **`Periodicity` and `AmountMode` are CENTRAL** (`extends CentralCatalogModel`, no tenant_id). `Rule::exists('amount_mode','id')` / `exists('periodicity','id')` in ChargePolicy/ChargeConcept requests have NO tenant filter — correct (central tables have no tenant_id column).
- **`charge_status`, `payment_status`, `charge_batch_status` are CENTRAL** FSM catalogs with fixed UUID constants. `ChargeStatus` extends CentralCatalogModel. The model comment was corrected in Slice A to say "CENTRAL/shared catalog" (was mislabeled "seeded per tenant").
- **`Payment::payer_user()` bypasses UserTenantScope** via `->withoutGlobalScope(UserTenantScope::class)` because payment is tenant-scoped but User is central. Correct per stancl-tenancy §9.
- **`Setting` IS tenant-scoped** (`BelongsToTenant`). `CollectionsDatabaseSeeder` uses `Setting::firstOrCreate(['slug'=>...])` inside tenant context → TenantScope adds tenant_id to lookup, BelongsToTenant stamps on insert. Per-tenant settings isolation is intact. `BackfillCollectionSettingsCommand` wraps each tenant in `$tenant->run()`.

# Payment exists-rule hardening (Slice A, correct)
`PaymentController::register/submit` added `Rule::exists('unit','id')->where('tenant_id', tenant('id'))` and same for `bank_account` — closes a cross-tenant FK injection (previously only `uuid` validated). This is a GOOD hardening, matches the project pattern for tenant-scoped exists rules.
