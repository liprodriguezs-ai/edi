---
name: resident-invitation-isolation
description: Resident Invitation feature (feat/resident-invitation) — tenant isolation review; UserInvitation central-table query rules and the block() cross-tenant gap
metadata:
  type: project
---

# Resident Invitation (Housing) — isolation review (2026-06-27)

## Classification (confirmed)
- `user_invitation` (`Modules/User/Models/UserInvitation`) is CENTRAL — `use CentralConnection`, NO TenantScope, has its own `tenant_id` column. Every MANAGEMENT query MUST filter `->where('tenant_id', tenant()->getTenantKey())` manually. Verified done in: controller `index`/`findOwnedInvitation`, service `checkEmail` hasPending, `UserInvitationService::createAndSend` dedup.
- `token_hash` UNIQUE is intentionally GLOBAL (no tenant_id) — it is the public verify/accept lookup key (resolved without tenant context). Correct. No missing tenant-scoped unique in the migration.
- Token lookup `findValidByToken` is intentionally global (public key) — NOT a leak.

## Correct patterns (do NOT flag in future)
- Guards rely on TenantScope'd `Unit::findOrFail` / `UnitUser::findOrFail` (both `BelongsToTenant`) → cross-tenant id = 404. Sufficient as the tenant guard for store/checkEmail/deactivate.
- `checkEmail` uses `User::withoutGlobalScope(UserTenantScope)` (User central) but only exposes `email_registered` (bool) + same-tenant units (UnitUser is TenantScope'd). No cross-tenant account/unit exposure.
- `verifyInvitationToken` uses `$tenant->run()` (Octane-safe) to resolve labels of the invitation's OWN tenant only — intended (recipient is being invited to that cluster).
- `acceptInvitationWithAccount` (authenticated confirm flow) enforces case-insensitive email-match between session user and invitation → 403 if mismatch. Prevents attaching a stranger's account. `tenancy()->initialize()` wrapped in try/finally `tenancy()->end()` — Octane-safe.
- `BlacklistEntry` is `BelongsToTenant`, `BlacklistType` is CentralCatalogModel (`BlacklistType::VISITOR` constant, no tenant filter needed). `UserProfile` is `BelongsToTenant` → `firstOrCreate` inside tenant context is scoped+stamped.

## CRITICAL gap found — ResidentAdminController::block
`Modules/Housing/app/Http/Controllers/ResidentAdminController.php` (block, ~line 36): resolves the target with `User::withoutGlobalScope(UserTenantScope)->findOrFail($userId)` and then sets `auth_active=false` (GLOBAL — all clusters) + deletes tokens + writes a BlacklistEntry naming the user. There is NO check that the target belongs to the actor's current tenant. `block.resident` is granted to the per-tenant `administrator` role (not super-admin-only; it's outside `$permissionsOnlySuperAdmin`/`$superAdminExclusives`, so administrator/account_owner/company_admin get it via whereNotIn). => a cluster A admin can globally disable a user who belongs only to tenant B (cross-tenant denial) and write their PII name into A's blacklist.
Fix: after resolving target, `abort_unless($target->tenants()->where('tenant.id', tenant()->getTenantKey())->exists(), 404)` (User::tenants() = belongsToMany via `user_tenant`). Reconsider whether auth_active should be global vs per-tenant given single global column.
