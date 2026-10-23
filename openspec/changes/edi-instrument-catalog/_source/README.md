# Source extracts

Datos extraídos del dump de producción del sistema legacy
(`extendevs_prod_edi.sql`), que **no vive en este repo** por contener datos
personales de menores.

## Qué hay acá

| Archivo | Contenido |
|---|---|
| `cat_edi_clasificacion.csv` | 29 grupos de edad: 15 oficiales + 14 alternos |
| `cat_edi_evaluacion.csv` | 451 reactivos con sus dos opciones y el color de cada una |
| `cat_edi_frb.csv` | 7 factores de riesgo biológico |
| `cat_edi_posicion.csv` | 6 posiciones de aplicación |
| `cat_edi_perimetro_cefalico.csv` | 118 filas de referencia percentilar (meses 1–59) |
| `area_item_counts.csv` | matriz grupo × eje × cantidad de reactivos, derivada |
| `edi_oracle.jsonl` | 1,836 pruebas aplicadas para validar el motor de calificación |

## Qué NO hay, a propósito

Las tablas transaccionales crudas (`tran_edi_beneficiario`,
`tran_edi_evaluacion_respuestas`, `tran_edi_frb_respuestas`) se extrajeron para
construir el oráculo y **se descartaron enseguida**: contienen notas clínicas de
texto libre escritas por el personal sobre niños reales, y no hay forma barata de
garantizar que 1,834 de ellas estén libres de datos identificables.

`edi_oracle.jsonl` es el derivado que sí se conserva. Cada línea trae únicamente
lo que necesita el motor para recalcular: `legacy_id`, grupo, insumos perinatales
(semanas de gestación, peso al nacer, edad de la madre, perímetro cefálico),
las respuestas por reactivo y el resultado esperado. **Sin nombre, sin CURP, sin
identificador de persona, sin texto libre.**

## Para qué sirve el oráculo

Replicar las 1,836 pruebas contra el motor nuevo y verificar que reproduce el
resultado que el sistema viejo guardó. La transcripción de las reglas ya se validó
así: **1,834/1,836 = 99.9%**, y los 2 restantes resolvieron la regla de evaluación
alterna (ver `design.md` §13).

## Advertencia sobre `birth_weight_grams`

La columna `peso_nacer` del legacy mezcla unidades: la mayoría de las filas guarda
**gramos** y una parte guarda **kilogramos**. El fixture ya normalizó ambas a gramos
(767 filas venían en gramos, 716 en kilos), pero el dato de origen tiene además
valores imposibles: el rango va de **10 g a 33 kg**, y **319 de 1,483 filas caen
fuera de un rango plausible** (500–6500 g).

Consecuencias:

- **No consumas este campo sin validar el rango.** El factor de riesgo
  `birth_weight_le_1500` se dispara con ≤1500 g; con la basura incluida daría 50
  positivos, y no todos son prematuros reales.
- El replay del oráculo **no pasa insumos perinatales**: las respuestas de producción
  ya traen el autocompletado del legacy, así que el motor se valida contra la verdad
  del momento de captura y este campo no lo afecta.
- El sistema nuevo debe validar rangos plausibles al capturar, y tratar lo
  fuera de rango como dato ausente (`NULL` = "se ignora"), nunca como cero.
