# Plan 033 — Estado real y errores de tipo restantes

> **Contexto (2026-06-12, sesión de ejecución del audit ciclo 2).** El plan 033
> original ("backlog de complejidad → lint 0") se ejecutó como una **flota de
> sub-agentes en background descontrolada** que se pisó entre sí, dejó 3 archivos
> a medias y **rompió el build** en varios archivos que YA estaban verdes
> (corrompió trabajo de planes 030/031/032). El usuario interrumpió, **quitó la
> regla `complexity` de `eslint.config.js`** (la regla que era el propósito de
> 033) y pidió arreglar el build sin revertir.

## Qué se hizo (verde y commiteado)

- **Build restaurado** — `frontend` commit `68b15fd` `fix(build): restore missing
  useMemo import and type-only UseMutationResult import`:
  - `ChargeBatchFormModal.tsx` usaba `useMemo` sin importarlo (ReferenceError en runtime).
  - `CollaboratorDetail.tsx` importaba el TIPO `UseMutationResult` como valor →
    rollup `MISSING_EXPORT` bajo `verbatimModuleSyntax`. Se separó a `import type`.
- **3 archivos a medias descartados** (trabajo abandonado del agente muerto;
  como la regla complexity ya no existe, su refactor no servía): `git checkout`
  restauró las versiones commiteadas buenas de `TenantTableRow.tsx`,
  `PermissionGuard.tsx`, `SearchList.tsx`.
- **Estado verificado**: `pnpm build` ✓ (vite, ~2.9s) · `pnpm test` ✓ (56/56) ·
  `pnpm dev`/runtime sin afectar.
- **Backup del trabajo de la flota**: branch `backup/plan-033-fleet` → `4889fb0`
  (los 9 commits `refactor(lint)`/`fix(lint)` de 033 quedan ahí por si se quiere
  rescatar algo). NO mergeado.

## Decisión del usuario

- **Quitó la regla `complexity`** de `eslint.config.js` (cambio sin commitear en
  el working tree del frontend al cierre de esta sesión). El objetivo de 033 dejó
  de ser "complejidad ≤15".
- Eligió **quedarse en verde** (build + tests) y dejar los errores de tipo
  (no bloqueantes) documentados acá para limpiar después.

## Errores de tipo restantes (40 — NO bloquean build ni runtime)

`vite build` no corre `tsc` (el build es solo `vite build --mode production`), y
`vitest` no typechequea — por eso build y tests pasan con estos errores presentes.
Para verlos: `cd frontend && npx tsc -p tsconfig.app.json --noEmit`.

Agrupados por causa (todos introducidos por la flota de 033):

| Archivo(s) | # | Causa raíz | Fix sugerido |
|---|---|---|---|
| `modules/configuration/ConfigurationPage.tsx` (43,44,52,60,68,69,77) | 7 | Las secciones extraídas (plan 032) tipan la prop mutation como `UseMutationResult<unknown, Error, X>`; `useConfiguration()` devuelve `<unknown, unknown, X>`. | Alinear el tipo de error en las props de las secciones (`GeneralConfigSection`, etc.) a `unknown`, o tipar las mutations del hook con `Error`. |
| `modules/collaborator/CollaboratorForm.tsx` (239,313,321,329) | 4 | `CatalogSelect` declara `control: Control<FieldValues>` pero recibe `Control<CollaboratorFormValues>`; y `useCollaboratorForm(entity)` espera `ICollaborator \| undefined` pero recibe `Partial<ICollaborator> \| null`. | Tipar `CatalogSelect` con el tipo de form específico (o genérico `<T extends FieldValues>`); normalizar `entity ?? undefined`. |
| `modules/unit/UnitForm.tsx` (50,59,61,277) | 4 | `string \| null \| undefined` asignado a `string \| undefined` en buildDefaults; `Partial<IUnit> \| null` a `IUnit \| null \| undefined`. | `?? undefined` / `?? ''` en los defaults; normalizar el parámetro entity. |
| `modules/tenant/components/TenantSystemsModal.tsx` (36,118) · `modules/subscriptionPlan/components/PlanSystemsModal.tsx` (32,92) | 4 | `SystemResource.list` (plan 027): "No overload matches" + `allSystems` es `unknown`. La firma del resource no coincide con cómo lo llaman/tipan los modales. | Revisar la firma de `SystemResource.list` y tipar el retorno de la query (`useQuery<ISystem[]>`). |
| `modules/chargeConcept/ChargeConceptFormModal.tsx` (87,420) | 2 | `boolean \| undefined` a `boolean`; el subcomponente extraído `AmountModeFields` se invoca sin las props `name, label`. | Default `?? false`; pasar `name`/`label` (o hacerlas opcionales en `AmountModeFieldsProps`). |
| `shared/hooks/useBaseList.ts` (77) · `shared/hooks/baseListParams.ts` (82) | 2 | Refactor plan 030: `IPagination` vs `StorePagination`; `Sort[]` vs `{field,direction}[]`. | Alinear los tipos de los helpers puros extraídos con los del store. |
| `modules/payment/StatementPage.tsx` (244) | 1 | Objeto `{name?,bank?,...}` no asignable a `BankDataInfo`. | Normalizar nulls del objeto a la forma de `BankDataInfo`. |
| `modules/unit/UnitDetailPage.tsx` (32) | 1 | `{title, description}` pasado donde se espera `string` (subtítulo). | Pasar el string correcto. |
| `shared/hooks/useBaseList.test.ts` | 15 | Tipos laxos en el test de caracterización (plan 029); no afecta runtime ni el `pnpm test`. | Tipar mocks del store/resource; baja prioridad. |

## Cómo retomar

1. `cd frontend && npx tsc -p tsconfig.app.json --noEmit` → lista viva.
2. Arreglar por el orden de la tabla (de arriba hacia abajo = más impacto primero).
3. Gate al terminar: `npx tsc -p tsconfig.app.json --noEmit` → 0 · `pnpm build` ✓ · `pnpm test` ✓.
4. Considerar agregar `tsc --noEmit` al script de `build` o al CI para que esto no
   vuelva a colarse (hoy el build NO typechequea — esa es la razón por la que la
   flota pudo romper tipos sin que el build lo detectara).

## Lección (para el índice principal)

Ejecutar refactors masivos como flota de sub-agentes paralelos sobre UN MISMO
repo, sin gate de `tsc`/build entre commits, corrompe trabajo previo en silencio.
Los refactors de archivos con cobertura de tests delgada deben ir de a uno, con
build+tsc+test verificados por commit.
