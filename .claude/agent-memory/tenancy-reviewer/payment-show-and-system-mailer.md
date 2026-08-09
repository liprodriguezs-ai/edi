---
name: payment-show-and-system-mailer
description: Cambio 3 (PaymentController::authorize override for resident payment show) + Cambio 5 (SystemMailer system-email bypass) — both isolation-SAFE; patterns to not re-flag
metadata:
  type: project
---

# PaymentController::authorize override + SystemMailer (review 2026-06-28) — SAFE

## Cambio 3 — PaymentController::authorize override (Collections) — CORRECT
- Relaxes ONLY `show` (single Payment instance). Orion `show()` resolves `$entity` via the
  tenant-scoped `findOrFail` (Payment `BelongsToTenant`) BEFORE `authorize($entity)` → foreign-tenant
  id = 404 before auth runs. Verified in vendor HandlesStandardOperations.php:350-352.
- `index/store/update/delete/restore` pass the CLASS string or non-read abilities, so `$arguments
  instanceof Payment` is FALSE (index passes Payment::class) OR `$ability !== 'read.payment'` →
  fall through to `parent::authorize` (strict `tokenCan` gate). Confirmed Orion index→resolveAbility('index')='read.payment' but arg is class-string.
- Ownership check `UnitUser::where(unit_id,user_id)->whereNull('end_date')->exists()` — UnitUser is
  `BelongsToTenant` (TenantScope), so it can never cross tenants. `end_date NULL` = active resident (correct).
- `tokenCan('read.payment')`/`tokenCan('*')` broad-read detection is IDENTICAL to base TraitController::authorize
  and to sibling submit()/statement() → consistent gating. Super-admin/owner carry granular `read.payment`
  (project does NOT grant `*`); the `*` branch is harmless belt-and-suspenders.
- Test PaymentShowAccessTest covers all leak vectors: own-unit allow, other-unit-same-tenant 403,
  ex-resident 403, board broad allow, cross-tenant 404. SUGGESTION only: no test asserts residents still
  get 403 on index/search (broad-only) — code is correct, coverage gap is cosmetic.

## Cambio 5 — SystemMailer (Email) — CORRECT, no cross-tenant recipient/render leak
- `SystemMailer::send($to, Mailable)` = thin `Mail::to($to)->send()`. Recipients are ALWAYS explicit
  (caller-supplied), never resolved by a cross-tenant query. Bypasses notification engine on purpose
  (must-deliver security/legal mail).
- Recipients: reset → `$user->email` (own, central); invitation → `$invitation->email` (central row,
  scoped by management queries); welcome/transfer → `$user->email` (own). No leak.
- NONE of the system mailables implement ShouldQueue → rendered synchronously in-request, so no queue
  serialization that would drop tenant context.
- Render data is central/config only EXCEPT ResidentInvitationMail context: `cluster_name = tenant()?->name`
  (current tenant), `unit_label = $unit->full_identifier` (TenantScope'd Unit), inviter name. Built in the
  inviter's own tenant context (inviteToUnit / resend resolve unit via TenantScope). No other tenant's data.
- AccountOwnerNotificationService `notify()` resolves `$tenant = $user->tenants()->first()` ONLY to count
  users (first-vs-transfer); tenant is NOT rendered into the email. Safe.
- ResetPasswordMail runs on PUBLIC forgot-password route (no tenancy init) but renders no tenant data → safe.

## Adjacent refactor (email unification) — no tenancy impact
- Command/Job diffs (SendChargeReminders, GenerateReceiptPdfJob, GenerateReportJob, etc.) only change
  `click_action` deep-link strings (e.g. 'charge' → '/statement'). sendForTenant/alertForTenant loops unchanged.
- NotificationUserController: REMOVED `unreadCount` endpoint (was already per-user + NotificationUser TenantScope'd).
  Remaining `mark-all-read` keeps full chain auth:sanctum→rejectRefreshToken→authActive→initializeTenancy. No regression.
