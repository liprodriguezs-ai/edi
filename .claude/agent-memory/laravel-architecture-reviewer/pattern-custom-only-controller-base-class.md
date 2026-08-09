---
name: pattern-custom-only-controller-base-class
description: A controller with ZERO Orion CRUD routes (pure custom actions, no $model) should extend Illuminate\Routing\Controller, not QCH\Core\Http\Controllers\Controller — precedent is ConfigurationController.
metadata:
  type: project
---

laravel-backend-architecture skill rule 1 says controllers "SIEMPRE extienden `QCH\Core\Http\Controllers\Controller`... y definen `$model` y `$keyForPermission`" — but that rule assumes the controller has an Orion CRUD resource. `Modules/Configuration/app/Http/Controllers/ConfigurationController.php` (≈1800 lines, 100% custom actions, no Orion resource at all) correctly extends the LEAN `Illuminate\Routing\Controller` instead, with its own local `requireAbility()` helper for per-method ability checks.

Found violating this in the `delinquency-restrictions` review: `DelinquencyRestrictionController` (`Modules/Collections/app/Http/Controllers/DelinquencyRestrictionController.php`) extends `QCH\Core\Http\Controllers\Controller` (the Orion base) despite having zero Orion routes and never setting `$model`/`$keyForPermission`. Works today (Orion's CRUD dispatch is never invoked so the missing `$model` never errors) but is dead weight and misleading — reads as if the endpoint goes through `TraitController::authorize()`'s ability-mapping, when it deliberately doesn't (per its own docblock, `/my-restrictions` is intentionally ability-free, self-scoped to the caller's own units).

**Review rule going forward:** for any NEW controller, check whether it registers via `Orion::resource(...)` anywhere in the module's `routes/api.php`. If not (pure custom-action controller, like `ConfigurationController`), it should `use Illuminate\Routing\Controller;`, not the Orion-flavored core base. Flag WARNING if a custom-only controller extends the Orion base with no `$model`.

Related: [[authz-controller-override-not-policy]] — per-record authz in this stack lives in controller `authorize()` overrides, which only applies to Orion-base controllers anyway; a pure-custom controller like this one gates ability manually per method (`requireAbility()`/`$this->authorize('ability', Model::class)`), reinforcing that it doesn't need the Orion base.
