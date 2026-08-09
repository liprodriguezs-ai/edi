# Proposal: charge-policy-editable-until-billed

> Fase: PROPOSE. Artifact store: openspec + engram (`sdd/charge-policy-editable-until-billed/proposal`).
> Enfoque cerrado por el usuario: **Approach A** (rutear el alcance al `charge_batch` existente, sin cambio de esquema).

## Why (el problema y por qué ahora)

Hoy, cuando un administrador crea una regla de cobro (`charge_policy`), el **alcance** de esa regla
—a quién le llega el cargo: `target_type` (`all`/`by_type`/`by_tower`/`by_floor`/`by_feature`/`by_share`/`single_unit`/`manual`)
y su selección concreta `target_selector`— queda **inmutable**. No es que esté "bloqueado por regla de negocio":
es que **ni siquiera existe un camino para editarlo**. `ChargePolicyUpdateRequest` no declara esos campos y
`ChargePolicyService::update()` nunca toca los `charge_batches`. El alcance real vive en el batch template
(`is_initial = true`) para reglas periódicas, o en el único batch (`is_initial = false`) para reglas eventuales/directas.

El frontend agrava el problema: `ChargeRuleFormPage` muestra un `Callout` fijo ("El alcance y las fechas del lote inicial
no se pueden cambiar después de crear la regla.") y deshabilita el `SingleTargetSelector` **siempre que sea edición**,
sin importar si la regla ya generó cargos o no. El mensaje es engañoso (afirma que las fechas tampoco se editan, cuando
`valid_from`/`valid_to` sí son editables hoy) y el bloqueo es más estricto de lo necesario.

**El dolor concreto:** el administrador comete un error de alcance al crear la regla (elige la torre equivocada, olvida
una unidad, marca "todo el condominio" por accidente) y **no tiene forma de corregirlo**. Su única salida es borrar la
regla y rehacerla desde cero. Esto es especialmente costoso porque la regla ya puede tener recargos, ventanas de vigencia
y configuración de monto cargadas. La necesidad real: **poder corregir el alcance mientras la regla todavía NO facturó**
(es decir, mientras no exista ningún `charge` generado). Una vez que la regla generó cargos, cambiar el alcance
retroactivamente rompería la coherencia contable, así que ahí sí debe bloquearse.

Además, al abrir el proyecto se confirmaron **dos bugs latentes** que, de no arreglarse, harían que esta misma feature
"funcione a medias" o de forma inconsistente (detallados en *What changes* y *Scope in*).

## What changes

### Backend (`backend/Modules/Collections/`)

1. **`ChargePolicyUpdateRequest`** — agregar reglas de validación para los campos de **alcance**
   (`target_type`, `target_selector`, y para reglas eventuales también `issued_at`/`due_date`, que son
   propiedad del batch y hoy no son editables post-creación), **espejando** la validación del Create.
   Estos campos quedan **gateados**: solo se aceptan mientras la regla no haya facturado. Si la regla ya generó
   cargos, se rechazan con 422 (siguiendo el patrón existente `charge_concept_id: prohibited`), en vez de
   ignorarlos en silencio. `valid_from`/`valid_to` **NO cambian** — siguen editables como hoy (reglas `sometimes`
   + `validatePeriodicWindow` + `after_or_equal`), sin gate nuevo.

2. **`ChargePolicyService::update()`** — resolver el batch correcto y actualizar su `target_type`/`target_selector`
   (+ `issued_at`/`due_date` para eventuales) **dentro de la misma transacción** que el update de la policy,
   **solo cuando** `charges()->exists() === false`. Resolución del batch:
   - Periódica → el batch template (`charge_batches()->where('is_initial', true)->first()`).
   - Eventual/directa → el único batch `is_initial = false`.
   No se toca `ChargeGenerationService`, ni el cron, ni `TargetResolver`.

3. **`ChargePolicyController`** — exponer al frontend un booleano computado `has_billed` agregando
   `->withExists('charges as has_billed')` en los tres hooks `buildIndexFetchQuery` / `buildShowFetchQuery` /
   `buildSearchFetchQuery` (patrón ya usado en `ChargeController`). **NO** usar `$appends` + accessor con
   `charges()->exists()` — correría una query por fila en listados (N+1), auditado por `@infra-cost-reviewer`.
   El gate de `performDestroy` (`charge_batches()->exists() || charges()->exists()`) se deja **como está**.

4. **`ChargePolicy` (modelo)** — sin cambio de esquema. Opcional: un helper delgado `hasBilled(): bool`
   (espejo de `isDirect()`/`isPeriodic()`) para reutilizar el gate entre Service y Controller.

### Frontend (`frontend/src/modules/chargePolicy/` + `shared/components/target/`)

5. **`ChargeRuleFormPage.tsx`** — reemplazar el lock incondicional por `isEdit` por un lock **condicional** basado
   en `policy?.has_billed`. Actualizar el copy del `Callout` para reflejar que el alcance se bloquea **solo tras facturar**,
   y que las fechas siguen editables. Mantener el shape de error de `handleValidationError` (que ancla los errores de
   `target_selector` sobre `target_type`) para que el nuevo rechazo 422 no se trague en silencio.

6. **`buildPolicyPayload.ts::buildPolicyUpdatePayload`** — incluir `target_type`/`target_selector`
   (+ `issued_at`/`due_date` para eventuales) en el payload **solo cuando** `!policy.has_billed`.

7. **`schema.ts`** — `IChargePolicy` gana `has_billed?: boolean` (los campos de alcance ya están modelados).

8. **Fix bug #1 (lock-bypass del multiselect manual):**
   - `SingleTargetSelector.tsx` — propagar `disabled` hacia `<ManualUnitPicker>` (hoy no lo pasa).
   - `ManualUnitPicker.tsx` — agregar prop `disabled?: boolean` y aplicarla al `<SearchSelect isDisabled>`.
   Sin esto, el selector manual queda interactivo aunque el resto del selector esté deshabilitado → se puede
   editar el alcance saltándose el lock.

9. **Fix bug #2 (hidratación de scope en reglas eventuales):**
   - `buildPolicyDefaults.ts::batchScopeFromPolicy()` — hoy solo busca un batch con `is_initial === true`.
     Las reglas eventuales/directas nunca crean uno (`is_initial` siempre false) → al editarlas cae en
     `emptyBatchScope('')` y muestra "Todo el condominio" con fechas en blanco en vez del alcance/fechas reales.
     Debe también matchear el único batch `is_initial = false`.

## Scope

### In scope (incluido)
- Backend: gate de alcance + update del batch en `ChargePolicyService::update()` en la misma transacción.
- Backend: exposición de `has_billed` vía `withExists` en los 3 hooks Orion de `ChargePolicyController`.
- Frontend: lock condicional por `has_billed`, copy del Callout actualizado, payload de update condicional,
  `IChargePolicy.has_billed`.
- Frontend bug #1: `ManualUnitPicker` no propaga `disabled` (+ `SingleTargetSelector` no se lo pasa).
- Frontend bug #2: `batchScopeFromPolicy` no hidrata scope/fechas para reglas eventuales.
- Cobertura de tests nueva (backend feature): update de alcance antes de facturar (periódica + eventual),
  rechazo tras facturar, fechas siempre editables, correctitud de `has_billed`, y el edge "eventual con 0 unidades".

### Out of scope (NO incluido — se declara explícitamente para evitar confusión en QA)
- **Bug `is_active` false→true sin regeneración**: reactivar una regla no dispara generación de cargos.
  Es adyacente y puede confundir en QA, pero **no** entra en este change.
- **`ChargeBatchController::performUpdate` permisivo**: acepta edits de `target_type`/`target_selector`/`issued_at`/
  `due_date` en cualquier batch **sin** gate de "ya facturó". Hoy es inofensivo (no hay ruta de edición de batch en la UI:
  `ChargeBatchFormPage` es create-only), pero queda **marcado como gap latente** para el equipo por si a futuro se agrega
  una UI de edición de batch. No se corrige acá.
- Cambio de esquema en `charge_policy` (Approach B): descartado por el usuario.
- Restricciones extra sobre `valid_from`/`valid_to` (p.ej. impedir mover `valid_from` más allá del `issued_at` del cargo
  más antiguo): NO se pide, agrega complejidad; revisar solo si surge un incidente real.

## Approach & rationale

**Approach A — rutear los cambios de alcance al `charge_batch` existente, sin cambio de esquema.** (Decisión cerrada.)

Por qué A y no B (agregar columnas `target_type`/`target_selector` a `charge_policy`):
- **Cero migración/backfill.** No se toca el módulo más crítico y de mayor tráfico (pagos FIFO, FSM de estados, cron diario).
- **Respeta la ADR ya documentada** en la migración/modelo: "charge_policy = config (monto/gracia/periodicidad/fechas);
  el alcance vive en charge_batch". El batch template es **la única fila que el cron clona** (`cloneInitialBatch` copia
  `initial.target_type`/`target_selector`), así que editarlo en el lugar es la única fuente de verdad — sin riesgo de
  dual-write/sync.
