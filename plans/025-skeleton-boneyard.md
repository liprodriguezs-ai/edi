# Plan 025: Migrar skeletons de shadcn/`animate-pulse` a boneyard-js (anti-pattern §4)

> **Executor instructions**: Follow this plan step by step. Run every
> verification command before moving on. On any STOP condition, stop and report.
> When done, update your row in `plans/README.md`.
>
> **Drift check (run first)**:
> `git -C frontend diff --stat e5296c9..HEAD -- src/modules/role src/modules/collaborator src/shared/components/molecules/TableLoadingState.tsx`
> Ante mismatch con los excerpts, STOP.

## Status

- **Priority**: P2
- **Effort**: S
- **Risk**: LOW (cambio visual de loading states, sin lógica)
- **Depends on**: none
- **Category**: tech-debt
- **Planned at**: frontend submodule `e5296c9`, 2026-06-12

## Why this matters

`CLAUDE.md` §4 (fuente autoritativa de anti-patterns) prohíbe dos cosas que
estos archivos hacen: usar el `Skeleton` de shadcn y usar `animate-pulse` de
Tailwind para loading. El proyecto estandarizó `boneyard-js` (accesibilidad
`aria-live` incluida). Hoy hay 7 archivos con el import prohibido y 1 con
`animate-pulse`, así que los loading states se ven y comportan distinto según
el módulo.

## Current state

Archivos con `import { Skeleton } from '@/components/ui/skeleton'` (grep
verificado 2026-06-12):

1. `src/modules/role/RoleDetail.tsx`
2. `src/modules/role/components/RolePermissionsEditor.tsx`
3. `src/modules/collaborator/components/CollaboratorDataTab.tsx`
4. `src/modules/collaborator/me/MyDataCard.tsx`
5. `src/modules/collaborator/me/MyDocumentsCard.tsx`
6. `src/modules/collaborator/components/CollaboratorProfileHeader.tsx`
7. `src/modules/collaborator/me/DossierStatusCard.tsx`

`src/shared/components/molecules/TableLoadingState.tsx` líneas 19–37 — rama
`showSkeletons` con `animate-pulse`:

```tsx
if (showSkeletons) {
  return (
    <div className={cn('space-y-3', className)}>
      {Array.from({ length: rows }).map((_, index) => (
        <div
          key={index}
          className="flex items-center space-x-4 rounded-lg border border-border/50 bg-card/30 p-4 animate-pulse"
        >
          ...
```

Convención a seguir (de `CLAUDE.md` §4): `<Skeleton name="nombre-unico"
loading={isLoading}>{contenido}</Skeleton>`, importado de `'boneyard-js/react'`.
ANTES de tocar nada, buscar 2–3 usos correctos existentes como exemplar:
`grep -rn "boneyard-js/react" frontend/src | head -5` y leer uno.

## Commands you will need

| Purpose | Command | Expected on success |
|---------|---------|---------------------|
| Grep gate | `grep -rln "components/ui/skeleton" frontend/src/modules frontend/src/shared` | vacío al terminar |
| Lint | `cd frontend && pnpm lint` | sin errores NUEVOS vs baseline |
| Build | `cd frontend && pnpm build` | exit 0 |
| Tests | `cd frontend && pnpm test` | all pass |

## Scope

**In scope**: los 8 archivos listados arriba.

**Out of scope**:
- `src/components/ui/skeleton.tsx` (el componente shadcn en sí — NO borrarlo en
  este plan; otros usos podrían existir fuera de modules/shared, y borrar
  vendored shadcn es decisión aparte).
- Cualquier otro `animate-pulse` decorativo NO asociado a loading (si aparece,
  anotarlo, no tocarlo).

## Git workflow

- Commit dentro del submódulo `frontend/`. Ej:
  `refactor(ui): migrate skeletons to boneyard-js per project convention`.

## Steps

### Step 1: Leer el exemplar

`grep -rn "boneyard-js/react" frontend/src | head -5` → abrir un uso real y
copiar el patrón exacto (props `name` y `loading`).

### Step 2: Migrar los 7 archivos de módulos

En cada uno: reemplazar el import por `import { Skeleton } from
'boneyard-js/react'` y envolver el contenido real con
`<Skeleton name="<kebab-descriptivo>" loading={...}>`. En archivos que hoy
tienen un sub-componente `*Skeleton` dedicado (p.ej.
`CollaboratorProfileHeader` línea 40–42 hace
`if (isLoading) return <CollaboratorProfileHeaderSkeleton />`), preferir el
wrap del contenido y borrar el sub-componente skeleton local si queda sin uso.
`name` debe ser único por sitio.

**Verify** (tras cada archivo): `pnpm build` → exit 0.

### Step 3: `TableLoadingState`

Reemplazar la rama `showSkeletons` (el map con `animate-pulse`) por el Skeleton
de boneyard envolviendo una estructura de filas equivalente. La rama spinner
(resto del archivo) NO se toca.

**Verify**: `grep -n "animate-pulse" src/shared/components/molecules/TableLoadingState.tsx` → 0 matches.

### Step 4: Gates finales

**Verify**: grep gate vacío + `pnpm lint` sin errores nuevos + `pnpm test` pass.

## Test plan

Sin tests nuevos (cambio visual). Smoke manual recomendado: abrir Colaboradores
(detalle y "Mi expediente") y Roles con red throttled y confirmar que los
skeletons aparecen.

## Done criteria

- [ ] `grep -rln "components/ui/skeleton" frontend/src/modules frontend/src/shared` → vacío
- [ ] `grep -rn "animate-pulse" frontend/src/shared/components/molecules/TableLoadingState.tsx` → vacío
- [ ] `pnpm build` exit 0; `pnpm test` pass
- [ ] `plans/README.md` row updated

## STOP conditions

- No existe NINGÚN uso previo de `boneyard-js/react` en src (sin exemplar, el
  patrón de `name` sería inventado: reportar antes).
- El wrap de boneyard rompe el layout de algún card de forma evidente y el fix
  no es obvio.

## Maintenance notes

- Nuevos componentes con loading: SIEMPRE boneyard. El reviewer debe rechazar
  imports de `@/components/ui/skeleton` en PRs futuros (vale agregar una regla
  `no-restricted-imports` de ESLint como follow-up barato).
