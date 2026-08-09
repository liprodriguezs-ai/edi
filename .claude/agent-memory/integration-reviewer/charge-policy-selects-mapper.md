---
name: charge-policy-selects-mapper
description: POST /selects requires the type to be registered in AngularController::$mapperSelects; charge_policy is NOT registered, so the batch form policy dropdown 500s
metadata:
  type: project
---

The frontend dropdown catalog endpoint is `POST /selects` (NOT Orion). It resolves each top-level key via `Str::studly($key)` against `AngularController::$mapperSelects`. If the studly name is missing from `$mapperSelects`, the controller falls through to `app("App\\Modules\\{$name}\\{$name}")` — a class that does not exist for module models — and throws → **500**, the dropdown loads nothing.

`useCatalogs({ [CatalogType.X]: {...} })` keys map 1:1 to these `/selects` keys.

**Confirmed drift (2026-06-25, chargePolicy/chargeConcept/chargeBatch split review):**
`CatalogType.CHARGE_POLICY = 'charge_policy'` was added to `useCatalogs.ts` and `ChargeBatchFormPage` loads the policy dropdown via `POST /selects { charge_policy: { _sort, _with: 'concept,periodicity' } }`, but `ChargePolicy::class` is NOT in `AngularController::$mapperSelects` (only `ChargeConcept` and the catalog statuses are). → the ONLY way to create a batch (pick a policy) 500s.
Fix: add `'ChargePolicy' => ChargePolicy::class` to `$mapperSelects` + the `use` import in `backend/app/Http/Controllers/AngularController.php`. `_with` nested relations DO work — `selects()` runs `$data->load(explode(',', $request['_with']))` indirectly via ApiHandler; `concept`/`periodicity` are real relations on the model.

**RESOLVED (2026-06-25, cockpit seam review):** `'ChargePolicy' => ChargePolicy::class` is now registered in `AngularController::$mapperSelects` (line ~99). The `ChargeBatchFormPage` policy dropdown (`POST /selects { charge_policy: { _sort, _with: 'concept,periodicity' } }`) resolves correctly. `concept`/`periodicity` carry the `@Relation` annotation on the model (required by laravel-api-handler `_with` whitelist), so the nested eager-load works. This drift is closed.

**Why:** `/selects` is the canonical dropdown source and its registry is a single hand-maintained array — every new `CatalogType` used in a form needs a matching `$mapperSelects` row, or it is a silent 500.
**How to apply:** Whenever a frontend form adds a `useCatalogs` entry for a NEW catalog/model, verify the studly name exists in `AngularController::$mapperSelects`. Orion `includes()` whitelisting is a SEPARATE contract from the `/selects` mapper. NOTE: `_with` nested relations also require a `@Relation` PHPDoc annotation on the relation method (laravel-api-handler), distinct from Orion's `includes()`.
