---
name: direct-charge-concepts
description: How cobro-directo charge concepts are seeded (OTHER-only), why domain concepts are NOT pre-seeded, and how OTHER is hidden from the /selects concept picker
metadata:
  type: discovery
---

# Direct-charge (cobro directo) concepts — seeding + picker exclusion (2026-07-03)

## Seeding: ONLY the technical OTHER concept is pre-seeded
`TenantCatalogSeeder::seedDirectChargeConcepts()` (backend/database/seeders/TenantCatalogSeeder.php)
seeds ONE `charge_concept`: code `OTHER`, name "Otro / Concepto vario", in the protected
`Servicios / Eventuales` category (`ChargeConceptCategory::KEY_SERVICES`, HasCatalogKey — key
immutable, category undeletable).

- DOMAIN concepts (access card, hall reservation, fine, …) are **NOT** pre-seeded. By design
  (user decision 2026-07-03) each SOURCE module creates its own concept when needed (e.g. Amenities
  auto-creates its hall-reservation concept). Earlier a version seeded 4 concepts
  (ACCESS_CARD_REPLACEMENT, HALL_RESERVATION, FINE, OTHER) — those 3 domain rows were REMOVED from
  the seeder and pruned from all existing dev tenants (they had no charges/policies).
- `OTHER` exists only to satisfy the NOT-NULL `charge.charge_concept_id` FK for the free-text
  ("Otro") path. `DirectChargeService::resolveOtherConceptId()` resolves it server-side via
  `ChargeConcept::where('code','OTHER')->first()` — it does NOT go through /selects.
- `ChargeConcept::CODE_OTHER = 'OTHER'` constant added; `ChargeConcept::PROTECTED_CODES = [self::CODE_OTHER]`
  (only OTHER is undeletable — the `deleting` guard throws ValidationException for protected codes).
- `charge_concept.code` is NOT NULL + `unique(tenant_id, code)` → only ONE row can ever be OTHER.
- Backfill: `collections:backfill-direct-charge-concepts` re-runs `seedDirectChargeConcepts()` per
  tenant (now seeds only OTHER); idempotent (firstOrCreate).

## OTHER is hidden from the /selects concept picker AT SOURCE
`AngularController::selects()` (backend/app/Http/Controllers/AngularController.php) returns FULL
model rows (`->get()`, no `$hidden` on ChargeConcept) so the payload includes `code`, `is_active`, etc.
Exclusion added via the `$filterEspecial` map (constructor):
`'ChargeConcept' => fn($model, $params) => $model->where('code', '!=', ChargeConcept::CODE_OTHER)`.
Because code is unique + NOT NULL, this hides exactly the one system row, never a legit admin concept.

### Who consumes the CHARGE_CONCEPT /selects catalog (frontend)
- `payment/components/DirectChargeSheet.tsx` — the direct-charge concept picker. It appends a
  SYNTHETIC option `OTHER_CONCEPT_OPTION { id: '__OTHER__', name: 'Otro (especificar motivo)' }`
  that maps to `concept_free_text` and never travels to the backend. The real seeded OTHER concept
  was showing up next to it → the redundancy this exclusion fixes.
- `charge/ChargeListPage.tsx` — the charge-list concept FILTER also sources from CHARGE_CONCEPT
  /selects. SIDE EFFECT of the source-side exclusion: OTHER no longer appears there either (free-text
  direct charges live on OTHER, so you can't filter the list by that bucket anymore). Accepted as OK
  (technical bucket, not a real concept). If ever undesired, switch to a client-side `code === 'OTHER'`
  filter in DirectChargeSheet only (payload already carries `code`).
- Policy/batch concept pickers (`chargePolicy/components/ChargeConceptSelect.tsx`) use Orion
  `GET /charge_concept` via `ChargeConceptResource.list()`, NOT /selects → UNAFFECTED. They still show
  OTHER (an admin could create a policy on OTHER — semantically odd but out of scope; not fixed here).

NO frontend change was required for this task.

## Tests
- `tests/Feature/Collections/DirectChargeTest.php` no longer relies on the seeded ACCESS_CARD_REPLACEMENT;
  a `makeDomainConcept()` helper creates a concept in the KEY_SERVICES category (category id resolved by
  `ChargeConceptCategory::where('key', KEY_SERVICES)->value('id')`, robust vs the Catalog::id heuristic).
- `tests/Feature/Collections/SelectsChargePolicyTest.php` gained
  `test_selects_charge_concept_excludes_the_technical_other_concept` (real concept present, OTHER absent).
