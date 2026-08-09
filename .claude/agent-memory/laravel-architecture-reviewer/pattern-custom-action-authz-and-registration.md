---
name: pattern-custom-action-authz-and-registration
description: Recurring review checks for new Kivo7 modules — custom-write-action authz, module_system completeness for catalogs, snake_case resource naming, performForceDestroy for FK guards
metadata:
  type: project
---

Four reusable review checks, confirmed against existing modules (Access is the reference pattern Package mirrors).

**1. Custom write actions MUST authorize explicitly.** Orion CRUD (`store/update/destroy`) auto-enforces `create.{key}` via `resourceAbilityMap`. But a custom `Route::post(...)` controller method bypasses Orion's authorize entirely — the route middleware (`auth:sanctum, rejectRefreshToken, authActive, initializeTenancy`) does NOT check permissions. Canonical pattern: `AccessLogController::scanQr` calls `$this->authorize('create.access_log', AccessLog::class)` as line 1 of the method. A custom write action without this = privilege escalation (any authed tenant user can invoke it). Package's `batchRegister` shipped WITHOUT it while the single-intake `store` was gated → asymmetric hole.

**Why:** the route-level middleware stack has no `permission:` entry; authz for custom actions lives inside the method.
**How to apply:** for every non-Orion POST/PUT/DELETE route, grep the target method for `$this->authorize(` or `tokenCan(`. If absent → CRITICAL.

**2. module_system.csv must cover EVERY new module row, including hidden catalogs.** All ~28 catalog modules in modules.csv (central FSM AND tenant-scoped) have exactly one module_system row. A new module's catalogs missing from module_system.csv (Package shipped package_carrier/type/status absent) breaks module→system→tenant access resolution that GET /api/menu and gating rely on.
**How to apply:** for each new UUID in modules.csv, confirm a matching row in module_system.csv. Count mismatch → CRITICAL incomplete registration (CLAUDE.md regla 3).

**3. Orion resource names are snake_case project-wide.** Every multi-word resource uses snake_case (`access_entry_type`, `charge_status`, `bank_account`); skill §2.7 shows `document_type`. Kebab-case (`package-status`, `package-carrier`) is an outlier that drifts the public API contract and the frontend resource basePath. WARNING.

**4. FK-existence delete guards belong in `performForceDestroy`, not `performDestroy`.** Orion splits them: `performDestroy` → `$entity->delete()` (soft), `performForceDestroy` → `$entity->forceDelete()` (physical). A guard `if ($entity->packages()->exists()) abort(422)` in `performDestroy` wrongly blocks SAFE soft-deletes and leaves the actual physical removal (which violates the FK) unguarded — inverted intent. Put it in `performForceDestroy`.

---

**WU-2 (Pickup & Custody) review — 2026-06-29:** Check #1 was correctly applied to ALL six WU-2 actions (`verifyCode`/`handover`/`returnPackage`/`flagIncident`/`reassignUnit` → `authorize('update.package')`; `myPackages` → `authorize('read.package')` + `create.package` staff-exclusion 403). Service-layer placement, single-transaction handover atomicity (validate-all-before-write, rollback test green), tenant isolation via `BelongsToTenant`, and `pickup_code` `$hidden`/`makeVisible` all correct. No CRITICAL/WARNING.

**Module-accepted convention (do NOT re-flag as a violation):** Package custom write actions type dedicated FormRequests in the signature (`handover(PackagePickupRequest)`, `returnPackage(PackageReturnRequest)`, etc.). This deviates from skill §2.10/§2.12 ("custom actions validate inline with `$request->validate`") and from EVERY sibling module (Access/Chat/Collections all use `Illuminate\Http\Request` + inline validation). BUT it matches WU-1's already-accepted `batchRegister(PackageBatchRequest)`, so for the Package module it is an internally-consistent, functionally-correct choice — treat as SUGGESTION-level consistency note, not a defect.

**Setting boolean gates are safe:** `Setting::getValue` → `resolved_value` casts `TYPE_BOOLEAN` via `in_array(strtolower($raw),['true','1','yes'])`, so `'false'` → real `false`. Policy gates like `if ($this->getSetting('package.requires_pickup_signature'))` do NOT suffer the truthy-'false'-string bug.
