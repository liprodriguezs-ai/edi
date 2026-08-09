# Plan 033: Backlog de complejidad — llevar `pnpm lint` a cero

> **Executor instructions**: Follow this plan step by step. Run every
> verification command before moving on. On any STOP condition, stop and report.
> When done, update your row in `plans/README.md`.
>
> **Drift check (run first)**: `cd frontend && pnpm lint` y comparar la lista
> de ofensores con la tabla de abajo. Archivos ya resueltos por planes previos
> (024, 030, 031, 032) NO deben aparecer; si aparecen, esos planes no corrieron
> — ejecutarlos primero.

## Status

- **Priority**: P2
- **Effort**: L (mecánico pero ancho: ~25 sitios en ~24 archivos)
- **Risk**: MED (refactors de render sin tests en su mayoría; mitigado por ser extracciones sin lógica nueva)
- **Depends on**: plans/024 (HARD — sin el autofix el lint no es señal), plans/030, 031, 032 (HARD para sus archivos; este plan cubre el RESTO)
- **Category**: dx / tech-debt
- **Planned at**: frontend submodule `e5296c9`, 2026-06-12

## Why this matters

Tras los planes 024/030/031/032 quedará un backlog de ~25 violaciones de
`complexity` (límite 15) y 2 de `max-lines` repartidas por los módulos. Con
lint en cero, el pre-commit vuelve a ser una señal binaria confiable y la regla
de complejidad pasa de "backlog conocido" a "gate real" para todo PR futuro.

## Current state

Censo EXACTO medido con `pnpm lint` el 2026-06-12 (archivo :: línea ::
complejidad). Excluidos los que cubren otros planes (useBaseList → 030,
ChargeBatchFormModal → 031, ConfigurationPage → 032):

| Archivo | Sitio | Compl. |
|---|---|---|
| `src/modules/communityProject/CommunityProjectDetailPage.tsx` | 41:46 arrow | **51** |
| `src/modules/treasurerPanel/components/TreasurerHome.tsx` | 49:8 `TreasurerHome` | **51** |
| `src/modules/collaborator/CollaboratorForm.tsx` | 40:60 arrow | 39 |
| `src/modules/unit/UnitDetailPage.tsx` | 37:34 arrow | 36 |
| `src/modules/provider/ProviderListPage.tsx` | 75:8 arrow | 31 |
| `src/modules/user/ChangePlanPage.tsx` | 16:8 `ChangePlanPage` | 28 |
| `src/modules/chargeConcept/ChargeConceptListPage.tsx` | 86:8 arrow (+71:1 `ConceptFormModal` 16; archivo 522 líneas) | 28 |
| `src/modules/expense/components/ExpenseFormModal.tsx` | 57:8 arrow (+44:8 fn 17) | 28 |
| `src/modules/payment/StatementPage.tsx` | 30:33 arrow | 25 |
| `src/shared/components/PermissionGuard.tsx` | 28:26 arrow | 23 |
| `src/modules/unit/UnitForm.tsx` | 41:52 arrow (+40:83 arrow 21) | 23 |
| `src/modules/tenant/components/TenantTableRow.tsx` | 20:127 arrow | 22 |
| `src/shared/components/SearchList.tsx` | 55:21 arrow | 22 |
| `src/modules/checkoutSubscription/components/PlanCard.tsx` | 14:8 `PlanCard` | 22 |
| `src/modules/bankAccount/BankAccountListPage.tsx` | 77:8 arrow | 21 |
| `src/modules/payment/components/RegisterPaymentSheet.tsx` | 63:8 `RegisterPaymentSheet` | 21 |
| `src/modules/collaborator/components/CollaboratorProfileHeader.tsx` | 37:8 fn | 21 |
| `src/modules/user/SubscriptionPage.tsx` | 64:1 `CurrentPlanSection` | 20 |
| `src/modules/charge/ChargeListPage.tsx` | 40:34 arrow (+119:42 arrow 16) | 20 |
| `src/modules/access/VisitorListPage.tsx` | 133:43 arrow | 18 |
| `src/modules/collaborator/CollaboratorDetail.tsx` | 27:8 fn | 17 |
| `src/modules/communityProject/ProjectContributionListPage.tsx` | 81:48 arrow | 17 |
| `src/modules/unit/components/AssociatePersonModal.tsx` | 50:8 arrow | 17 |
| `src/modules/userProfile/UserProfileListPage.tsx` | 21:29 arrow | 17 |
| `src/modules/chargeBatch/ChargeBatchDetailPage.tsx` | 35:41 arrow | 16 |
| `src/modules/payment/PaymentListPage.tsx` | 194:43 arrow | 16 |
| `emails/_components/OrderStatus.tsx` | 21:22 arrow | 16 |

