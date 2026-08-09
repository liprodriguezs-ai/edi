# Plan 022: Usar el recompute bulk de agregados en `cancelBatch` (eliminar N+1)

> **Executor instructions**: Follow this plan step by step. Run every
> verification command before moving on. On any STOP condition, stop and report.
> When done, update your row in `plans/README.md`.
>
> **Drift check (run first)**:
> `git -C backend diff --stat e41e668..HEAD -- Modules/Collections/app/Services/ChargeGenerationService.php Modules/Collections/app/Services/ChargeStatusService.php`
> Ante cambios, comparar excerpts; ante mismatch, STOP.

## Status

- **Priority**: P2
- **Effort**: S
- **Risk**: LOW (el método bulk ya existe, ya está testeado y ya se usa en `activateBatch`)
- **Depends on**: none
- **Category**: perf
- **Planned at**: backend submodule `e41e668`, 2026-06-12

## Why this matters

`cancelBatch` recomputa el agregado de estado de cada unidad afectada con UNA
llamada por unidad dentro de la transacción (cada llamada = 2+ queries). Para un
lote que apunta a 500 unidades son ~1.000 round-trips manteniendo locks sobre el
batch y sus charges. El método bulk (`recomputeUnitAggregates`) ya existe y es
exactamente lo que usa `activateBatch` unas líneas más arriba — la corrección es
una línea.

## Current state

- `backend/Modules/Collections/app/Services/ChargeGenerationService.php`:

El patrón CORRECTO en `activateBatch` (línea 151–153):

```php
// Recompute unit aggregates for all affected units (single batch query,
// not one round trip per unit).
$this->chargeStatusService->recomputeUnitAggregates($units->pluck('id')->all());
```

El N+1 en `cancelBatch` (líneas 210–214):

```php
$this->recomputeBatchProgress($batch);

foreach (array_keys($affectedUnitIds) as $unitId) {
    $this->chargeStatusService->recomputeUnitAggregate($unitId);
}
```

- `backend/Modules/Collections/app/Services/ChargeStatusService.php` — firmas
  confirmadas: `recomputeUnitAggregate(string $unitId): void` (línea 79) y
  `recomputeUnitAggregates(array $unitIds): void` (línea 108).

## Commands you will need

| Purpose | Command | Expected on success |
|---------|---------|---------------------|
| Suite Collections | `cd backend && php artisan test tests/Feature/Collections/` | all pass |
| Lifecycle del batch | `cd backend && php artisan test --filter=CollectionsBatchLifecycleTest` | all pass |
| Formato | `cd backend && vendor/bin/pint --dirty` | exit 0 |

## Scope

**In scope**:
- `backend/Modules/Collections/app/Services/ChargeGenerationService.php` (solo el foreach de `cancelBatch`)

**Out of scope**:
- `recomputeUnitAggregate(s)` en `ChargeStatusService` — no tocar.
- El resto de `cancelBatch` (locks, validación de pagos, cancelación de charges).

## Git workflow

- Commit dentro del submódulo `backend/`. Ej:
  `perf(collections): batch unit-aggregate recompute in cancelBatch`.

## Steps

### Step 1: Reemplazar el loop

En `cancelBatch`, sustituir:

```php
foreach (array_keys($affectedUnitIds) as $unitId) {
    $this->chargeStatusService->recomputeUnitAggregate($unitId);
}
```

por:

```php
$this->chargeStatusService->recomputeUnitAggregates(array_keys($affectedUnitIds));
```

**Verify**: `php artisan test --filter=CollectionsBatchLifecycleTest` → all pass.

### Step 2: Suite completa de Collections

**Verify**: `php artisan test tests/Feature/Collections/` → all pass. Después
`vendor/bin/pint --dirty` → exit 0.

## Test plan

Sin tests nuevos: el comportamiento (estado final de las unidades tras
cancelar) ya está cubierto por `CollectionsBatchLifecycleTest` y
`CollectionsStatusCronAndWorstWinsTest`; esos tests deben pasar SIN modificarse
— son la prueba de que el cambio es solo de performance.

## Done criteria

- [ ] `grep -n "recomputeUnitAggregate(" backend/Modules/Collections/app/Services/ChargeGenerationService.php` → 0 matches (solo queda la versión plural)
- [ ] `php artisan test tests/Feature/Collections/` → all pass sin tests modificados
- [ ] `vendor/bin/pint --dirty` → exit 0
- [ ] `plans/README.md` row updated

## STOP conditions

- Algún test de Collections falla tras el cambio: NO ajustar el test;
  significaría que `recomputeUnitAggregates` no es equivalente semántico de la
  versión singular en este contexto — reportar la diferencia observada.
- Los excerpts no coinciden con el código vivo.

## Maintenance notes

- Regla para el reviewer y futuros PRs: dentro de transacciones de Collections,
  TODO recompute multi-unidad usa la versión plural; la singular queda para
  flujos de una sola unidad (pagos individuales).
