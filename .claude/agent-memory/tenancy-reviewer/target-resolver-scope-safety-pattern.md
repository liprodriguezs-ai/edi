# TargetResolver — patrón estructural de tenant-safety

Todo método nuevo de `TargetResolver::TARGET_TYPES` construido sobre `billableQuery()`/`Unit::query()` hereda aislamiento automáticamente porque `Unit` usa `BelongsToTenant`. Si además la relación usada (`whereHas('vehicles')`, `whereHas('unitStatus')`) apunta a un modelo que TAMBIÉN usa `BelongsToTenant` (confirmado: `Vehicle`, `Section`, `Tower`, `UnitStatus`, `UnitUser`), el scope queda doblemente aplicado.

Un UUID de selector (`section_ids`, `unit_ids`, etc.) perteneciente a OTRO tenant nunca cruza — simplemente no matchea ninguna fila de la query ya acotada, resultando en 0 filas, no en fuga. Este es el patrón preexistente en todo el proyecto (`target_selector` se valida solo como `array`, sin `Rule::exists(...)->where('tenant_id', ...)` por clave).

Solo marcar CRITICAL a futuro si un nuevo método de `TargetResolver` bypasea `billableQuery()`/`Unit::query()` (raw `DB::` o relación a un modelo CENTRAL sin scope).

Verificado 2026-07-02 al revisar `by_section` y `with_vehicles` (ambos SAFE; 0 críticos).
