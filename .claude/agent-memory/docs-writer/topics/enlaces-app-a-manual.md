---
name: enlaces-app-a-manual
description: Cómo funciona el botón de ayuda de la app que lleva al manual, por qué está en la barra superior y no en cada pantalla, y cómo se evita que los enlaces se pudran
metadata:
  type: project
---

# Enlaces de la app hacia el manual (2026-08-06)

## Qué se construyó

Un botón de ayuda (`?`) en la **barra superior** de la app. Lleva a la página del
manual que corresponde a la pantalla actual.

| Pieza | Archivo |
|---|---|
| Registro de enlaces | `frontend/src/shared/docs/docsRegistry.ts` |
| Botón | `frontend/src/components/docs-help-button.tsx` |
| Montaje | `frontend/src/components/site-header.tsx` (antes de `<NotificationBell />`) |
| Validador | `scripts/check-docs-links.mjs` |

## Por qué en la barra superior y no junto al título de cada pantalla

Se evaluaron las dos. La decisión fue de cobertura:

- `PageHeader.tsx` (el encabezado compartido) alcanza **76 de 107** pantallas.
  Las 25 que faltan son páginas de **detalle** y los **5 tableros**, que pintan su
  `<h1>` a mano.
- `SiteHeader` alcanza **las 107**, porque `DashboardLayout` envuelve todas las
  rutas autenticadas.

Poner el botón en los dos lugares habría duplicado la misma acción en 76
pantallas, que se lee como descuido. Se eligió un solo punto.

## Por qué el destino NO se pasa por props

Se resuelve dentro del botón con `useLocation()` contra el registro. Eso da:

- **Cero ediciones** en las 100+ pantallas.
- Un solo lugar que cambiar cuando el manual se reorganiza.

La clave del mapa es el **primer segmento de la ruta**, igual que el
`SECTION_LABELS` que ya existía en `site-header.tsx`. `/charge-batch/123` →
`charge-batch`.

## El problema real: los enlaces se pudren

El sistema de diseño de GitLab desaconseja enlazar de la interfaz a la
documentación justamente porque los enlaces quedan apuntando a páginas que ya no
existen y nadie se entera.

La respuesta aquí es `scripts/check-docs-links.mjs`, que revisa **dos frentes** y
**falla con código 1** si algo apunta al vacío:

1. **El registro de la app** → que la página exista.
2. **Los enlaces internos del manual** → que la página exista **y, si el enlace
   lleva `#`, que el encabezado exista.**

Ese segundo punto se agregó después (2026-08) al detectar el hueco: la primera
versión recortaba el `#` antes de comparar, así que un enlace a un encabezado
renombrado pasaba en silencio. **Renombrar un encabezado es más frecuente que
renombrar una página**, así que era el agujero más probable.

Los slugs se calculan imitando a `github-slugger` (el que usa Starlight):
minúsculas, sin puntuación, espacios por guiones, **acentos conservados**, y
sufijo `-1`, `-2` en encabezados repetidos. Los `#` dentro de bloques de código
se ignoran.

Probado en los tres sentidos: renombrar un encabezado enlazado, romper una
página del registro, y el caso sano. Al fallar **nombra cada archivo** que
contiene el enlace roto.

Lo corre solo el hook `Stop` (`.claude/hooks/docs-drift-check.sh`), pero **córrelo
tú antes de terminar** cualquier trabajo que mueva páginas **o renombre
encabezados**.

## Detalles que conviene no romper

- Una ruta **sin entrada** cae a la portada `/docs`. Es deliberado: las pantallas
  de administración de plataforma (`tenant`, `billing-package`, `email`) no se
  documentan en público.
- El texto del botón cambia según el caso: *"Ayuda de esta pantalla"* cuando hay
  página propia, *"Manual de Kivo7"* cuando cae al respaldo. No prometas una guía
  específica que no existe.
- Abre en pestaña nueva (`target="_blank"` + `rel="noopener noreferrer"`) a
  propósito: perder un formulario a medio llenar sería peor que el beneficio.
- El origen sale de `VITE_DOCS_BASE_URL`, con respaldo `https://kivo7.com`.
- Se reusó el patrón de ayuda que ya existía en el proyecto: icono `CircleHelp`
  de lucide, `aria-label` que empieza con "Ayuda:", `TooltipContent` de 260px.
  `TooltipProvider` ya viene montado por `SidebarProvider`, no hace falta otro.

## Estado

74 entradas en el registro, todas verificadas contra páginas existentes.
Typecheck y lint del frontend limpios.
