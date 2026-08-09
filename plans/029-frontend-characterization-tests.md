# Plan 029: Tests de caracterización — useBaseList y flujos críticos de Categoría C

> **Executor instructions**: Follow this plan step by step. Run every
> verification command before moving on. On any STOP condition, stop and report.
> When done, update your row in `plans/README.md`.
>
> **Drift check (run first)**:
> `git -C frontend diff --stat e5296c9..HEAD -- src/shared/hooks/useBaseList.ts src/modules/access src/modules/chat src/modules/chargeBatch`
> Ante mismatch con los excerpts, STOP.

## Status

- **Priority**: P1 (prerequisito de los planes 030 y 031)
- **Effort**: M
- **Risk**: LOW (solo tests)
- **Depends on**: none
- **Category**: tests
- **Planned at**: frontend submodule `e5296c9`, 2026-06-12

## Why this matters

Dos refactors de alto riesgo están planeados (030: `useBaseList`, el hook que
alimenta 20+ listas; 031: `ChargeBatchFormModal`, el form financiero más
crítico). Hoy NO hay tests de comportamiento que detecten una regresión en
ellos: solo existen tests de schemas Zod y `money.test.ts`. Tampoco hay tests
en los flujos nuevos de Categoría C que operan en tiempo real (scan de QR en
caseta, envío de chat). Este plan crea la red de seguridad ANTES de mover nada.

## Current state

- Infra: Vitest instalado (plan 009 del ciclo 1); script `pnpm test` = `vitest run`.
  Tests existentes como patrón estructural: `src/shared/utils/money.test.ts`
  (puro) y `src/modules/access/schema.test.ts` (Zod). VERIFICAR si
  `@testing-library/react` está en `frontend/package.json` — los tests de hooks
  lo requieren (`renderHook`). Si falta: STOP (instalar deps requiere
  aprobación del operador).
- `src/shared/hooks/useBaseList.ts` (355 líneas) — comportamiento a caracterizar:
  - `buildParams` (líneas 65–150): merge de `page/limit` con precedencia
    overrides > currentSearch > store.pagination; con `config.overrideParams`,
    los filters se CONCATENAN `[...currentSearch.filters, ...overrideFilters]`,
    el sort de override PISA al currentSearch, los includes se concatenan y
    DEDUPLICAN por `relation`; `search.value` se trimea y fuerza
    `case_sensitive: false`; passthrough condicional de `aggregates`,
    `user_id`, `scopes`, `active` y `view`.
  - `getList` (líneas 152–218): con `response.meta` arma la paginación desde
    meta (con fallbacks encadenados); SIN meta, calcula `totalPages` con la
    heurística `data.length === pageSize → currentPage + 1`; en error hace
    `removeAllEntities()` + `putMeta(null)` + `toast.error` + re-throw.
  - `onSearch` resetea a página 1; `onReset` LIMPIA filters/search/aggregates
    pero PRESERVA sort e includes (líneas 228–240).
- `src/modules/access/components/ScanQrModal.tsx` — scanner de caseta (Enter
  envía, callback `onScanned`). Leerlo antes de testear.
- `src/modules/chat/hooks/useSendMessage.ts` — mutation de envío usada por
  `ChatPage` (`useSendMessage(channelId, () => setDraft(''))`).
- `src/modules/chargeBatch/` — `buildTargetSelector(formValues)` se usa para la
  vista previa del modal (localizarlo: `grep -rn "buildTargetSelector" src/modules/chargeBatch`).
  Si es función pura exportada, es testeable directo.

## Commands you will need

| Purpose | Command | Expected on success |
|---------|---------|---------------------|
| Tests | `cd frontend && pnpm test` | all pass, N nuevos |
| Test watch (dev) | `pnpm test:watch` | — |
| Lint/build | `pnpm lint` / `pnpm build` | sin errores nuevos / exit 0 |

## Scope

