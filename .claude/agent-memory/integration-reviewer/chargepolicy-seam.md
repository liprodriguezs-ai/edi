---
name: chargepolicy-seam
description: charge_policy + surcharge_rules + due_soon_days seam review (2026-06-25). ALIGNED. ChargeConcept split into identity(concept)+behavior(policy). Surcharge bp/minor-unit conversion contract.
metadata:
  type: project
---

# ChargePolicy / surcharge / due_soon_days seam — findings (2026-06-25)

## Context: the concept→policy split
`ChargeConcept` was reduced to IDENTITY only (clave, name, category, is_active).
ALL charge BEHAVIOR (amount_mode, periodicity, amounts, grace_days, due_soon_days,
validity window, surcharge schedule) moved to a NEW `charge_policy` table/module
(1:N per concept). FE module: `frontend/src/modules/chargePolicy/`. BE:
`backend/Modules/Collections/.../ChargePolicy*`. At review time BOTH sides were
UNTRACKED new files (git diff HEAD missed them — had to git-status/grep).

## Verdict: ALIGNED (no contract violations)

### due_soon_days (per-policy override of cluster collections.due_soon_days)
- Column `charge_policy.due_soon_days` smallInteger nullable. Validated min:0 max:60
  in BOTH ChargePolicyCreateRequest:52 and UpdateRequest:39.
- FE `chargePolicyFormSchema.due_soon_days`: z.number().int().min(0).max(60).nullable().optional()
  — range MATCHES. Sent in both create+update payloads (`?? null`). Hydrated from
  `policy.due_soon_days` in buildPolicyDefaults. Plain column → serialized in show, no include needed.
- Snapshot path: ChargeGenerationService copies `$policy->due_soon_days` onto charge.due_soon_days;
  ChargeStatusService::resolveStatusKey uses `$charge->due_soon_days ?? $thresholds['due_soon_days']`.

### surcharge_rules (recargos moratorios, array, sync create/update/delete by id)
- Fields match EXACTLY: from_month(1-120), surcharge_mode_id(uuid), surcharge_value(int≥1),
  recurrence(one_time|per_month_overdue), cap_amount(nullable int), id(update only).
- SURCHARGE_MODE UUIDs match on all 3: FE schema `SURCHARGE_MODE`, BE `SurchargeMode::PERCENT/FIXED`,
  CatalogSeeder seeded rows. PERCENT=c595cf11-8209-4e34-90e5-b7957478ee39, FIXED=6bc44bb8-cde0-4ce8-8c6f-5d10ddf9aa4c.
- RECURRENCES match (ChargeSurchargeRule::RECURRENCES ↔ SURCHARGE_RECURRENCES).
- **Conversion (CRITICAL, correct both directions):** surcharge_value/cap_amount are RAW
  integers on the wire (bigInteger, NOT MoneyIntegerCast). PERCENT mode: value is BASIS
  POINTS (5% = 500). FE buildPolicyPayload: PERCENT→`Math.round(pct*100)`, FIXED→pesosToMinorUnits;
  cap_amount→pesosToMinorUnits. buildPolicyDefaults reverses: bp/100, minorUnitsToPesos. Symmetric.
- Round-trip on EDIT works because all 3 links present: show requests
  `include=...,surcharge_rules,surcharge_rules.surcharge_mode` (matches controller includes()
  whitelist), defaults hydrate them, update re-sends them. If the include were dropped, an
  edit-save would WIPE rules (service treats present `surcharge_rules` as the full desired set).

### Relation includes
ChargePolicyController::includes() = ['concept','amount_mode','periodicity','chargeBatches',
'surcharge_rules','surcharge_rules.surcharge_mode']. FE ChargeRuleFormPage show() requests
exactly these. Surcharge-mode dropdown fed via /selects (Str::studly('surcharge_mode')→SurchargeMode).

## WARNING / SUGGESTION
- Nested 422 errors (`surcharge_rules.N.from_month`, e.g. the `distinct` rule) are NOT mapped
  to inline RHF errors — handleValidationError only maps FLAT knownFields. They fall to the
  generic toast. FE zod does NOT replicate `distinct` (dup from_month) nor a max on percent,
  so those only fail server-side without inline feedback. (ChargeRuleFormPage:106-132)
- FE omits `is_active` per surcharge rule; BE service defaults it true. Harmless but implicit.
- grace_days cap is now CONSISTENT (FE max(365) ↔ BE max:365) — the old chargeConcept 60-vs-365
  mismatch (see collections-seam) is GONE with the split.

## Target selector rework review — 2026-06-30 (ALIGNED, no CRITICALs)

The "Alcance del cobro" rework centralized the target_type→key mapping in
`frontend/src/shared/utils/targetSelector.ts`. This file is the canonical source
of truth for both ChargeBatch and ChargePolicy forms.

### Keys verified ALIGNED (targetSelector.ts ↔ TargetResolver.php)
- `by_type` → `{ unit_type_ids: string[] }` ✅
- `by_tower` → `{ tower_ids: string[] }` ✅
- `by_floor` → `{ floors: string[] }` (no tower_ids; backend defaults [] = all towers) ✅
- `by_feature` → `{ unit_feature_ids: string[] }` (OR semantics) ✅
- `single_unit` → `{ unit_id: string }` (scalar, not array) ✅
- `manual` → `{ unit_ids: string[] }` ✅

### by_feature OR: byFeature uses whereHas + whereIn → union semantics. Empty array falls back to allActive() (documented, Zod guards empty on submit).

### TARGET_TYPES: both sides identical 8-value set; by_floor/single_unit removed from UI only.

### knownFields dead entries (WARNING): ChargeRuleFormPage.tsx:127–143 lists
target_unit_type_ids, target_tower_ids, target_floor, target_unit_ids,
target_feature_ids, target_unit_id as knownFields. Backend never emits these
as error keys (it only returns `target_type` or `target_selector`). Dead code,
misleading to future devs.

### ChargeBatch form has no inline 422 mapper (WARNING): only global toast.
Policy form has rich setError mapper. Preexisting gap, not introduced here.

### Policy update deliberately omits target_type/target_selector (by design):
buildPolicyUpdatePayload sends NO target fields. ChargePolicyUpdateRequest has
NO rules for them. ChargePolicy.$fillable excludes them. target_selector lives
on the initial ChargeBatch (is_initial=true), which is write-once (create only).

### Preview endpoint ALIGNED: POST /charge-batch/preview ↔
ChargeBatchResource.preview(). Same shape used by both batch form AND policy
form (BatchPreview component). Returns { count: number }, read as data.count.
