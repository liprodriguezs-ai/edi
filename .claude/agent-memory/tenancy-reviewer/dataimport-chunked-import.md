---
name: dataimport-chunked-import
description: DataImport module's chunked Bus::batch payment import. Tenant-isolation review verdict (SAFE) and the specific facts that confirm isolation across coordinator/chunk/finalizer jobs.
metadata:
  type: pattern
---

# DataImport chunked import — tenant isolation review (verdict: SAFE)

Reviewed 2026-06-14. Module `backend/Modules/DataImport`. Chunked redesign: `ImportController@executeImport` → `RunImportJob` (coordinator, parses once, caches rows to a tenant-namespaced file, dispatches `Bus::batch(ProcessImportChunkJob)` + `FinalizeImportJob` via `->finally`). All on the `imports` QUEUE NAME (not a connection).

**Why isolation is sound:**
- `ImportRun` model uses `BelongsToTenant` → every `ImportRun::find()` in all three jobs is auto-scoped by `TenantScope`. A cross-tenant id 404s (safe failure), never resolves another tenant's row.
- Tenancy re-init across jobs relies on `QueueTenancyBootstrapper` (see [[stancl-queue-bootstrapper]]). Each job receives ONLY the import_run_id string; tenant context comes solely from the queue payload's tenant_id.
- Rows cache path is tenant-namespaced: `imports/{tenantId}/{importRunId}.rows.json` (`RunImportJob::cachePath(tenant('id'), runId)`), used identically by coordinator write, chunk read, finalizer cleanup. No cross-tenant collision/read.
- Uploaded file path also tenant-namespaced: `imports/{tenantId}/...` (`ImportController@executeImport`).
- Every manual query on a tenant table includes `->where('tenant_id', tenant('id'))`: `FinalizeImportJob::buildBankBalanceDelta` (BankAccount), `RowImporter` (Payment idempotency, Unit natural key, UnitUser, BankAccount), `ReferenceResolver` (all tenant catalogs). Central catalogs (PaymentMethod, UnitRelationship) correctly queried without tenant_id.
- User lookups in RowImporter correctly strip `UserTenantScope` (User is central) — `User::withoutGlobalScope(UserTenantScope::class)`. Matches stancl-tenancy §9.
- `ChargeStatusService::recomputeUnitAggregates` operates on `affectedUnitIds` accumulated by chunk jobs (already tenant-scoped) and queries Charge/Unit (tenant-scoped models) — defense in depth: even if scope absent, the IDs belong to the run's tenant.

**Test rigor (CrossTenantIsolationTest):** genuinely rigorous, not false-confidence. It (1) end-to-end imports for tenant A and asserts ZERO rows under tenant B via `withoutGlobalScope(TenantScope)` counts (so a leak is visible, not hidden by the scope); (2) asserts `QueueTenancyBootstrapper::getPayload('database')` carries A's id and never B's; (3) proves a chunk re-scoped to tenant B cannot resolve A's run. Sync queue DOES exercise the payload+re-init path (SyncQueue raises JobProcessing/JobProcessed).

No CRITICAL or HIGH tenant-isolation findings. Minor/defensive notes only (best-effort cleanup runs under tenant scope so paths resolve correctly; recompute relies on accumulated IDs being correct).
