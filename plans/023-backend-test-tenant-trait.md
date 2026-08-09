# Plan 023: Extraer trait `WithTenantSetup` para el seeding duplicado en tests backend

> **Executor instructions**: Follow this plan step by step. Run every
> verification command before moving on. On any STOP condition, stop and report.
> When done, update your row in `plans/README.md`.
>
> **Drift check (run first)**:
> `git -C backend diff --stat e41e668..HEAD -- tests/`
> Ante cambios estructurales en los tests, re-listar los archivos del Step 1.

## Status

- **Priority**: P3
- **Effort**: M (cambio mecánico pero ancho: ~20 archivos)
- **Risk**: LOW (solo tests; la suite completa es la red)
- **Depends on**: none (idealmente DESPUÉS de 021 para migrar también los tests nuevos)
- **Category**: tech-debt
- **Planned at**: backend submodule `e41e668`, 2026-06-12

## Why this matters

El helper privado que siembra catálogos centrales + seeder por tenant está
copiado y pegado en ~20 archivos de test (verificado por grep). Cuando cambie la
lógica de seeding (nuevo catálogo requerido, rename del seeder), habrá que tocar
los 20. Un trait único elimina el drift.

## Current state

El bloque duplicado, tal como aparece en
`backend/tests/Feature/Chat/ChatModuleTest.php` (idéntico o casi en los demás):

```php
private function seedTenant(Tenant $tenant): void
{
    // Seed central catalogs (UUID-keyed, no tenant_id) outside the tenant context.
    (new CatalogSeeder)->run();

    $tenant->run(function (): void {
        Artisan::call('db:seed', [
            '--class' => TenantDatabaseSeeder::class,
            '--force' => true,
        ]);
    });
}
```

Archivos afectados (lista del grep en planificación — re-generarla en Step 1):
ConfigurationModuleTest, OrionValidationWiringTest, ChatModuleTest,
ChatHttpEnforcementTest, AccessModuleTest, PruneExpiredQrTest, AccessHttpTest,
AccessApprovalFlowTest, EmployeeModuleTest, BoardHttpTest, EmployeeHttpTest,
BoardModuleTest, TenantIsolationTest, AuthorizationGuardTest,
ProjectsModuleTest, ProjectsHttpTest, ProvidersModuleTest,
HousingTestHelpers, UnitPlatformFeaturesTest, UnitModuleTest, y los nuevos
de `tests/Feature/Collections/` (sin commitear aún — incluirlos si ya están).

Algunos archivos tienen además helpers hermanos (`twoTenants()`, `inTenant()`,
`tenantRequest()`, `makeTenant()`) con variaciones menores — el trait debe
absorber la versión más completa (la de
`tests/Feature/Security/TenantIsolationTest.php`).

## Commands you will need

| Purpose | Command | Expected on success |
|---------|---------|---------------------|
| Suite completa | `cd backend && php artisan test` | all pass (mismo número de tests que antes) |
| Conteo de duplicados | `grep -rln "new CatalogSeeder" backend/tests/Feature/ \| wc -l` | 0 al terminar |
| Formato | `cd backend && vendor/bin/pint --dirty` | exit 0 |

## Scope

**In scope**:
- `backend/tests/Support/WithTenantSetup.php` (create — trait)
- Todos los archivos de `backend/tests/Feature/**` que dupliquen el bloque

**Out de scope**:
- Seeders de producción (`CatalogSeeder`, `TenantDatabaseSeeder`) — no tocar.
- `tests/Support/Catalog.php` (helper existente) — se usa, no se modifica.
- Cambiar QUÉ siembra cada test (solo de dónde sale el helper).

## Git workflow

- Commit dentro del submódulo `backend/`. Puede ser un commit por lote de
  módulos migrados o uno solo. Ej:
  `refactor(tests): extract WithTenantSetup trait for tenant seeding`.

## Steps

### Step 1: Censo

`grep -rln "new CatalogSeeder" backend/tests/Feature/` → lista definitiva.
Anotar ANTES el total de tests: `php artisan test | tail -3` (número de tests y
asserts — el "after" debe coincidir).

### Step 2: Crear el trait

`backend/tests/Support/WithTenantSetup.php` con namespace `Tests\Support`,
extrayendo de `TenantIsolationTest`: `seedTenant()`, `makeTenant()` (si existe),
`twoTenants()`, `inTenant()`, `tenantRequest()`. Métodos `protected`. Sin
estado propio del trait.

**Verify**: `php artisan test --filter=TenantIsolationTest` tras migrar SOLO ese
archivo (Step 3 piloto) → pass.

### Step 3: Migración piloto (3 archivos)

Migrar `TenantIsolationTest`, `ChatModuleTest`, `AccessModuleTest`:
`use Tests\Support\WithTenantSetup;` + `use WithTenantSetup;` en la clase,
borrar los helpers locales duplicados. Si un archivo tiene una VARIACIÓN real
del helper (firma o comportamiento distinto), NO forzarla al trait: dejarla
local y anotarla en el reporte final.

**Verify**: `php artisan test tests/Feature/Security/ tests/Feature/Chat/ tests/Feature/Access/` → pass.

### Step 4: Resto de archivos, por módulo

Mismo tratamiento, corriendo el test del módulo tras cada lote.

**Verify**: `php artisan test` → all pass, MISMO conteo de tests que el censo
del Step 1. `grep -rln "new CatalogSeeder" backend/tests/Feature/ | wc -l` → 0.

## Test plan

No hay tests nuevos: el criterio es que la suite completa pase con el MISMO
número de tests y asserts que antes de empezar.

## Done criteria

- [ ] `php artisan test` → all pass, conteo idéntico al censo inicial
- [ ] `grep -rln "new CatalogSeeder" backend/tests/Feature/` → vacío
- [ ] Trait único en `tests/Support/WithTenantSetup.php`
- [ ] `vendor/bin/pint --dirty` → exit 0
- [ ] `plans/README.md` row updated

## STOP conditions

- Más de 3 archivos tienen variaciones REALES del helper (no copia idéntica):
  el trait único es el approach equivocado; reportar las variantes encontradas.
- La suite pierde tests respecto del censo (algo dejó de descubrirse/correr).

## Maintenance notes

- Tests nuevos que necesiten tenant sembrado: `use WithTenantSetup` — nunca
  copiar el bloque de nuevo. Vale agregarlo a la doc de testing del proyecto.
