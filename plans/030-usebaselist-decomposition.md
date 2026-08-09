# Plan 030: Descomponer `useBaseList` (complejidad 35/34 → ≤15) sin cambiar comportamiento

> **Executor instructions**: Follow this plan step by step. Run every
> verification command before moving on. On any STOP condition, stop and report.
> When done, update your row in `plans/README.md`.
>
> **Drift check (run first)**:
> `git -C frontend diff --stat e5296c9..HEAD -- src/shared/hooks/useBaseList.ts`
> Ante mismatch con los excerpts del plan 029 (mismo archivo), STOP.
> **Gate de entrada**: `pnpm test -- useBaseList` debe existir y pasar (plan
> 029 ejecutado). Si no existe, STOP — este refactor no se hace sin esa red.

## Status

- **Priority**: P2
- **Effort**: M
- **Risk**: HIGH (es el hook fundacional de 20+ listas; cualquier regresión rompe todas)
- **Depends on**: plans/029-frontend-characterization-tests.md (HARD)
- **Category**: tech-debt
- **Planned at**: frontend submodule `e5296c9`, 2026-06-12

## Why this matters

`useBaseList.ts` concentra TODA la lógica de params/paginación de las listas en
dos funciones con complejidad ciclomática 35 y 34 (límite del proyecto: 15,
medido con `pnpm lint` el 2026-06-12). Las ramas interactúan (override × search
× store) y no todas las combinaciones están ejercitadas; cada cambio nuevo en
listas pasa por acá a ciegas. La descomposición en funciones puras chicas las
hace testeables una a una y baja el lint a cero en este archivo.

## Current state

- `src/shared/hooks/useBaseList.ts` (355 líneas). Ofensores exactos del lint:
  - línea 65 `buildParams` (arrow, complejidad 35)
  - línea 152 `getList` (async arrow, complejidad 34)
- El detalle del comportamiento a preservar está inventariado en el plan 029
  ("Current state") y PINNEADO por `src/shared/hooks/useBaseList.test.ts` —
  ese archivo es el contrato; este plan NO lo modifica.
- Estilo del archivo: refs para estabilidad de callbacks (`storeRef`,
  `paramsSearchRef`) — patrón a conservar tal cual.

## Commands you will need

| Purpose | Command | Expected on success |
|---------|---------|---------------------|
| Tests del hook | `cd frontend && pnpm test -- useBaseList` | all pass SIN editar el test |
| Suite completa | `pnpm test` | all pass |
| Lint del archivo | `pnpm exec eslint src/shared/hooks/useBaseList.ts src/shared/hooks/baseListParams.ts` | exit 0 |
| Build | `pnpm build` | exit 0 |

## Scope

**In scope**:
- `src/shared/hooks/useBaseList.ts`
- `src/shared/hooks/baseListParams.ts` (create — helpers puros)

**Out of scope**:
- `src/shared/hooks/useBaseList.test.ts` — INTOCABLE (es el contrato).
- `useInitOnce`, `createEntityStore`, `resourceApi` — no tocar.
- La API pública del hook (lo que retorna y las firmas de config) — cero
  cambios; ningún consumidor se modifica.

## Git workflow

- Commits dentro del submódulo `frontend/`, uno por step para revert quirúrgico:
  `refactor(lists): extract pure param builders from useBaseList` /
  `refactor(lists): extract pagination computation from getList`.

## Steps

### Step 1: Extraer builders puros de `buildParams`

Crear `src/shared/hooks/baseListParams.ts` con funciones puras (input → output,
sin refs ni estado):

- `resolvePagination(storePagination, currentSearch, overrides)` → `{ page, limit }`
- `mergeFilterParams(currentSearch, overrideParams)` → partial params (filters
  concat, sort precedencia, includes dedup — EXACTAMENTE la lógica actual de
  las líneas 86–124, ramas con y sin `overrideParams`)
- `buildSearchExtras(currentSearch)` → partial params (search trim +
  case_sensitive, aggregates, user_id, scopes, active — líneas 126–147)

`buildParams` queda como composición: resolver paginación, armar base
`{ page, limit, view }`, `Object.assign` de los partials. Complejidad objetivo
de cada función: ≤10.

**Verify**: `pnpm test -- useBaseList` → all pass sin tocar el test.

### Step 2: Extraer el cálculo de paginación de `getList`

En `baseListParams.ts`:

- `paginationFromMeta(meta, params, dataLength)` → paginationUpdate (líneas 164–175)
- `paginationFromHeuristic(params, dataLength, total)` → paginationUpdate (líneas 176–199, incluida la heurística `data.length === pageSize → page + 1`)

`getList` queda: try → search → elegir una de las dos funciones → `setListResult`
→ catch/finally idénticos.

**Verify**: `pnpm test -- useBaseList` → all pass; `pnpm test` → suite completa pass.

### Step 3: Gates de lint y build

**Verify**:
`pnpm exec eslint src/shared/hooks/useBaseList.ts src/shared/hooks/baseListParams.ts` → exit 0 (cero `complexity`);
`pnpm build` → exit 0.

### Step 4: Smoke de integración

Levantar la app (`pnpm dev`) y recorrer 3 listas distintas que usen override
params e includes (Unidades, Pagos, Avisos): paginar, buscar, resetear filtros.
Comportamiento idéntico.

## Test plan

- Los 10 tests de caracterización del plan 029 pasan SIN MODIFICACIÓN — ese es
  el criterio central.
- Bonus natural (no obligatorio): tests unitarios directos de los builders
  puros nuevos en `baseListParams.test.ts` (entradas borde: arrays vacíos,
  undefined).

## Done criteria

- [ ] `pnpm test` → all pass; `useBaseList.test.ts` con CERO diffs (`git diff --stat -- src/shared/hooks/useBaseList.test.ts` vacío)
- [ ] `pnpm exec eslint src/shared/hooks/useBaseList.ts` → exit 0
- [ ] API pública del hook sin cambios (ningún archivo consumidor en el diff)
- [ ] `pnpm build` exit 0
- [ ] `plans/README.md` row updated

## STOP conditions

- El test de caracterización falla y la única forma de hacerlo pasar es
  cambiar el test → regresión por definición; revertir el step y reportar.
- Para extraer una función "hace falta" pasarle un ref o el store → la
  extracción está mal cortada (los puros reciben VALORES, no refs); replantear
  antes que forzar.
- Aparece un consumidor que dependía de un detalle interno no cubierto por los
  tests del 029 (p.ej. identidad de objetos retornados).

## Maintenance notes

- Lógica nueva de params va en `baseListParams.ts` como función pura + test
  directo; el hook solo compone.
- Reviewer: verificar especialmente la rama SIN `overrideParams` (líneas
  114–124 originales) — es fácil perderla al unificar.
