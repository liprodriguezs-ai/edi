# Module 4 — Collections — Tasks

## Status: COMPLETE (green gate passing)

### Migrations
- [x] 7 catalog tables (charge_status, payment_status, charge_batch_status,
      charge_concept_category, amount_mode, periodicity, payment_method)
- [x] charge_concept (COALESCE-unique clave per tenant)
- [x] charge_batch (partial unique on concept/year/month for monthly idempotency)
- [x] charge, payment (unique receipt per tenant), payment_application, credit_balance
- [x] add charge_status_id to unit (Housing) + FK

### Models
- [x] 7 catalog models with string KEY_* constants + name/color/order/is_active
- [x] ChargeConcept, ChargeBatch, Charge, Payment (payerUser withoutGlobalScope),
      PaymentApplication, CreditBalance — all MoneyIntegerCast on money columns
- [x] Unit.chargeStatus() belongsTo + charge_status_id fillable

### Services
- [x] TargetResolver (8 target types + previewCount)
- [x] AmountCalculator (fixed/range/free/by_share, minor units, documented rounding)
- [x] ChargeGenerationService.activateBatch (idempotent) + recomputeBatchProgress
- [x] ChargeStatusService (6 states, stored writer, worst-wins aggregate, refresh cron)
- [x] PaymentService.register (FIFO + credit + manual) + cancel (full reversal)

### Endpoints
- [x] Orion resources for all tables (catalogs + entities)
- [x] Custom: charge-batch/{id}/activate, charge-batch/preview, payment/register,
      payment/{id}/cancel, charge/{id}/cancel, unit/{id}/statement, delinquency
- [x] Routes use ['auth:sanctum','initializeTenancy']

### Cron
- [x] collections:generate-monthly (per tenant, idempotent)
- [x] collections:refresh-statuses (per tenant)
- [x] Registered in CollectionsServiceProvider schedule

### Seeding + menu
- [x] TenantDatabaseSeeder seeds all 7 catalogs per tenant
- [x] modules.csv (13 rows), permissions.csv (6 each), module_system.csv (Fraccionamiento/Cobranza)
- [x] SystemTableSeeder (entities, routes, icons, hidden catalogs)
- [x] RolePermissionTableSeeder common perms

### Tests
- [x] 4 spec flows (A mass gen, B annual prepay, C overpay+credit, D single-unit)
- [x] ChargeStatusService 6 states + grace days
- [x] FIFO ordering, payment cancel reversal, bank balance, charge cancel, tenant isolation,
      unique receipt, money integer storage, by_share rounding, paid=sum(applications)
- [x] tearDown ends tenancy (deadlock fix)

### Green gate (all exit 0)
- [x] migrate:fresh --seed
- [x] db:seed ResetPermissionTableSeeder
- [x] orion:specs
- [x] php artisan test — 177 passed, 0 failed, 0 deadlocks (3 consecutive runs)
- [x] pint --dirty — passed
