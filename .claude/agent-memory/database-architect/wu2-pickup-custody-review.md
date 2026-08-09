---
name: wu2-pickup-custody-review
description: WU-2 adversarial review findings for Package module pickup & custody operations
metadata:
  type: project
---

WU-2 (pickup codes, handover, lifecycle, my-packages) reviewed 2026-06-29. Key findings:

**WARNING — Handover notification single-unit assumption (PackageService.php ~line 264)**
`$packages->first()?->unit_id` only notifies residents of the FIRST package's unit. API accepts cross-unit `package_ids` with no validation. UI flow (verify-code → handover) always produces single-unit, so latent in practice. Fix: unique unit IDs across packages, notify each; or add FormRequest validation that all ids share same unit.

**WARNING — Concurrent pickup_code race: unhandled QueryException (PackageService.php ~line 63)**
`Package::where('pickup_code', $code)->exists()` + insert are not atomic. Two simultaneous requests can both pass the exists() check for the same code; partial unique index catches the duplicate but throws a PostgreSQL constraint violation (unhandled → 500). With ~852B possible codes and typical cluster load, extremely unlikely but code path exists. Fix: catch QueryException on save and retry code generation.

**WARNING — Media files copied inside DB::transaction (PackageService.php ~lines 249-259)**
`addMedia()->preservingOriginal()->toMediaCollection()` inside handover's transaction copies files to disk (filesystem op) before the transaction commits. If the transaction rolls back after some iterations, DB records roll back but filesystem copies remain (orphaned). Low probability in practice (pre-flight checks rule out most failure causes). Design-accepted tradeoff per design.md §5.

**WARNING — Missing verify-code tenant isolation test (PackagePickupTest.php)**
Tasks 2.4.1 implies tenant isolation for verify-code. Test for my-packages isolation exists; no equivalent test that a Tenant A guard scanning a Tenant B code gets 404. BelongsToTenant scope on Package makes the code work correctly, but the coverage gap is a security path without test.

**SUGGESTION — `request->input('package_ids')` vs `validated()` (PackageController.php ~line 162)**
Bypasses the FormRequest chain; correct only because validated() ran first. Use `$request->validated('package_ids')` for consistency.

**SUGGESTION — TenantCatalogSeeder catalog names differ from spec (lines 127-143)**
Types seeded as {Caja pequeña, Caja grande, Sobre/paquete plano, Bolsa, Otro} vs spec {Sobre, Caja, Voluminoso, Perecedero, Frágil}. Carriers: J&T Express added, Correos de México absent. Admin-editable, no code branches. Low impact.

**SUGGESTION — PackageReassignRequest missing `exists:unit` rule**
`unit_id` validated as `required, uuid` only; service does tenant-scoped Unit::find() which handles it correctly, but FormRequest-level check would surface cleaner validation errors.

**Verdict: fix-then-ship.** Data integrity is sound; notification latent bug + missing test coverage drive the verdict.

**Why:** Three real correctness issues (notification gap, race, missing test) none of which affect data integrity but two of which affect user-observable behavior.
**How to apply:** If WU-3/WU-4 build on handover, fix the multi-unit notification before WU-4 adds retention that also calls notifyUsers.
