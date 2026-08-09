---
name: collections-relation-key-drift
description: Two camelCase hasMany relations remain in Collections (chargeBatches, paymentApplications) — include string uses camel, JSON key serializes snake; chargeBatches is seam-read by the frontend
metadata:
  type: project
---

Audited 2026-06-26 (relation-key drift focus). Empirically confirmed Eloquent serialization:
- `backend/vendor/.../Eloquent/Concerns/HasAttributes.php:158` → `public static $snakeAttributes = true;`
- `relationsToArray()` (same file, ~line 392-415) applies `$key = Str::snake($key)` to EVERY relation key.
- NO model in `Modules/`/`app/`/`core/` overrides `$snakeAttributes`.
- Orion serializes via standard `->toArray()` (`vendor/tailflow/laravel-orion/.../HandlesStandardOperations.php:68`); the project Controller/TraitController define NO `jsonResource`; `BaseModel` does NOT override `toArray`.
- ∴ a camelCase relation METHOD always serializes under a snake_case JSON KEY. `Str::snake('chargeBatches')='charge_batches'`, `Str::snake('paymentApplications')='payment_applications'`.

**Implication for the whole repo's belongsTo "drift" theory:** camel belongsTo methods (the old `chargeStatus`, `paymentStatus`, etc.) ALSO serialized snake — so a camel method is NOT a runtime read-break by itself. Plan 034's premise ("Eloquent serializes under the EXACT method name") is inaccurate under default `$snakeAttributes`. Plan 034 nonetheless (correctly, for convention) already converted the Collections belongsTo set to snake — verified live: Charge::charge_status(), Payment::payer_user/payment_method/bank_account/payment_status(), ChargePolicy::amount_mode() are all snake now.

**Two camelCase relations STILL present (out of plan-034 scope, both hasMany):**

1. `ChargePolicy::chargeBatches()` — SEAM-EXPOSED AND READ. Triplet:
   - method camel: `Modules/Collections/app/Models/ChargePolicy.php:100`
   - include whitelist camel: `Modules/Collections/app/Http/Controllers/ChargePolicyController.php:21` (also internal `->chargeBatches()->exists()` line 76)
   - frontend include string camel: `frontend/src/modules/chargePolicy/ChargeRuleFormPage.tsx:84` (`include: '...,chargeBatches,...'`)
   - JSON key serializes snake `charge_batches`; frontend READS snake: `buildPolicyDefaults.ts:90` (`policy.charge_batches`), type `schema.ts:88` (`charge_batches?: IInitialBatch[]`).
   - WORKS today (auto Str::snake). Consistent fix = rename all to `charge_batches` (read stays unchanged). Renaming is a COORDINATED back+front lockstep: rename method ⇒ MUST also flip whitelist (line 21) + internal call (76) + FE include string (84) in same deploy, else `with('chargeBatches')` → BadMethodCallException 500, or FE-only snake → unallowed-include/broken edit hydration.

2. `Charge::paymentApplications()` — ON THE WIRE but NOT read by frontend (latent). Triplet:
   - method camel: `Modules/Collections/app/Models/Charge.php:88`
   - include whitelist camel: `Modules/Collections/app/Http/Controllers/ChargeController.php:30`
   - eager-loaded + serialized in custom action: `PaymentController.php:298` returns `'charges' => $charges` (key `payment_applications` on wire)
   - internal calls: `ChargeController.php:176`, `ChargeService.php:50`, `ChargeGenerationService.php:319` (`whereHas`)
   - frontend reads NOTHING (`payment_applications`/`paymentApplications` absent in frontend/src). Backend-only fix today (5 sites lockstep), no FE coordination. WARNING, not CRITICAL.

Every OTHER Collections include is snake/single-word and method==include==read consistent (verified): charge_status, batch, concept, unit, policy, status, payment_status, payment_method, bank_account, payer_user, applications, media, category, periodicity, amount_mode, surcharge_rules, surcharge_rules.surcharge_mode.

**Why:** laravel-backend-architecture rule 4 says name the method EXACTLY as the desired include/JSON key — never rely on camel→snake auto-conversion. These two hasMany relations violate it; chargeBatches is the only one a frontend dev actually touches.
**How to apply:** Multi-word hasMany relations in this repo MUST be snake_case plural (`charge_batches`, not `chargeBatches`). When auditing a new collection relation, check the triplet (method / include whitelist + FE include string / FE read) — they must ALL be the same snake string. See [[charge-policy-cockpit-seam]] (cockpit show() include does NOT pull chargeBatches; only ChargeRuleFormPage does).
