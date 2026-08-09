# Plan 021: Tests de aislamiento cross-tenant para Charge y Payment

> **Executor instructions**: Follow this plan step by step. Run every
> verification command before moving on. On any STOP condition, stop and report.
> When done, update your row in `plans/README.md`.
>
> **Drift check (run first)**:
> `git -C backend diff --stat e41e668..HEAD -- tests/Feature/Security/TenantIsolationTest.php`
> Ante cambios, comparar excerpts; ante mismatch, STOP.

## Status

- **Priority**: P1
- **Effort**: S
- **Risk**: LOW (solo tests; cero código de producción)
- **Depends on**: none
- **Category**: tests
- **Planned at**: backend submodule `e41e668`, 2026-06-12

## Why this matters

El invariante más crítico del sistema (una sola BD compartida, aislamiento por
fila vía `TenantScope`) está testeado para Unit, BankAccount y CommunityProject,
pero NO para los dos modelos financieramente más sensibles: `Charge` y
`Payment`. Una regresión que remueva `BelongsToTenant` de esos modelos, o una
query que los lea con `withoutTenancy()`, expondría los registros financieros de
un clúster a otro y hoy pasaría en silencio.

## Current state

- `backend/tests/Feature/Security/TenantIsolationTest.php` — 3 tests existentes
  con el patrón a imitar (líneas 54–73):

```php
public function test_housing_unit_of_another_tenant_is_not_readable(): void
{
    [$tenantA, $tokenA, $tenantB] = $this->twoTenants();

    $foreignUnitId = $this->inTenant($tenantB, function (): string {
        $unitType = UnitType::findOrFail(Catalog::id(UnitType::class, 'Casa'));
        $unitStatus = UnitStatus::findOrFail(Catalog::id(UnitStatus::class, 'Activa'));

        return Unit::create([
            'unit_type_id' => $unitType->id,
            'number' => '999',
            'unit_status_id' => $unitStatus->id,
            'ownership_share' => 1.0,
        ])->id;
    });

    $this->tenantRequest($tokenA, $tenantA)
        ->getJson("/api/unit/{$foreignUnitId}")
        ->assertStatus(404);
}
```

Los helpers `twoTenants()`, `inTenant()`, `tenantRequest()` y `seedTenant()`
viven al final del MISMO archivo — reusarlos tal cual.

Para construir un `Charge`/`Payment` válido en el tenant B, copiar el setup
mínimo de los tests de Collections existentes:
`backend/tests/Feature/Collections/` (p.ej. `CollectionsChargeMutationsTest.php`
o `CollectionsPaymentManualAndGuardsTest.php` crean charge_concept →
charge_batch → charge → payment). Leer UNO de esos archivos y extraer el camino
más corto de creación de modelos (no pasar por HTTP para el setup; crear por
modelo dentro de `inTenant`).

Rutas a atacar (Orion `show`): `GET /api/charge/{id}` y `GET /api/payment/{id}`.
Confirmar nombres exactos con `grep -rn "Orion::resource" backend/Modules/Collections/routes/api.php`.

## Commands you will need

| Purpose | Command | Expected on success |
|---------|---------|---------------------|
| Test filtrado | `cd backend && php artisan test --filter=TenantIsolationTest` | 5 pass (3 viejos + 2 nuevos) |
| Formato | `cd backend && vendor/bin/pint --dirty` | exit 0 |

## Scope

**In scope**:
- `backend/tests/Feature/Security/TenantIsolationTest.php` (agregar 2 tests)

**Out of scope**:
- Cualquier código de producción. Si un test nuevo FALLA (tenant A obtiene 200
  sobre un recurso de tenant B), eso es un leak real: STOP y reportar como
  hallazgo urgente — NO debilitar el test para que pase.

## Git workflow

- Commit dentro del submódulo `backend/`. Ej:
  `test(security): pin cross-tenant isolation for charge and payment`.

## Steps

### Step 1: `test_collections_charge_of_another_tenant_is_not_readable`

Dentro de `inTenant($tenantB, ...)`: crear el grafo mínimo
(charge_concept con su periodicity/amount_mode del catálogo → charge_batch →
charge con `expected_amount` en minor units y `charge_status_id` del catálogo
central `ChargeStatus::PENDING`). Luego, como tenant A:
`getJson("/api/charge/{$foreignChargeId}")->assertStatus(404)`.

**Verify**: `php artisan test --filter=test_collections_charge` → 1 pass.

### Step 2: `test_collections_payment_of_another_tenant_is_not_readable`

Igual, agregando un `payment` (necesita `bank_account` — copiar el setup del
test de BankAccount ya presente en este archivo) con
`payment_status_id = PaymentStatus::CONFIRMED` y `total_amount` en minor units.
Como tenant A: `getJson("/api/payment/{$foreignPaymentId}")->assertStatus(404)`.

**Verify**: `php artisan test --filter=TenantIsolationTest` → 5 pass.

## Test plan

Cubierto arriba (este plan ES tests). Patrón estructural: los 3 tests
existentes del mismo archivo.

## Done criteria

- [ ] `php artisan test --filter=TenantIsolationTest` → 5/5 pass
- [ ] Los 2 tests nuevos crean los registros DENTRO de `inTenant($tenantB, ...)` y asertan 404 desde el token de A
- [ ] `vendor/bin/pint --dirty` → exit 0
- [ ] `plans/README.md` row updated

## STOP conditions

- Un test nuevo da 200 en vez de 404 → LEAK CROSS-TENANT REAL: reportar de
  inmediato con el modelo afectado; no "arreglar" el test.
- El setup mínimo de Charge/Payment supera ~80 líneas por test (señal de que
  falta un factory/helper): reportar y proponer primero el helper (ver plan 023).
- Las rutas `charge`/`payment` no existen con ese nombre en Orion.

## Maintenance notes

- Cuando se agreguen módulos nuevos con datos sensibles, agregar su test espejo
  acá — este archivo es el contrato del aislamiento.
- Relación con plan 023: si ese plan extrae el trait `WithTenantSetup`, estos
  tests migran al trait junto con el resto del archivo.
