# Design: charge-policy-editable-until-billed

## Enfoque técnico

Approach A: el PATCH de la política enruta los cambios de ALCANCE (`target_type`/`target_selector`, +`issued_at`/`due_date` en eventual) al `charge_batch` que ya guarda el alcance (template `is_initial=true` en periódica; único `is_initial=false` en eventual), dentro de la MISMA transacción que actualiza la política, SOLO cuando `charges()->exists()===false`. Cero migración; no se toca `ChargeGenerationService`/cron/`TargetResolver`/`cloneInitialBatch` (el template sigue siendo la única fila que el cron clona). `has_billed` se expone por subconsulta `EXISTS` (sin N+1). **Amendment 1**: `periodicity_id` se bloquea al facturar. **Amendment 2**: se oculta el selector de modo de monto (solo `FIXED_REQUIRED`).

## Decisiones de arquitectura

| Decisión | Elección | Alternativa rechazada | Razón |
|---|---|---|---|
| Dónde vive el alcance editable | Enrutar al `charge_batch` existente | Columnas `target_*` en `charge_policy` (Approach B) | Sin migración/backfill; el template ya es single-source para el cron |
| Gate de "ya facturó" | `charges()->exists()` | `charge_batches()->exists()` | Template/eventual-0-unidades existen con 0 cargos → no deben bloquear; `charge_policy_id` indexado; precedente `performDestroy` |
| Cómputo de `has_billed` para el front | `->withExists('charges as has_billed')` en los 3 hooks fetch | `$appends`+accessor | El accessor dispara N+1 (auditado por infra-cost); `withExists` = 1 subconsulta booleana |
| Prohibición al facturar | `Rule::prohibitedIf(fn => hasBilled())` (scope + periodicity) | Rama distinta en el servicio | Espeja el patrón existente `charge_concept_id: prohibited`; el 422 sale con la key correcta |
| Modo de monto | Ocultar el `RadioGroup`, conservar el campo `fixed_amount` (flag-driven) | Hardcodear FIXED en los money fields | Red de seguridad: una policy legacy RANGE aún hidrata/edita bien |

## Batch resolution (servicio)

`ChargePolicyService::update()` (misma `DB::transaction` actual): calcular `$hasBilled = $policy->charges()->exists()` UNA vez → fill+save política → sync recargos → si `!$hasBilled && array_key_exists('target_type', $attributes)` resolver el lote y actualizarlo:

```
resolveScopeBatch(policy):
  isPeriodic  -> charge_batches()->where('is_initial', true)->first()   // template (índice parcial garantiza ≤1)
  isDirect    -> non-initial = charge_batches()->where('is_initial', false)->get()
                 count==1 -> ese lote
                 count==0 -> null   (eventual pausado-al-crear: no-op)
                 count>1  -> abort(422) "No se puede determinar el lote a actualizar"  // invariante violada, no elegir a ciegas
```

Con el lote: `target_type`, `target_selector` (`?? null`), y en eventual `issued_at`/`due_date`; `save()`. Doble gate (request + servicio) por defensa. **Límite conocido**: periódica pausada-al-crear no tiene template → el cambio de alcance es no-op (atado al bug `is_active` fuera de alcance).

## Gate placement + reglas 422

`ChargePolicyUpdateRequest`: helper memoizado `hasBilled()`/`policy()` (reusar en `validatePeriodicWindow`/`validateAutomaticPeriodicity`).

| Campo | Regla update |
|---|---|
| `periodicity_id` | `[prohibitedIf($billed),'sometimes','required','uuid',exists]` + `unique(...)->ignore($policy->id)` cuando `!billed && is_active` (respeta el índice parcial `(tenant,concept,periodicity)`) |
| `target_type` | `[prohibitedIf($billed),'sometimes','string',Rule::in(TARGET_TYPES)]` |
| `target_selector` | `[prohibitedIf($billed),'sometimes','nullable','array']` |
| `issued_at`/`due_date` | `[prohibitedIf($billed),'sometimes','nullable','date'(+after_or_equal)]` |
| `charge_concept_id` | `prohibited` (sin cambio) |

Mensajes espejando `charge_concept_id.prohibited`. **Shape 422 intacto**: los errores salen bajo `target_type`/`target_selector`/`periodicity_id`/`issued_at`/`due_date` — `handleValidationError` ya mapea esas keys y ancla `target_selector` sobre `target_type`. No se agregan keys nuevas.

## has_billed exposure

`ChargePolicyController`: override `buildIndexFetchQuery`/`buildShowFetchQuery`/`buildSearchFetchQuery` → `parent::...()->withExists('charges as has_billed')`. En Postgres el `EXISTS` serializa como booleano nativo (sin accessor). Contrato: `IChargePolicy.has_billed?: boolean`.

## Frontend

