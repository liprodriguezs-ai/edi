# Dominio

**EDI** es un sistema para aplicar y dar seguimiento a la **Evaluación del Desarrollo
Infantil**, el tamiz de desarrollo de la Secretaría de Salud de México, más las
sesiones de **estimulación temprana** asociadas.

## Glosario

| Término | Significado |
|---|---|
| Tenant | La **organización de salud** suscrita a la plataforma. Todas comparten UNA base; el aislamiento es por fila (`tenant_id` + `TenantScope`) |
| Health Center | **Centro de salud** o unidad médica dentro de la organización. Una organización tiene N |
| Child | Niño o niña evaluado. PII sensible: es menor de edad |
| Caregiver | Cuidador o tutor. Se vincula al niño vía `child_caregiver` con su parentesco |
| Birth Record | Datos perinatales: semanas de gestación, peso y talla al nacer, días en UCIN, edad de la madre al parto. Alimentan la edad corregida y los factores de riesgo autoderivados |
| Visit | La consulta donde se aplica el tamiz (`receipt_number` + fecha) |
| Instrument | La prueba EDI como CONTENIDO versionado: grupos, reactivos, opciones, reglas |
| Instrument Version | Una edición del instrumento. Inmutable tras publicarse. Una prueba apunta a la suya |
| Age Group | Grupo de edad (15 oficiales, 1–71 meses) + 14 grupos **alternos** con los reactivos del grupo anterior |
| Development Area | Eje evaluado: MG (motriz gruesa), MF (motriz fina), LE (lenguaje), SO (social), CO (conocimiento), EN (exploración neurológica), ALA (señales de alarma), ALE (señales de alerta) |
| Screening Item | Reactivo. Código tipo `MG10.2`. Dos opciones, cada una produce un color |
| Risk Factor | Factor de riesgo biológico (7). Tres se autoderivan de datos ya capturados |
| Screening | La prueba aplicada: cabecera + respuestas + resultados por eje |
| Result Color | Semáforo: **verde** (normal), **amarillo** (rezago), **rojo** (riesgo de retraso) |
| Corrected Age | Edad corregida por prematurez. Puede cambiar el grupo aplicado |
| Alternate Evaluation | Si un eje sale TODO amarillo, se aplican los reactivos del grupo anterior para ese eje |
| Situation | Comparación con la prueba anterior: primera vez, mejoró, se mantuvo o empeoró |
| Follow Up | Conducta derivada: reaplicar a 3 meses, referir a especialista, prueba confirmatoria |
| Confirmatory Test | Prueba confirmatoria (Battelle) tras un resultado rojo |
| Stimulation Session | Sesión de estimulación temprana: integración, preparación, actividad central, relajación |
| Stimulation Group | Grupo de estimulación con lista de asistencia |

## Reglas de negocio

### Cálculo del resultado

El semáforo se calcula **server-side**, como función pura, a partir de las respuestas
crudas y del `RuleSet` de la versión del instrumento. Nunca se acepta un resultado del
cliente.

- **Por eje**: `reds >= red_threshold` → rojo; si no `greens >= green_threshold` →
  verde; si no y el eje admite amarillo → amarillo; si no, verde. Los umbrales son
  DATOS (`area_scoring_rule`), distintos por grupo.
- **Global**: se recorre `global_scoring_rule` por secuencia y gana la primera regla
  que hace match. Las condiciones usan una gramática cerrada de 6 operadores.
- **Trazabilidad**: cada cálculo guarda qué regla disparó, para poder explicarlo
  clínicamente y para comparar contra el sistema anterior.

### Edad

La edad cronológica determina el grupo. La **edad corregida** se calcula sólo si el
niño tiene menos de 2 años, nació con menos de 37 semanas y su edad cronológica supera
los 59 días: `días − (40 − SDG) × 7`. Si la edad corregida cae en otro grupo, se aplica
ese y se registra el hallazgo.

### Autoderivados

No los contesta quien aplica la prueba: gestación menor a 34 semanas, peso al nacer
≤ 1500 g, madre menor de 16 años al parto, y el perímetro cefálico contra la tabla
percentilar por edad y sexo. Si el cliente los manda, se sobrescriben.

### Versionado

El instrumento evoluciona (2ª edición 2013, manual 2024). Una prueba histórica se
recomputa **con las reglas de su propia versión**, nunca con las nuevas. Recalificar
crea un registro nuevo y requiere permiso y motivo; no pisa en silencio.

## Multi-tenancy

Una sola base compartida. Aislamiento por fila vía `tenant_id` + `TenantScope` (trait
`BelongsToTenant`). No hay base por tenant ni cambio de conexión. El tenant se resuelve
del usuario autenticado; el super admin manda `X-Tenant-Id`.

## Privacidad

Los sujetos son **menores de edad**. Aplica LFPDPPP y NOM-024: revisar cifrado de CURP,
política de retención, y que la auditoría registre las respuestas sin volcar PII a logs.
