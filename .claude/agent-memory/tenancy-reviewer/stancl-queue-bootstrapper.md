---
name: stancl-queue-bootstrapper
description: How Stancl QueueTenancyBootstrapper carries tenant_id into queue payloads and re-initializes tenancy per job; sync vs database connection behavior; what makes a queue-tenancy test trustworthy vs false-confidence.
metadata:
  type: pattern
---

# Stancl QueueTenancyBootstrapper — how queued-job tenancy actually works

Source verified at `backend/vendor/stancl/tenancy/src/Bootstrappers/QueueTenancyBootstrapper.php` and `config/queue.php` / `config/tenancy.php`.

**Dispatch side (payload generation):**
- `setUpPayloadGenerator()` calls `$queue->createPayloadUsing(fn($conn) => getPayload($conn))` — but ONLY when the queue is NOT a `QueueFake`. A full `Queue::fake()` (no args) bypasses payload generation entirely.
- `getPayload($connection)` returns `['tenant_id' => tenant()->getTenantKey()]` IF (a) `tenancy()->initialized` is true at dispatch time AND (b) `config("queue.connections.$connection.central")` is falsy. Otherwise returns `[]` (central/no-tenant job).
- In this project the `database` connection has `connection => central` (the DB connection for the jobs TABLE) but NO `'central' => true` key, so `getPayload('database')` DOES inject tenant_id. Do not confuse `connection => 'central'` (where the jobs table lives) with the `central => true` flag (suppresses tenant payload).

**Execute side (re-init):**
- A static `JobProcessing` listener calls `initializeTenancyForQueue($payload['tenant_id'] ?? null)`: if a tenant_id is present it `tenancy()->initialize(find(id))`; if absent it ENDS tenancy (so a non-tenant job never inherits stale context). `JobProcessed`/`JobFailed` revert.
- This listener is registered at provider boot, so it fires for EVERY connection including `sync`.

**Why sync tests are NOT automatically false-confidence here:**
- `SyncQueue::push()` (verified `vendor/laravel/.../SyncQueue.php`) calls `createPayload()` then raises `JobProcessing` (before) and `JobProcessed` (after). So under `queue.default=sync`, the chunk/finalizer jobs DO get a payload with tenant_id AND DO get re-initialized from it by the bootstrapper — they are NOT merely riding the HTTP request's ambient tenant context. A sync test that dispatches a job carrying only an id, and then reads tenant-scoped rows successfully, genuinely exercises the payload+re-init path.
- The trustworthy extra assertions are: (1) assert `getPayload('database')` directly carries tenant A and never tenant B; (2) prove a chunk re-scoped to tenant B cannot resolve tenant A's run (TenantScope blocks it, `withoutGlobalScope` still finds it → proves scope, not deletion, isolates).

**Residual real-worker gap a sync test still cannot cover:** a long-lived `queue:work` worker carrying stale tenant state between jobs. The bootstrapper handles this (ends tenancy when payload has no tenant_id; reverts after each job), but only an integration test with an actual worker, or trust in the bootstrapper, covers it. Acceptable for v1.
