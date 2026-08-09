---
name: charge-policy-scope-date-editability
description: charge_policy has NO target_type/target_selector column (lives on charge_batch); today's edit-lock is unconditional and inconsistent between scope and dates; two confirmed frontend bugs (ManualUnitPicker ignores disabled; eventual-policy edit hydrates empty scope/dates)
metadata:
  type: discovery
---

Investigated 2026-07-03 for a requirement "scope + dates of a charge_policy should be
editable only while it has NOT generated any charge_batch/charge yet". Findings:

## Architecture fact (the root of the confusion)

`charge_policy` table/model has **NO `target_type`/`target_selector` columns at all**
(`backend/Modules/Collections/app/Models/ChargePolicy.php:36-51` fillable list — absent).
The "alcance" is stored ONLY on `charge_batch`:

- PERIODIC policy -> the `is_initial=true` template batch
  (`ChargeGenerationService::createInitialTemplate`, app/Services/ChargeGenerationService.php:183-204).
  The cron's `cloneInitialBatch` copies `target_type`/`target_selector` FROM THE TEMPLATE,
  never from the policy (ChargeGenerationService.php:288-291).
- EVENTUAL/DIRECT policy -> the single one-shot batch, always `is_initial=false`
  (`generateEventualBatch`, ChargeGenerationService.php:245-267). No template ever exists.

So "editing scope of a policy" is really "editing the associated charge_batch row" -- there
is no policy column to update. Any fix must decide where the writable value lives (see plan).

## Current backend state (verified)

- `ChargePolicyUpdateRequest` (app/Http/Requests/ChargePolicyUpdateRequest.php) has **no
  rules for `target_type`/`target_selector`/`issued_at`/`due_date`** -- absent entirely, so
  even if the frontend sent them they never survive `$request->validated()` and never reach
  `ChargePolicyService::update()`'s `Arr::only(...)` allow-list (Services/ChargePolicyService.php:185-189,
  list excludes them too). Scope is blocked categorically, NOT conditionally on generated state.
- `valid_from`/`valid_to` (real ChargePolicy columns) are in the update rules as plain
  `sometimes|nullable|date` (ChargePolicyUpdateRequest.php:42-43) with **NO gate at all** on
  whether batches/charges already exist -- always editable for periodic policies. Verified this
  is actually safe today: `GenerateMonthlyChargesCommand::activePoliciesFor` only reads
  `policy.valid_from/valid_to` to decide FUTURE period generation
  (Console/Commands/GenerateMonthlyChargesCommand.php:166-179); it never retro-touches
  already-generated batches. So shrinking/extending the window post-generation is a
  legitimate, low-risk admin action today -- blocking it categorically once charges exist
  (as literally requested) would be a functional regression (no way to end a recurring fee
  early without deleting the policy).
- Separate, ungated risk on a DIFFERENT surface: `ChargeBatchController::performUpdate`
  (Http/Controllers/ChargeBatchController.php) lets `PUT /charge-batch/{id}` edit
  `target_type`/`target_selector`/`issued_at`/`due_date` on **any** batch (including one
  with charges already) with **zero check** for existing charges. Not currently wired to any
  edit UI, but if it ever is, it would silently desync stored target vs. already-charged units.

## Current frontend state (verified) -- confirmed inconsistent, two real bugs found

- `ChargeRuleFormPage.tsx` disables `SingleTargetSelector` unconditionally in edit:
  `disabled={isSaving || isEdit}` (line 453) with a FIXED Callout claiming "El alcance y las
  fechas del lote inicial no se pueden cambiar despues de crear la regla" (lines 433-437) --
  shown whenever `isEdit`, never conditional on whether charges exist.
- That Callout is **misleading for periodic policies**: `PolicyConfigForm.tsx`'s "Vigencia"
  section (valid_from/valid_to) is rendered with `disabled={isSaving}` ONLY -- no `isEdit` gate
  (PolicyConfigForm.tsx:197,204) -- so dates ARE freely editable in edit mode, contradicting the
  banner. This mismatch is very likely why the user perceives "bloquea/permite de forma
  inconsistente".
- **Bug A (confirmed):** `ManualUnitPicker` (modules/chargeBatch/components/ManualUnitPicker.tsx)
  has NO `disabled`/`isDisabled` prop in its interface and never forwards one to its internal
  `SearchSelect`. `SingleTargetSelector.tsx` invokes it at line ~450 WITHOUT passing `disabled`
  at all -- contrast with the `single_unit` branch a few lines above, which correctly forwards
  `disabled={disabled}` to `UnitCombobox` (SingleTargetSelector.tsx:439). Net effect: when
  `target_type === 'manual'`, the multiselect stays fully interactive in edit mode even though
  the parent is "locked" -- the user can add/remove units despite the UI claiming otherwise.
- **Bug B (confirmed, untested):** `buildPolicyDefaults.ts`'s `batchScopeFromPolicy` (line 116-119)
  only looks for a batch with `is_initial === true` to hydrate scope+dates in edit mode. EVENTUAL
  (direct) policies **never have an `is_initial=true` batch** (always false, per
  `generateEventualBatch`) -- so for an eventual policy, `initial` is always `undefined` and the
  function falls back to `emptyBatchScope('')`: `target_type: 'all'`, all target arrays empty,
  `issued_at: ''`, `due_date: ''`. Editing an EVENTUAL policy therefore shows a WRONG "Todas las
  unidades" scope and BLANK dates instead of the real one-shot batch's actual values -- while also
  being disabled, so the user can't tell it's wrong without inspecting the DB. No test in
  `buildPolicyDefaults.test.ts` covers the eventual/edit path (only periodic `is_initial:true`
  and the empty/no-batches case are tested).