- **El gate `charges()->exists()` ya es de confianza en este codebase** (precedente: `performDestroy`), y `charge.charge_policy_id`
  está indexado → el chequeo es barato.
- **El edge "eventual con 0 unidades" se maneja gratis**: `generateEventualBatch` crea+activa el batch aunque `TargetResolver`
  resuelva 0 unidades, así que `charges()->exists()` es false y el alcance queda editable exactamente cuando debe.

**Gate del alcance (cerrado):** editable solo mientras `charges()->exists() === false`. **No** usar `charge_batches()->exists()`:
el template existe desde t=0 (antes de cualquier cargo), así que daría `true` casi inmediatamente tras crear la regla y
bloquearía el alcance de entrada — justo lo contrario de lo que se busca.

**Fechas (cerrado):** `valid_from`/`valid_to` quedan **siempre editables**, sin gate. Ninguna de las dos columnas es leída por
`ChargeStatusService` ni por cómputo por-cargo; cambiarlas post-facturación no toca cargos ya generados, solo mueve la ventana
de elegibilidad futura del cron (`activePoliciesFor`). Acortar `valid_to` para terminar antes una cuota recurrente es una
necesidad real y legítima, y debe seguir siendo posible después de facturar.

## Impact / risks

- **Dos agregados escritos por un endpoint (policy PATCH → también escribe un `charge_batch`).** Leve desvío del "un modelo
  por controller" de Orion, pero ya precedentado por este Service que hace más que un fill plano (`syncSurchargeRules`).
  Mitigación: **boundary transaccional claro** — el update de la policy y el update del batch ocurren en la misma
  `DB::transaction`, todo-o-nada.
