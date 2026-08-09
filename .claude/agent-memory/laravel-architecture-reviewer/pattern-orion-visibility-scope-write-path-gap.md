---
name: pattern-orion-visibility-scope-write-path-gap
description: Visibility/exclusion query scopes applied to Orion index/show are NOT automatically applied to update/destroy/restore — each needs its own buildXFetchQuery override (found in Complaint module review)
metadata:
  type: pattern
---

Orion resolves `index`/`show` via `buildIndexFetchQuery`/`buildShowFetchQuery`, but `update`/`destroy`/`restore`
go through **separate** methods: `buildUpdateFetchQuery`, `buildDeleteFetchQuery`, `buildRestoreFetchQuery`
(`vendor/tailflow/laravel-orion/src/Concerns/HandlesStandardOperations.php`). QCH\Core's base `Controller`/
`TraitController` do NOT wire any of these together — overriding one does not cover the others.

**Concretely observed (Complaint module, `Modules/Complaint/app/Http/Controllers/ComplaintController.php`):**
the controller correctly overrides `buildIndexFetchQuery`/`buildShowFetchQuery` to apply a confidentiality
scope (`Complaint::scopeVisibleTo`), and the custom `transition`/`assign`/`message` actions re-check the same
rule manually (`assertManagerNotExcluded`/`assertVisible`). But the raw Orion `PUT`/`DELETE`/`POST .../restore`
`/complaint/{id}` endpoints were left unguarded — any actor holding the coarse `update.complaint`/`delete.complaint`
Spatie ability (here: all 5 board roles get `update.complaint` via `RolePermissionTableSeeder`'s `$writeNoDelete`)
can bypass the exclusion entirely, including tampering with the very field (`against_unit_id`) that drives the
exclusion (setting it to `null` un-excludes the excluded actor from their own denunciation).

**Checklist for any future module with a "some users must not see/manage rows matching condition X" rule
enforced via a query scope on Orion resources:**
1. Does the controller override `buildIndexFetchQuery` AND `buildShowFetchQuery`? (usually yes, this is the
   pattern people remember)
2. Does it ALSO override `buildUpdateFetchQuery`, `buildDeleteFetchQuery`, `buildRestoreFetchQuery` (or gate
   via `performUpdate`/`performDestroy`/`performRestore`)? — usually forgotten, always check.
3. Does the FormRequest for `update` expose any field that, if edited, could remove the very condition the
   exclusion depends on (e.g. nulling out the FK that triggers exclusion)? That's a compounding self-service
   bypass, not just a visibility leak.

Related: [[pattern-custom-action-authz-and-registration]] (custom write routes need explicit `$this->authorize`)
— same family of "Orion's automatic ability-mapping doesn't cover everything, check each surface individually."

Also found in the same review: the same "exclusion set" query was independently duplicated 3x (model scope +
2 services) instead of extracted once — worth flagging DRY on any module with a similarly-phrased exclusion
rule scattered across scope + services, since divergence between the copies is exactly how gaps like this happen.