## How to detect "already generated charges" (for conditional editability)

Two candidate relations already exist on `ChargePolicy` (both used together today in
`ChargePolicyController::performDestroy`, Http/Controllers/ChargePolicyController.php:73-81):

- `$policy->charge_batches()->exists()` -- hasMany ChargeBatch (Models/ChargePolicy.php:100-103).
  **Misleading as the sole gate**: for a periodic policy this is TRUE almost immediately after
  creation even with a FUTURE `valid_from` (only the template batch exists, 0 charges) -- see
  `ChargePolicyService::create` branch when `$policy->valid_from?->isFuture()` (Services/ChargePolicyService.php:78-89),
  which creates ONLY the template via `createInitialTemplate` (no charges yet).
- `$policy->charges()->exists()` -- hasMany Charge (Models/ChargePolicy.php:123-126, FK
  `charge.charge_policy_id`, direct nullable column per migration `2026_05_31_154423_create_charge_table.php:20`).
  This is the TRUE "has this policy ever billed anyone" signal and matches the user's stated
  reasoning ("para no romper cargos existentes"). Recommend using `charges()->exists()`, not
  `charge_batches()->exists()`, as the lock condition -- otherwise a policy with a future
  `valid_from` (template-only, zero charges) would be locked before it ever bills anyone, which
  contradicts the intent.
- The realistic window where a SAVED policy has ZERO batches at all is `is_active=false` at
  creation time (the `if ($policy->is_active) { ... }` gate in `ChargePolicyService::create`,
  Services/ChargePolicyService.php:73). Default `is_active` in the create form is `true`
  (buildPolicyDefaults.ts:168), so reaching "paused, nothing generated yet" requires an explicit
  toggle-off before first save.

## Adjacent gap discovered (not asked, but relevant to "activate later")

`ChargePolicyService::update()` never triggers batch generation even when `is_active` flips
false->true (Services/ChargePolicyService.php:182-198 -- only fills columns + saves, no
generation call). For a PERIODIC policy created with `is_active=false` (so no template was ever
made), the daily cron's `initialBatchFor()` requires a pre-existing `is_initial=true` batch and
just **warns + skips** if none exists (Console/Commands/GenerateMonthlyChargesCommand.php:103-111,186-192).
Net effect: a paused-at-creation periodic policy, once reactivated via edit, silently NEVER
generates anything again -- a real, separate bug worth fixing in the same pass if the team
decides to allow "edit while nothing generated yet" (that state becomes reachable/attractive
once this feature exists).

## Plan sketch for conditional editability (not applied -- diagnosis only)

Backend:

1. Add a computed flag on `ChargePolicy` (e.g. `has_billed` via `charges()->exists()`, exposed
   through `Http/Resources` or as an appended attribute) so the frontend doesn't have to infer
   it from `charge_batches[]` shape.
2. Decide where scope lives when editable: either (a) keep it on the template batch and let
   `ChargePolicyController`/`ChargePolicyService::update()` route target_type/target_selector
   changes to the `is_initial=true` batch when `!has_billed` (periodic) or to the single batch
   (eventual, only reachable if `is_active` was false at creation), or (b) add
   target_type/target_selector columns directly to `charge_policy` as the single source of
   truth and change `cloneInitialBatch`/generation to read from the policy instead of the
   template batch (bigger, cleaner, but a schema change + backfill).
3. Gate on `charges()->exists()`, NOT `charge_batches()->exists()` -- see reasoning above.
4. Keep `valid_from`/`valid_to` unconditionally editable for periodic (current behavior) OR, if
   the team insists on the literal rule, gate only `valid_from` (start) while leaving `valid_to`
   (end) always editable -- shortening/extending a recurring window after billing has started is
   a legitimate, common admin action; blocking it entirely is a functional regression.
5. Fix the adjacent `is_active` false->true gap (item above) in the same change, since it becomes
   directly relevant once "edit while nothing generated" is a real, reachable, supported state.

Frontend:

1. `ChargeRuleFormPage.tsx`: replace the unconditional `isEdit` gate on `SingleTargetSelector`
   (line 453) and the Callout (lines 433-437) with a flag from the backend (`policy.has_billed`
   or similar), make the Callout conditional/accurate per mode (periodic dates message !=
   eventual dates message != scope message).
2. Fix Bug A: add a `disabled` prop to `ManualUnitPicker` and forward it from
   `SingleTargetSelector.tsx` (mirror the `single_unit` branch's `disabled={disabled}` pattern)
   -- required regardless of the conditional-editability feature; it's a lock-bypass bug today.
3. Fix Bug B: `batchScopeFromPolicy` must fall back to the (single) non-initial batch when no
   `is_initial=true` batch exists (i.e., eventual policies) instead of silently defaulting to
   `emptyBatchScope`.

Risks to flag back to the user/team:

- Allowing scope edits on an ACTIVE periodic policy that already has a template (but zero
  charges, future valid_from) means editing the template batch's target -- safe, nothing
  billed yet.
- Allowing scope edits AFTER `charges()->exists()` is true is exactly what the user wants to
  block -- agreed, no counter-argument found.
- Blocking `valid_from`/`valid_to` categorically once billed is a regression vs. today's
  behavior (see above) -- needs an explicit product decision, not just an engineering default.
- This is schema-touching / multi-file (controller + request + service + generation service +
  frontend form + a computed flag) -> substantial work, route through SDD once the team decides
  the exact rule (especially the dates nuance and the target-storage-location question).