- `schema.ts`: `has_billed?: boolean` en `IChargePolicy`.
- `ChargeRuleFormPage.tsx`: `hasBilled = Boolean(policy?.has_billed)`. `SingleTargetSelector disabled` y fechas eventual → `isSaving || hasBilled` (NO `isEdit`). `Callout` solo si `hasBilled`, copy real (alcance + periodicidad + fechas bloqueadas; vigencia y monto siguen editables). Pasar `hasBilled` a `PolicyConfigForm` y a `buildPolicyUpdatePayload`.
- `buildPolicyPayload.ts`: `buildPolicyUpdatePayload(values, hasBilled)`. **Acoplamiento clave**: cuando `hasBilled`, OMITIR `periodicity_id` (si se envía sin cambios, `prohibited` lo rechaza — igual que `charge_concept_id` ya se omite). Cuando `!hasBilled`: incluir `periodicity_id`, `target_type`, `target_selector` y (eventual) `issued_at`/`due_date`. `valid_from`/`valid_to` siempre para recurrente.
- `PolicyConfigForm.tsx` + `PeriodicitySelector.tsx`: prop `disabled` opcional; `RadioGroup disabled={isSaving || hasBilled}`.
- `AmountModeSelector.tsx`: quitar el `RadioGroup` de modo; conservar `AmountModeMoneyFields` (flag-driven). `amount_mode_id` persiste vía `buildPolicyDefaults` (default `FIXED_REQUIRED`, ya presente).
- **Bug #1** `SingleTargetSelector.tsx`→`ManualUnitPicker.tsx`: agregar prop `disabled` a `ManualUnitPicker`, propagarla a `<SearchSelect isDisabled>`, y pasarla desde `SingleTargetSelector` (espejo de la rama `single_unit`).
- **Bug #2** `buildPolicyDefaults.ts::batchScopeFromPolicy`: `find(is_initial===true) ?? find(is_initial===false)` — eventual hidrata su único lote no-inicial (alcance/fechas reales) en vez de `emptyBatchScope`.

## Testing

| Capa | Qué | Cómo |
|---|---|---|
| Backend Feature (nuevo `ChargePolicyScopeUpdateTest`) | periódica no-facturada persiste en template; eventual-0-unidades persiste en único lote (+fechas); 422 al facturar (scope y periodicity) mientras nombre/monto/recargos sí actualizan; `valid_from/valid_to` editables aun facturado sin efecto retroactivo; unique-ignore al cambiar periodicidad; `has_billed` correcto por `withExists` | PHPUnit host + tenant setup, patrón `ChargePolicyInitialBatchTest` |
| Backend Unit | invariante eventual >1 lote → abort | servicio con estado fabricado |
| Front | `buildPolicyUpdatePayload` inclusión condicional por `hasBilled`; `batchScopeFromPolicy` eventual (bug #2) | extender `buildPolicyPayload.test.ts` / `buildPolicyDefaults.test.ts` |
| Front | `AmountModeSelector` sin radio + campo fijo presente | **reescribir** la 1ª aserción de `AmountModeSelector.test.tsx` (queda moot); conservar los tests de money-fields flag-driven. No borrar el archivo |

## Migración / Rollout

Sin migración. Sin feature flag.

## Riesgos / preguntas abiertas

- [ ] Acoplamiento `prohibited`↔omisión FE de `periodicity_id`: si el payload lo manda sin cambios estando facturado, 422. Mitigado omitiéndolo en `buildPolicyUpdatePayload`.
- [ ] Periódica pausada-al-crear (sin template): cambio de alcance = no-op (atado al bug `is_active`, fuera de alcance).
- [ ] `ChargeBatchController::performUpdate` sigue sin gate (fuera de alcance, gap latente; sin ruta UI hoy).
- [ ] Semántica de cambiar periodicidad periódica↔eventual (no facturada) es pre-existente; solo se agrega el gate de facturado + unique-ignore, no se rediseña.

## Lista de archivos (back + front)

BACKEND (Modules/Collections):
- `app/Http/Requests/ChargePolicyUpdateRequest.php` — Modify: prohibitedIf($billed) en periodicity_id + reglas nuevas target_type/target_selector/issued_at/due_date; unique-ignore en periodicity_id; helpers memoizados hasBilled()/policy(); mensajes prohibited.
- `app/Services/ChargePolicyService.php` — Modify: update() computa $hasBilled una vez; resolveScopeBatch() privado; actualiza el lote in-transaction cuando !billed && scope presente.
- `app/Http/Controllers/ChargePolicyController.php` — Modify: 3 overrides buildIndex/Show/SearchFetchQuery con ->withExists('charges as has_billed').
- `app/Models/ChargePolicy.php` — opcional (helper hasBilled()); no requerido.
- `tests/Feature/.../ChargePolicyScopeUpdateTest.php` — Create (+ unit para invariante >1).

FRONTEND (modules/chargePolicy + shared/components/target):
- `schema.ts` — Modify: IChargePolicy.has_billed?: boolean.
- `ChargeRuleFormPage.tsx` — Modify: lock condicional por hasBilled; Callout; pasar hasBilled a PolicyConfigForm + buildPolicyUpdatePayload.
- `buildPolicyPayload.ts` — Modify: buildPolicyUpdatePayload(values, hasBilled) con inclusión condicional (omite periodicity_id + scope cuando billed).
- `components/PolicyConfigForm.tsx` — Modify: prop hasBilled/disabled → PeriodicitySelector.
- `components/PeriodicitySelector.tsx` — Modify: prop disabled opcional.
- `components/AmountModeSelector.tsx` — Modify: ocultar RadioGroup de modo; conservar money fields.
- `shared/components/target/SingleTargetSelector.tsx` — Modify: pasar disabled a ManualUnitPicker (bug #1).
- `modules/chargeBatch/components/ManualUnitPicker.tsx` — Modify: prop disabled -> SearchSelect isDisabled (bug #1).
- `buildPolicyDefaults.ts` — Modify: batchScopeFromPolicy fallback a is_initial=false (bug #2).
- Tests: buildPolicyPayload.test.ts, buildPolicyDefaults.test.ts (extender), components/__tests__/AmountModeSelector.test.tsx (reescribir 1ª aserción).
