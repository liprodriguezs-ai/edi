---
name: collections-slice-a-charge-policy
description: Review del Slice A (concept→policy split) en el módulo Collections — violations, patrones validados y gaps semánticos encontrados en 2026-06-25.
metadata:
  type: project
---

## Contexto

Review arquitectónico del Slice A del módulo Collections. La separación concepto→política
movió `amount_mode`, montos, `grace_days`, `periodicity`, `collection_mode` y ventana de vigencia
de `charge_concept` a la nueva tabla `charge_policy` (1:N por concept).

## Violations encontradas

### CRITICAL

1. **Migración `2026_05_31_154421a_create_charge_policy_table.php` — nombre de archivo con sufijo `a`**
   La migración `charge_policy` usa el nombre `154421a_create_charge_policy_table.php`.
   Laravel ordena migraciones lexicográficamente. `154421a` viene después de `154421` pero
   el separador alfabético es frágil: si algún día alguien añade `154421b` o `1544210`,
   el orden puede corromperse. La convención del proyecto y el skill §12 indican usar
   timestamps en segundos como `2026_05_31_154421_` + número diferente (ej. `154421`→`154421`,
   nueva→`154422`, pero eso ya está tomado). La solución correcta era usar un timestamp
   de segundos genuinamente posterior al de `charge_concept` y anterior al de `charge_batch`
   (ej. `2026_05_31_154421_5` no existe — debió usarse un timestamp real diferente).
   En la práctica funciona hoy pero es técnicamente frágil.

2. **`ChargePolicyController.performStore` invoca `$request->validated()` sin disparar explícitamente `ChargePolicyCreateRequest`**
   El skill §2.12 dice: "La validación se dispara EXPLÍCITAMENTE dentro del hook, antes de llenar la entidad:
   `app(DealCreateRequest::class)->validated()`". Sin embargo, ChargePolicyController.performStore hace
   `$request->validated()` directamente sobre el `OrionRequest`. Esto funciona porque `DelegatingModuleRequest`
   puente-delega a `ChargePolicyCreateRequest`, pero el patrón explícito `app(ChargePolicyCreateRequest::class)->validated()`
   no se usa. Es inconsistente con el skill canónico, aunque funcional.

### MAJOR

3. **`ChargePolicy.chargeBatches()` — camelCase en relación HasMany viola el skill §2.3 / rule 4**
   El skill dice: relaciones de colección → plural (y snake_case implícito por el patrón del proyecto).
   `chargeBatches()` es camelCase. Sin embargo, el proyecto YA tiene `Housing.unitUsers()` en camelCase
   para HasMany, lo que indica que la convención real del proyecto acepta camelCase para HasMany compuestos.
   Esto es una inconsistencia en el skill vs la práctica real del codebase — no una violación estricta.
   El include `'chargeBatches'` en el controller es consistente con el método.

4. **`ChargePolicyUpdateRequest` permite nullear `valid_from`/`valid_to` en políticas periódicas**
   Las reglas son `['sometimes', 'nullable', 'date']`. Un PATCH que envíe `valid_from: null` sobre
   una política periódica deja la política activa pero con `valid_from=null`, lo que la saca silenciosamente
   del cron (`activePoliciesFor` filtra `whereDate('valid_from', '<=', $today)` que no matchea NULL).
   No hay error 422 — la política queda en un estado inconsistente (is_active=true pero sin ventana).
   Fix: en `withValidator`, si el policy a editar es `collection_mode=periodic`, `required_if_update`.

### MINOR

5. **`charge_policy` partial unique incluye policies DIRECT con is_active=true**
   El índice `(tenant_id, charge_concept_id, periodicity_id) WHERE is_active=true` no discrimina
   por `collection_mode`. Una policy DIRECT activa y una PERIODIC activa con el mismo concept+periodicity
   conflictuarían. En la práctica las DIRECT deberían usar periodicidad 'eventual' (no automática),
   pero el request solo valida que la PERIODIC use periodicidad automática, no que DIRECT use 'eventual'.
   Podría generarse un conflicto si alguien crea dos DIRECT activas con misma periodicity.

6. **`ChargeStatus` — comentario incorrecto corregido pero el fix es positivo**
   El comentario anterior decía "seeded per tenant (NOT shared UUIDs)" — era factualmente incorrecto
   (ChargeStatus extiende `CentralCatalogModel`, no tiene tenant_id). El nuevo comentario es correcto.

## Patrones validados (CLEAN)

- `ChargePolicy` extends `BaseModel`, usa `BelongsToTenant`, `#[ObservedBy([GlobalObserver::class])]`.
- `MoneyIntegerCast::class.':MXN'` aplicado a los 5 campos de dinero. Correcto.
- Todas las columnas propias de `charge_policy` tienen `->comment()`. Cumple rule 13.
- `$table->comment(...)` dentro del closure (no encadenado a `Schema::create`). Correcto.
- `BaseModel::columns($table, true)` — tenant-scoped con segundo argumento `true`. Correcto.
- FK a tablas centrales (`amount_mode`, `periodicity`) sin filtro de tenant en validación. Correcto.
- FK a `charge_concept` (tenant-scoped) con `Rule::exists()->where('tenant_id', tenant('id'))`. Correcto.
- `performStore` delegando a `ChargePolicyService::create()`. SRP respetado.
- `performDestroy` con guard de `chargeBatches()->exists()`. Correcto.
- `collection_mode` como string con constantes de clase (`COLLECTION_PERIODIC`, `COLLECTION_DIRECT`),
  NO PHP enum nativo. Cumple rule 14.
- `GenerateMonthlyChargesCommand` itera policies con `collection_mode=periodic`. DIRECT policies
  excluidas correctamente del cron.
- Transacciones anidadas: `ChargePolicyService::create` wrappea `generateDirectBatch` + `activateBatch`
  en una sola `DB::transaction`. `activateBatch` abre su propia transacción (savepoint). Correcto.
- `CatalogSeeder::upsertCatalog()` reemplazó `updateOrCreate` por `upsert()` — inmune al bug de
  Cachable con PK duplicado en re-seed (plan 040).
- `Payment` model: renombrado `paymentStatus()` → `payment_status()`, `bankAccount()` → `bank_account()`,
  `payerUser()` → `payer_user()` — ahora consistente con la convención snake_case para BelongsTo.
- `Charge` model: `chargeStatus()` → `charge_status()`. Consistente.
- `ChargeBatch` model: `concept()` → `policy()`. Semánticamente correcto.
- Route `charge_policy` registrada con `withSoftDeletes()` y middleware `$tenancyMiddleware`. Correcto.
- Menu wiring completo: módulo en `modules.csv`, 6 permisos, `module_system.csv`, `SystemTableSeeder`,
  `RolePermissionTableSeeder`. Cumple el checklist CLAUDE.md §3.
- `BY_SHARE` (Hamilton largest-remainder en `computeByShareAllocation`) NO fue modificado. Correcto.
- `AmountCalculator.compute()` recibe `ChargePolicy` en lugar de `ChargeConcept`. Firma correcta.
- `$amountOverride` eliminado correctamente de `AmountCalculator.compute()` — ya no existe en el batch.
