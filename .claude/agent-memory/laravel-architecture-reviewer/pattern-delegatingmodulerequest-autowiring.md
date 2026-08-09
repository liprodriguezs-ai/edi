---
name: pattern-delegatingmodulerequest-autowiring
description: DelegatingModuleRequest (core/src/Http/Request/DelegatingModuleRequest.php) auto-wires {Model}CreateRequest/{Model}UpdateRequest into Orion store/update WITHOUT needing a performStore/performUpdate override — supersedes the older explicit-invocation pattern documented in laravel-backend-architecture skill §2.12
type: pattern
---

## Discovery (AmenityReservations S3-S5 review, 2026-07-06)

The laravel-backend-architecture skill §2.12 documents FormRequest wiring as: controller overrides
`performStore`/`performUpdate` and manually calls `app(DealCreateRequest::class)->validated()`.
**This is now outdated for controllers that do NOT override performStore/performUpdate.**

The actual mechanism (verified by reading `core/src/Http/Controllers/TraitController.php` +
`core/src/Http/Request/DelegatingModuleRequest.php` + Orion vendor source):

1. Orion's `resolveRequestClass()` looks for `App\Http\Requests\{Model}Request` (singular,
   no Create/Update split) — this project never has that class, so it falls through.
2. `TraitController::resolveComponents()` catches that fallthrough and binds
   `QCH\Core\Http\Request\DelegatingModuleRequest` instead (see line ~27).
3. `DelegatingModuleRequest::delegateClass()` derives the sibling Requests namespace from
   the CONTROLLER's namespace (`...\Http\Controllers` → `...\Http\Requests`) and builds
   `{ControllerRequestsNamespace}\{class_basename($controller->getModel())}{Create|Update}Request`
   based on the route action (store→Create, update→Update). If that class exists, its
   `rules()`/`messages()`/`attributes()`/`withValidator()` are delegated into Orion's
   validation pipeline automatically.

**Implication for reviews: a plain Orion controller with NO performStore/performUpdate
override, but with a matching `{Model}CreateRequest`/`{Model}UpdateRequest` pair sitting in
the sibling `Http/Requests/` folder, is CORRECTLY wired.** Do not flag "FormRequest is never
invoked / dead code" for this shape — verify by checking:
- controller's `$model` basename matches the FormRequest prefix exactly
- the FormRequest sits in `{ModuleNamespace}\Http\Requests\` (sibling to `Http\Controllers`)
- the controller does NOT declare a custom `protected $request` (which would bypass this)

The explicit `app(XxxRequest::class)->validated()` inside performStore/performUpdate (skill
§2.12 example) is still valid/used when a controller ALSO needs custom business logic in the
hook (e.g. DocumentCollaboratorController delegates to a Service in performStore). For
simple CRUD-only controllers (the 80% case per skill §2.6), the DelegatingModuleRequest
auto-wiring is sufficient and is what most controllers actually rely on.

**Action item**: laravel-backend-architecture skill §2.12 should be updated to document DelegatingModuleRequest
as the primary/default mechanism, with manual invocation as the exception for controllers that
override performStore/performUpdate for other reasons. Flag this doc gap if touching that skill file.