**In scope** (solo creación de tests + mocks):
- `src/shared/hooks/useBaseList.test.ts` (create)
- `src/modules/access/components/ScanQrModal.test.tsx` (create)
- `src/modules/chat/hooks/useSendMessage.test.ts` (create)
- `src/modules/chargeBatch/buildTargetSelector.test.ts` (create; ubicar junto al símbolo real)

**Out of scope**:
- CUALQUIER cambio a código de producción. Si un test revela un bug real,
  documentarlo en el reporte y dejar el test en `.todo`/`skip` con comentario —
  no arreglar el bug acá.

## Git workflow

- Commit dentro del submódulo `frontend/`. Ej:
  `test(frontend): characterization for useBaseList, QR scan, chat send, target selector`.

## Steps

### Step 1: `useBaseList.test.ts` (el corazón del plan)

Estrategia: `renderHook` con un store REAL creado por `createEntityStore`
(no mockear el store — es parte del comportamiento) y un `resource.search`
mock que captura params y devuelve respuestas controladas. Casos mínimos (uno
por bullet de "Current state"):

1. default: llama search con `page/limit` del store y `view` undefined
2. `initialSearch` con `page/limit` pisa al store
3. `overrideParams.filters` se concatena DESPUÉS de los del usuario
4. `overrideParams.sort` pisa; sin override usa el del usuario
5. includes: concat + dedup por relation
6. `search.value` con espacios → trimeado + `case_sensitive: false`
7. response CON meta → pagination desde meta
8. response SIN meta y `data.length === limit` → `pages = page + 1`
9. error de search → entities vacías y NO crashea (toast mockeado)
10. `onReset` preserva sort/includes, limpia filters/search

Mockear `@/shared/hooks/useToast` (vi.mock) para no requerir DOM de sileo.

**Verify**: `pnpm test -- useBaseList` → 10 pass.

### Step 2: `buildTargetSelector.test.ts`

Localizar el símbolo. Casos: cada `target_type`
(all / by_type / by_tower / by_floor / manual / by_feature / single_unit)
produce el selector esperado a partir de formValues mínimos. Si NO es función
exportada pura: STOP parcial — reportar y seguir con los demás steps.

**Verify**: `pnpm test -- buildTargetSelector` → pass.

### Step 3: `ScanQrModal.test.tsx`

Render con resource mockeado: (a) Enter en el input dispara el submit con el
código; (b) éxito → `onScanned` recibe el resultado; (c) error de API → mensaje
visible y NO llama `onScanned`. Modelar mocks según cómo el componente obtenga
su resource (leer el archivo primero).

**Verify**: `pnpm test -- ScanQrModal` → 3 pass.

### Step 4: `useSendMessage.test.ts`

`renderHook` con QueryClientProvider wrapper: éxito → invalida la query de
mensajes y llama el callback (limpiar draft); error → no llama el callback.

**Verify**: `pnpm test` → all pass (suite completa).

## Test plan

Este plan ES el test plan. Patrón estructural: `money.test.ts` para puros;
para hooks, el wrapper de QueryClient se define inline en el test file.

## Done criteria

- [ ] `pnpm test` → all pass; ≥16 tests nuevos entre los 4 archivos
- [ ] `useBaseList.test.ts` cubre los 10 casos listados (cada uno asertando los PARAMS enviados o el estado del store)
- [ ] Cero cambios en `git diff` fuera de archivos `*.test.*`
- [ ] `plans/README.md` row updated

## STOP conditions

- `@testing-library/react` no está instalado (agregar deps requiere aprobación).
- Un test revela comportamiento claramente buggy de producción (p.ej. el merge
  de filters duplica): NO arreglar; documentar y marcar el caso como
  caracterización del comportamiento ACTUAL (los planes 030/031 deciden si se
  preserva).
- `createEntityStore` no permite instanciar un store aislado por test (estado
  global compartido entre tests): reportar el acople en vez de hackear.

## Maintenance notes

- Estos tests caracterizan el comportamiento VIGENTE, bueno o malo. Los planes
  030 y 031 deben correrlos SIN MODIFICARLOS — si un refactor "necesita"
  cambiar un test de este plan, eso es una regresión por definición (salvo
  decisión explícita del operador).
