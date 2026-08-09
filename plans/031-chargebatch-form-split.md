# Plan 031: Partir `ChargeBatchFormModal` (552 líneas, complejidad 21/28) en subcomponentes

> **Executor instructions**: Follow this plan step by step. Run every
> verification command before moving on. On any STOP condition, stop and report.
> When done, update your row in `plans/README.md`.
>
> **Drift check (run first)**:
> `git -C frontend diff --stat e5296c9..HEAD -- src/modules/chargeBatch/`
> OJO: este archivo aparece MODIFICADO sin commitear en el working tree
> auditado — el operador debe commitear el trabajo en curso antes de ejecutar.
> Ante mismatch con los excerpts, STOP.
> **Gate de entrada**: tests de plan 029 verdes (`pnpm test`), idealmente
> plan 024 ya aplicado (lint limpio de autofixables).

## Status

- **Priority**: P2
- **Effort**: L
- **Risk**: HIGH (creación de lotes de cobro = impacto financiero directo)
- **Depends on**: plans/029-frontend-characterization-tests.md (HARD); plans/024 (soft)
- **Category**: tech-debt
- **Planned at**: frontend submodule `e5296c9`, 2026-06-12

## Why this matters

Es el formulario más crítico de Collections: 552 líneas (warning `max-lines`),
función principal con complejidad 21 y una arrow interna con 28 (lint medido
2026-06-12). Renderiza condicionalmente 7 `target_type` distintos, una vista
previa en vivo por query, selectores de período y monto override — todo en una
función. Cambiar cualquier target type hoy arriesga a los demás.

## Current state

`src/modules/chargeBatch/components/ChargeBatchFormModal.tsx`:

- Línea 59: `export function ChargeBatchFormModal({ isOpen, onClose, onCreated }: ChargeBatchFormModalProps)` — complejidad 21.
- Líneas 63–84: `defaultValues` por `useMemo` dependiente de `isOpen` (con un
  `eslint-disable react-hooks/exhaustive-deps` interno) usando `new Date()`.
- Líneas 86–101: `useForm` + `reset(defaultValues)` al abrir.
- Líneas 103–113: `useCatalogs` con 4 catálogos (CHARGE_CONCEPT, UNIT_TYPE,
  TOWER, UNIT_FEATURE).
- Líneas 115–129: watches (`target_type`, `charge_concept_id`, `year`,
  `month`) + autocompletado del título desde concepto+período (§8.4.2).
- Líneas 131–175 aprox.: `const formValues = watch()` (FULL watch) +
  `buildTargetSelector(formValues)` + `useQuery` de vista previa
  (`queryKey: ['batch-preview', targetType, previewSelector]`).
- Línea 176: `const selectField = (` — helper de render local.
- Líneas ~180–520: el JSX gigante con las ramas por target_type (la arrow de
  complejidad 28 está acá — `86:8` según lint; confirmar al abrir).
- Líneas 536+: `function ManualUnitPicker(...)` — YA extraído como componente
  hermano en el mismo archivo (patrón a imitar).

## Commands you will need

| Purpose | Command | Expected on success |
|---------|---------|---------------------|
| Tests | `cd frontend && pnpm test` | all pass (incl. buildTargetSelector del 029) |
| Lint del módulo | `pnpm exec eslint src/modules/chargeBatch/` | exit 0 al terminar |
| Build | `pnpm build` | exit 0 |

## Scope

**In scope**:
- `src/modules/chargeBatch/components/ChargeBatchFormModal.tsx`
- Archivos NUEVOS bajo `src/modules/chargeBatch/components/` (subcomponentes)

**Out of scope**:
- `schema.ts` del módulo, `buildTargetSelector` y su test (plan 029) — INTOCABLES.
- El contrato con el backend (payload del submit) — byte-igual.
- `ChargeBatchDetailPage`, `ChargeBatchListPage` (su complejidad 16 la cubre el plan 033).

## Git workflow

- Commits dentro del submódulo `frontend/`, UNO por extracción (revert
  quirúrgico): `refactor(charge-batch): extract TargetTypeSection` etc.

