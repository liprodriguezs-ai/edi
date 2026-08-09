# Delta for Collections — Edición de Alcance y Periodicidad en Reglas de Cobro

## MODIFIED Requirements

### Requirement: Alcance editable solo antes de facturar

El sistema DEBE permitir editar `target_type`/`target_selector` (y en eventuales `issued_at`/`due_date`) de una `charge_policy` mientras `has_billed = false`, persistiendo el cambio en la misma transacción sobre el `charge_batch` correcto (template `is_initial=true` en periódicas; único batch `is_initial=false` en eventuales). Tras facturar, DEBE rechazar estos campos con 422 sin tocar el batch, dejando editable el resto de la config (nombre, monto, recargos).
(Previously: alcance inmutable siempre; sin ruta de update.)

#### Scenario: Periódica sin cargos
- GIVEN policy periódica, `has_billed = false`
- WHEN se actualiza `target_type`/`target_selector`
- THEN 200; el batch `is_initial=true` refleja el cambio

#### Scenario: Eventual sin cargos, incluido 0 unidades resueltas
- GIVEN policy eventual, `has_billed = false`
- WHEN se actualiza scope + `issued_at`/`due_date`
- THEN 200; el único batch `is_initial=false` refleja el cambio

#### Scenario: Rechazo tras facturar
- GIVEN policy con `has_billed = true`
- WHEN se intenta cambiar `target_type`/`target_selector`
- THEN 422, el batch no cambia; un update simultáneo de nombre/monto/recargos sí persiste

### Requirement: Periodicidad editable solo antes de facturar

El sistema DEBE permitir actualizar `periodicity_id` mientras `has_billed = false`, y rechazarlo con 422 una vez `has_billed = true`.
(Previously: `periodicity_id` editable sin gate.)

#### Scenario: Editable sin cargos
- GIVEN policy `has_billed = false`
- WHEN se actualiza `periodicity_id`
- THEN 200, valor persiste

#### Scenario: Bloqueada tras facturar
- GIVEN policy `has_billed = true`
- WHEN se intenta actualizar `periodicity_id`
- THEN 422, valor no cambia

### Requirement: Vigencia siempre editable

`valid_from`/`valid_to` DEBEN seguir editables sin importar `has_billed`, sin efecto retroactivo sobre `charge` ya generados.

#### Scenario: Editar vigencia con cargos existentes
- GIVEN policy `has_billed = true`
- WHEN se acorta `valid_to`
- THEN 200; ningún `charge` existente cambia de monto o estado

### Requirement: `has_billed` correcto, sin N+1

`ChargePolicyController` DEBE exponer `has_billed` vía `withExists('charges as has_billed')` en `buildIndexFetchQuery`/`buildShowFetchQuery`/`buildSearchFetchQuery`, sin query adicional por fila.

#### Scenario: Sin cargos (incluida eventual con 0 unidades resueltas)
- GIVEN policy sin ningún `charge`
- WHEN se consulta (index/show/search)
- THEN `has_billed = false`

#### Scenario: Con al menos un cargo
- GIVEN policy con ≥1 `charge`
- WHEN se consulta
- THEN `has_billed = true`

### Requirement: Bloqueo condicional en el formulario

El formulario DEBE habilitar `SingleTargetSelector` (incl. `ManualUnitPicker`) y `PeriodicitySelector` cuando `has_billed = false`, y deshabilitarlos cuando es `true`; el `Callout` DEBE indicar que solo alcance y periodicidad se bloquean, y que las fechas siguen editables.
(Previously: lock incondicional por `isEdit`, Callout con copy incorrecto sobre fechas.)

#### Scenario: Sin facturar
- GIVEN regla en edición, `has_billed = false`
- WHEN se abre el formulario
- THEN selectores habilitados

#### Scenario: Facturada
- GIVEN regla en edición, `has_billed = true`
- WHEN se abre el formulario
- THEN selectores deshabilitados; `Callout` refleja el estado real

### Requirement: `ManualUnitPicker` respeta `disabled`

`ManualUnitPicker` DEBE aceptar prop `disabled` y aplicarla a su `SearchSelect` (`isDisabled`); `SingleTargetSelector` DEBE propagarla.
(Previously: no se propagaba; el selector manual quedaba editable pese al lock.)

#### Scenario: Selector manual bloqueado
- GIVEN `SingleTargetSelector` con `disabled=true`, `target_type=manual`
- WHEN se renderiza
- THEN `SearchSelect` recibe `isDisabled=true`

### Requirement: Hidratación real en reglas eventuales

`batchScopeFromPolicy` DEBE resolver también el batch `is_initial=false`, devolviendo `target_type`/`target_selector`/`issued_at`/`due_date` reales al editar una regla eventual.
(Previously: solo buscaba `is_initial=true`; eventuales caían en `emptyBatchScope('')`.)

#### Scenario: Editar regla eventual
- GIVEN policy eventual con su batch `is_initial=false`
- WHEN se abre el formulario de edición
- THEN precarga el alcance y fechas reales de ese batch, no valores vacíos

### Requirement: Modo de monto fijo, selector oculto

El formulario NO DEBE renderizar `AmountModeSelector`; `amount_mode` DEBE quedar fijo en `FIXED_REQUIRED` por default; el campo de monto fijo dependiente sigue visible.
(Previously: `AmountModeSelector` permitía elegir entre varios modos.)

#### Scenario: Formulario sin selector de modo
- GIVEN formulario de creación o edición
- WHEN se renderiza
- THEN `AmountModeSelector` ausente; campo de monto fijo visible; payload trae `amount_mode = FIXED_REQUIRED`