- **Resolución del batch para eventuales.** Invariante documentado: exactamente **un** batch `is_initial = false` por regla
  eventual, creado una vez y nunca clonado. Riesgo si esa invariante se violara (>1 batch): definir un tie-break y/o fallar
  con gracia; no se agrega un gate nuevo, pero el Service debe manejar el caso de forma explícita, no asumir ciega.
- **Shape de error 422.** `handleValidationError` en `ChargeRuleFormPage.tsx` ancla los errores de `target_selector` sobre
  `target_type`. El nuevo camino de update condicional **debe** seguir emitiendo el mismo shape, o el manejo de 422 se
  traga el rechazo en silencio.
- **`ChargeBatchController::performUpdate` sigue sin gate** (fuera de scope). Hoy inofensivo (sin ruta de edición en la UI),
  pero es un gap latente a vigilar si se agrega edición de batch a futuro.
- **Bug `is_active` false→true** (fuera de scope) puede confundir a QA al probar este change → se declara explícitamente
  como no-incluido.
- **Cobertura de tests inexistente hoy** para el update de scope (solo `ChargePolicyInitialBatchTest` cubre creación) →
  la fase de tests debe crear cobertura nueva; sin ella, un futuro refactor puede reintroducir la inmutabilidad sin que nada avise.

## Requisitos testeables de alto nivel

> La fase **spec** los detalla con escenarios Given/When/Then. Acá quedan como intención verificable.

1. **Editar alcance antes de facturar (periódica):** dada una regla periódica sin cargos generados, actualizar
   `target_type`/`target_selector` persiste el cambio en el batch template y el próximo cron lo usa.
2. **Editar alcance antes de facturar (eventual):** dada una regla eventual sin cargos, actualizar
   `target_type`/`target_selector`/`issued_at`/`due_date` persiste en su único batch `is_initial = false`.
3. **Rechazo tras facturar:** dada una regla con al menos un `charge`, un intento de cambiar el alcance responde 422
   (no altera el batch), mientras que la config de la policy (nombre, monto, recargos) sí puede actualizarse.
4. **Fechas siempre editables:** `valid_from`/`valid_to` se pueden actualizar aun con cargos existentes, sin efecto
   retroactivo sobre cargos ya generados.
5. **`has_billed` correcto:** el flag expuesto por el controller es `false` cuando no hay cargos (incluido el edge
   "eventual con 0 unidades resueltas") y `true` cuando existe al menos un cargo, sin generar N+1 en listados.
6. **Frontend — lock condicional:** el `SingleTargetSelector` (incluido el `ManualUnitPicker`) está habilitado en edición
   cuando `has_billed` es false y completamente deshabilitado cuando es true; el Callout refleja el estado real.
7. **Frontend — hidratación eventual:** al editar una regla eventual, el formulario muestra el alcance y las fechas reales
   usadas en la creación (no "Todo el condominio" con fechas en blanco).

## Enmienda (confirmada por el usuario, post-propose)

Dos ajustes al scope:

1. **Periodicidad también gateada.** Además del alcance, `periodicity_id` se bloquea una vez que la regla facturó
   (`has_billed = true`); editable solo mientras no haya cargos. Backend: `ChargePolicyUpdateRequest` rechaza el cambio de
   `periodicity_id` con 422 cuando `has_billed` (mismo patrón que el alcance). Frontend: `PeriodicitySelector` deshabilitado
   cuando `has_billed`. Dirección confirmada: bloqueo TRAS el primer cargo, no antes.

2. **Ocultar `AmountModeSelector`.** Solo sobrevive el modo "Fijo obligatorio" (`FIXED_REQUIRED`); un único modo no tiene
   sentido mostrarlo como selector. Se OCULTA el componente `AmountModeSelector` entero del formulario de regla; `amount_mode`
   queda fijo por default en `FIXED_REQUIRED`. Los campos dependientes (monto fijo) siguen visibles. Efecto colateral: el test
   `AmountModeSelector.test.tsx` queda moot (componente oculto) — resolver en la fase de tests.