## Steps

### Step 1: Extraer `MonthYearSelector`

Subcomponente controlado por RHF (`control`/`register` via props o
`useFormContext` — elegir lo que el archivo ya estile; si no usa FormProvider,
pasar `control` por prop como hace `ManualUnitPicker`). Mover también
`MONTH_NAMES` si solo se usa ahí.

**Verify**: `pnpm build` exit 0; `pnpm test` pass.

### Step 2: Extraer `TargetTypeSection`

El bloque condicional completo de los 7 target types (all / by_type / by_tower /
by_floor / manual / by_feature / single_unit), incluidos los campos
`target_unit_type_id`, `target_tower_id`, `target_floor`, `target_unit_ids`
(usa `ManualUnitPicker`), `target_feature`, `target_unit_id` +
`singleUnitLabel`. Recibe los catálogos por props. La meta es que la arrow de
complejidad 28 desaparezca: dentro de la sección, un `switch (targetType)` que
delega a mini-componentes por rama o early returns.

**Verify**: `pnpm build` exit 0; smoke: cambiar entre los 7 tipos renderiza los
campos correctos.

### Step 3: Extraer `BatchPreview`

El `useQuery` de vista previa + su render. Recibe `targetType` y
`previewSelector` por props (la construcción del selector queda en el padre,
que es quien tiene el form). Mantener el `queryKey` EXACTO
(`['batch-preview', targetType, previewSelector]`).

**Verify**: `pnpm build` exit 0; smoke: la vista previa actualiza el conteo al
cambiar el target.

### Step 4: `defaultValues` a función pura

`buildChargeBatchDefaults(now: Date): ChargeBatchFormValues` exportada del
módulo (testeable; elimina el eslint-disable del useMemo). El componente la
llama con `new Date()` — eso NO viola el anti-pattern §4 (es lógica de
defaults, no formateo de display).

**Verify**: `pnpm test` pass.

### Step 5: Gates finales

**Verify**: `pnpm exec eslint src/modules/chargeBatch/` → exit 0 (cero
`complexity`, cero `max-lines`); `wc -l` del modal ≤ ~300; `pnpm test` all pass.

### Step 6: Smoke E2E manual (OBLIGATORIO antes de dar por hecho)

Con backend corriendo: crear un lote `all` activado y verificar conteo de
charges; crear un `single_unit` en draft; cancelar uno. Comparar contra el
comportamiento pre-refactor.

## Test plan

- `buildTargetSelector.test.ts` (plan 029) verde SIN cambios.
- Nuevo: test de `buildChargeBatchDefaults` (defaults correctos para una fecha
  fija — sin `new Date()` real en el test).
- Si plan 029 dejó characterization del modal, verde sin cambios.

## Done criteria

- [ ] `pnpm exec eslint src/modules/chargeBatch/` → exit 0
- [ ] `ChargeBatchFormModal.tsx` ≤ ~300 líneas; cero funciones >15 de complejidad en el módulo
- [ ] Payload de submit idéntico (verificar en Network tab durante el smoke: mismos campos)
- [ ] `pnpm test` all pass; `pnpm build` exit 0
- [ ] `plans/README.md` row updated

## STOP conditions

- El working tree del frontend tiene cambios sin commitear sobre este módulo.
- La vista previa deja de refrescar tras extraer `BatchPreview` (señal de
  queryKey/identidad del selector rota) y un intento no lo arregla.
- El smoke E2E muestra CUALQUIER diferencia en los charges creados (conteo,
  montos, unidades alcanzadas) → revertir el step y reportar.
- Extraer una sección exige duplicar estado del form en useState locales →
  diseño equivocado; replantear con control/useFormContext.

## Maintenance notes

- Nuevos target types: agregar la rama SOLO en `TargetTypeSection` + un caso a
  `buildTargetSelector.test.ts`.
- Reviewer: diff del payload de submit y del queryKey de la preview — son los
  dos puntos donde una regresión sería silenciosa.
