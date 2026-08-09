---
name: calle-y-numero-interior-en-unidad
description: 2026-08-07 — la Unidad ganó dos campos opcionales de dirección (Calle, Número interior); qué se documentó en estructura/unidades y qué límites NO hay que prometer
metadata:
  type: project
---

# Calle y número interior en la Unidad (2026-08-07)

**Hecho.** El módulo Housing agregó dos columnas nuevas y **opcionales** a `unit`
(migración `2026_08_06_120000_add_street_and_interior_number_to_unit_table.php`):

- `street` — string(150), nullable. Etiqueta en la UI: **Calle**, placeholder
  `Av. de los Cedros`.
- `interior_number` — string(50), nullable. Etiqueta: **Número interior**,
  placeholder `4-B`.

Orden visible en el bloque **Identificación** de `UnitForm.tsx` (**corregido el
2026-08-07**: el Número va primero y la Calle cierra el bloque; el primer orden
que se documentó, Calle → Número → Número interior, ya no es cierto):
Tipo de unidad → (si es vertical: Torre, Piso) → Número (se llama **Número de
depto** cuando el tipo es vertical) → **Número interior** → **Calle** →
Sección / Manzana → Estado.

Superficies donde aparecen, con su orden:

- Detalle de la unidad, tarjeta **Información general**: Tipo → Número →
  **Número interior** → **Calle** → Torre → Piso → metros… Las dos filas nuevas
  solo se pintan si tienen valor.
- `Unit::reportColumns()` (Excel de **Descargar**): Identificador, Número,
  **Número interior**, **Calle**, Tipo, Torre, Piso, Sección, m² construidos,
  m² terreno, Estado, F. creación.
- `UnitController::searchableBy()`: el buscador de la lista encuentra por ambos.

## Límites que el manual NO debe prometer (verificados en código)

- **No forman parte de la identidad de la unidad.** El unique sigue siendo
  tipo + torre + piso + número; capturar calles distintas NO permite repetir esa
  combinación.
- **El identificador legible no cambió**: sigue siendo "Casa 23" /
  "Torre A · Piso 5 · Depto 502", sin calle ni número interior.
- **La importación masiva por Excel no acepta estas dos columnas todavía.** Si
  algún día se documenta la plantilla de importación de unidades, no las incluyas
  hasta verificar `TemplateGenerator` / `WorkbookReader`.

## Qué se escribió en el manual

`landing/src/content/docs/docs/estructura/unidades.mdx`:

- Paso 2 del alta: los dos campos, marcados como opcionales, y la aclaración de
  que el campo **Número** se llama **Número de depto** en tipos verticales.
- Sección nueva **## Calle y número interior** (ancla pública
  `#calle-y-número-interior`) con tabla de para-qué-sirve, dónde se ven después y
  un `<Aside type="note">` con los límites de identidad.
- **Buscar y exportar**: el buscador y las columnas del Excel ahora los mencionan.
- **Si te dice que ya existe**: una línea aclarando que poner calles distintas no
  destraba el mensaje.

Relacionado: [[indiviso-retirado-de-la-ui]] (el otro cambio reciente del mismo
formulario de unidad).