`max-lines` restantes: `src/components/ui/sidebar.tsx` (672 — shadcn VENDORED)
y `src/modules/chargeConcept/ChargeConceptListPage.tsx` (522).

CORRECCIÓN de auditoría previa: la "complejidad 51 en
`collaboratorImport.ts`" reportada por un revisor era atribución errónea — ese
archivo lintea limpio; los 51 reales son los dos primeros de la tabla.

## Commands you will need

| Purpose | Command | Expected on success |
|---------|---------|---------------------|
| Lint global | `cd frontend && pnpm lint` | **exit 0, 0 problems** al terminar |
| Lint por archivo | `pnpm exec eslint <path>` | exit 0 tras cada fix |
| Build / Tests | `pnpm build` / `pnpm test` | exit 0 / pass |

## Scope

**In scope**: los archivos de la tabla. **Técnicas permitidas** (en orden de
preferencia):
1. Extraer mapas de configuración: cadenas `cond ? X : cond2 ? Y : ...` sobre
   un status/tipo → objeto lookup `Record<status, {...}>` a nivel de módulo.
2. Extraer subcomponentes de render por rama (mismo archivo o `components/`).
3. Early returns para aplanar nesting.
4. Extraer funciones nombradas puras para cómputos.

**Out of scope**:
- `src/shared/hooks/useBaseList.ts` (plan 030), `ChargeBatchFormModal` (031), `ConfigurationPage` (032).
- Subir el límite de complejidad o agregar `eslint-disable` (PROHIBIDO, con la
  única excepción del Step 1).
- Cambios de comportamiento/UX de cualquier tipo.

## Git workflow

- Commits dentro del submódulo `frontend/`, POR MÓDULO (p.ej.
  `refactor(unit): reduce complexity in UnitForm and UnitDetailPage`) — facilita
  revert y review.

## Steps

### Step 1: `sidebar.tsx` vendored

`src/components/ui/sidebar.tsx` es shadcn vendored (672 líneas): NO refactor.
Agregar override en la config de ESLint (`eslint.config.*`) desactivando
`max-lines` SOLO para `src/components/ui/**` con comentario "vendored shadcn".

**Verify**: `pnpm exec eslint src/components/ui/sidebar.tsx` → exit 0.

### Step 2: Los dos 51 primero

`CommunityProjectDetailPage.tsx:41` y `TreasurerHome.tsx:49`. Son páginas de
agregación con muchas ramas de estado: aplicar técnica 1 (mapas de
status→render) + 2 (subcomponentes por card/sección). Smoke manual de ambas
páginas tras el fix (proyecto con aportaciones; panel del tesorero con datos).

**Verify**: `pnpm exec eslint` de ambos → exit 0; `pnpm build` exit 0.

### Step 3: Barrido por módulo (de mayor a menor complejidad)

Para cada archivo de la tabla: aplicar la técnica mínima que baje TODAS sus
funciones a ≤15. `ChargeConceptListPage` además debe quedar <500 líneas
(extraer `ConceptFormModal` a `components/` — resuelve ambas violaciones).
Tras CADA archivo: lint del archivo + `pnpm build`.

**Verify**: por archivo, exit 0.

### Step 4: Gate global

**Verify**: `pnpm lint` → **0 problems**; `pnpm test` all pass; `pnpm build` exit 0.

## Test plan

- Suite existente verde tras cada commit (los tests del plan 029 cubren los
  flujos más sensibles).
- Smoke manual mínimo por módulo tocado: abrir la página y ejercitar la rama
  más condicional (p.ej. RegisterPaymentSheet: pago con monto mayor al saldo).

## Done criteria

- [ ] `pnpm lint` → exit 0, **0 problems** (errores Y warnings)
- [ ] Cero `eslint-disable` nuevos fuera del override de `src/components/ui/**`
- [ ] `pnpm test` all pass; `pnpm build` exit 0
- [ ] `plans/README.md` row updated

## STOP conditions

- Un archivo de la tabla requiere REESCRITURA de lógica (no extracción) para
  bajar de 15 → sacarlo del barrido, anotar y seguir; reportarlo al final como
  candidato a plan propio.
- `pnpm lint` reporta ofensores NUEVOS no listados (drift desde la
  planificación): agregarlos al censo y continuar, anotándolos en el reporte.
- Cualquier smoke manual muestra comportamiento distinto.

## Maintenance notes

- A partir de lint=0: cualquier PR con un error de lint nuevo se rechaza — ya
  no hay backlog que lo camufle. Considerar agregar `pnpm lint` al CI job del
  frontend (el gate de tests ya existe por el plan 008 del ciclo 1).
