---
name: indiviso-retirado-de-la-ui
description: El indiviso (ownership_share) se quitó de toda la UI de Kivo7 el 2026-08-06 y se borró del manual; qué sigue vivo por debajo y qué quedó pendiente (captura unidades.webp)
metadata:
  type: project
---

# El indiviso salió de la interfaz — y del manual (2026-08-06)

**Hecho.** El campo *Indiviso (%)* (`ownership_share`) se eliminó de TODA la
superficie visible de Kivo7: formulario de unidad (bloque **Datos físicos**, que
ahora solo tiene M² construcción y M² terreno, con ayuda "Opcionales"), tabla y
tarjetas del listado de Unidades, detalle de la unidad, `reportColumns()` del
modelo `Unit` (ya no sale en el Excel de Unidades) y `PAGOS_HEADERS_PUBLIC` de
`TemplateGenerator` (la plantilla de importación que descarga el usuario ya no
trae la columna `indiviso`).

**Why:** el prorrateo por indiviso NUNCA fue alcanzable desde la interfaz —el
selector de modo de monto está oculto por decisión de producto—, así que el campo
pedía un dato que no servía para nada visible.

**How to apply:** el manual NO debe documentar el indiviso como campo existente.
Si aparece de nuevo en alguna página, es desfase.

## Lo que sigue siendo cierto por debajo (no lo contradigas si vuelve el tema)

- La columna existe en la base y los datos capturados se conservan intactos.
- `WorkbookReader` sigue aceptando la columna `indiviso`, así que un archivo VIEJO
  que la traiga se importa sin error; solo la plantilla nueva ya no la incluye.
- La validación de "la suma no puede pasar de 100%" sigue viva en el backend, pero
  el usuario final ya no puede toparse con ella: no hay dónde capturar el valor.
- `UnitForm.tsx` conserva `ownership_share` en los valores del formulario a
  propósito: si se quitara del payload, un guardado normal borraría el indiviso
  histórico de los condominios que sí lo capturaron.

## Qué se quitó del manual

- `estructura/unidades.mdx` — sección completa **## El indiviso** (con su
  `<Aside type="caution">` del tope de 100%), el ítem **Indiviso (%)** del paso 3
  del alta, la mención en la lista de columnas del Excel y la palabra en el alt de
  la captura. Ningún enlace apuntaba al ancla `#el-indiviso` (verificado), así que
  no se rompió ninguna URL pública.
- `ayuda/glosario.mdx` — entrada **Indiviso** de la sección I.
- `ayuda/solucion-de-problemas.mdx` — fila "No deja guardar el indiviso".

## Pendiente para el humano

`landing/src/assets/docs/unidades.webp` **sigue mostrando la columna Indiviso** en
la tabla. Hay que recapturarla; el texto alternativo ya se escribió para la tabla
nueva (identificador, tipo, sección y estado). Ver los gotchas de captura en el
índice de MEMORY.md.
