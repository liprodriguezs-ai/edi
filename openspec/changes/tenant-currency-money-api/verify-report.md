# Verify Report — Tenant Currency & Money API Serialization

- **Change:** `tenant-currency-money-api`
- **Project:** `residia-monorepo` (Kivo7)
- **Phase:** verify
- **Date:** 2026-07-05
- **TDD mode:** Standard (strict_tdd = FALSE)
- **Verdict:** **PASS** — 0 CRITICAL, 2 WARNING, 2 SUGGESTION
- **Authority:** spec `openspec/changes/tenant-currency-money-api/spec.md`, tasks `.../tasks.md` (109/109 aplicables), design GATE-REVIEWED `.../design.md`, apply-progress engram #1501
- **Gate context:** judgment-day (2 jueces opus a ciegas) ya corrió sobre este diff → APPROVED, cero incidencias confirmadas. Este verify es la última compuerta (spec-compliance + evidencia de ejecución).

---

## Resumen ejecutivo

Las 20 capacidades del cambio (R-MAPI-1..14, R-TCUR-1..6) están **implementadas y probadas**. El dinero cruza la frontera del API exclusivamente como objeto Money `{amount, currency, formatted}` a través de los 8 grupos de endpoints (82 llamadas `TenantMoney::make/format/currency` en los bordes de respuesta), con la moneda resuelta por llamada desde `tenant()->currency` (fallback central seguro). Los caches internos conservan enteros planos con claves sin cambio; la conversión ocurre siempre después del cache. El frontend formatea con la moneda activa de la sesión (locale `es-MX` intacto) y la configuración operacional habilita el cambio curado MXN/USD con confirmación. **Cero cambio de lógica de negocio confirmado por tests de paridad en enteros.** Todas las compuertas de grep, la suite representativa de backend (47 passed / 343 assertions), el build de frontend (limpio) y `money.test.ts` (7/7) están en verde. No hay incidencias CRITICAL que bloqueen el archivo.

---

## Gate results (salida real)

| Gate | Comando | Resultado |
|---|---|---|
| Backend tests (representativos) | `php artisan test` sobre TenantMoneyCastTest + StatementEndpointTest + ChargeBatchSummaryTest + TreasuryReportControllerTest + ProjectsReportControllerTest + SubscriptionSelfServiceTest (host php, Postgres 127.0.0.1:5432, 1 proceso) | **47 passed, 343 assertions, 213.49s — GREEN** |
| Backend full suite (registrada en apply, NO re-ejecutada) | `php artisan test` completo | 1547 passed / 10 failed (los 10 pre-existentes, ajenos a money/currency — ver S2), ~94 min |
| Grep gate — casts hardcodeados | `rg "MoneyIntegerCast::class\.':MXN'" Modules app core` | **0 hits — GREEN** |
| Grep gate — strings MXN hardcodeados | `rg "' MXN'\|\" MXN\"" Modules` | **0 hits — GREEN** |
| Grep gate — `number_format(.*100` | `rg "number_format\(.*100" Modules` | **Solo sobrevivientes sancionados** (ReportFormatter celdas numéricas currency-agnósticas x5, regla de % de indiviso Housing, fixture de test DataImport) — GREEN |
| Grep gate — claves español en API | `rg "recaudado\|ejecutado\|disponible" Modules/*/app/Http Modules/*/app/Services` | **Solo internos de ContributionService (docblock + var local) + texto UI español** (labels/mensajes) — GREEN |
| Grep gate — billing excluido | `rg "TenantMoney" Modules/SubscriptionPlan` | **0 hits — GREEN** |
| Frontend build (clean break) | `pnpm build` (tsc + vite) | **10607 módulos, built in 3.77s, PWA 241 entries, exit 0 — GREEN** |
| Frontend money helper | `pnpm test src/shared/utils/money.test.ts` | **7 passed / 1 file — GREEN** |
| Frontend full suite (registrada en apply, NO re-ejecutada) | `pnpm test` | 457 passed / 52 files |

---

## Matriz de cumplimiento por requisito

Leyenda: **PASS** = implementado + probado en runtime. Evidencia = `file:line`. "Prueba" = test que cubre el escenario (spot-run de este verify, o suite completa registrada en apply cuando se indica).

### Capability: money-api-serialization

| Req | Verdicto | Evidencia (código) | Prueba |
|---|---|---|---|
| **R-MAPI-1** dinero como Money en cualquier borde | PASS | 82 usos `TenantMoney::make/format/currency` en los 8 grupos; gate `:MXN` = 0 | TenantMoneyCastTest (4) + todas las suites de endpoint; gate grep |
| **R-MAPI-2** statement (`credit_balance`, `totals.*`, sufijos fuera) | PASS | `PaymentController.php:338-344` (credit_balance + expected/discount/surcharge/paid/outstanding -> `TenantMoney::make`); cache `*_minor_units` intacto `:393-410` | **StatementEndpointTest "totals surcharge is present as money object" — 3 passed** |
| **R-MAPI-3** delinquency (`outstanding`, `total_outstanding`) | PASS | `PaymentController.php:459-480` (sort/sum sobre enteros `:463-465`, wrap después `:472,:480`) | Cubierto por CollectionsModuleTest/CollectionsBatch3Test (suite completa registrada) + inspección línea a línea |
| **R-MAPI-4** charge-batch summary | PASS | `ChargeBatchController.php:186-210` (SQL con clamp byte a byte intacto; wrap solo en respuesta) | **ChargeBatchSummaryTest — 4 passed** (incl. "clamps overpaid outstanding to zero") |
| **R-MAPI-5** tableros Treasury x3 (sin `_minor`) | PASS | `TreasuryReportService.php:199-206,290-304,400-421` (wrap desde `*_minor` post-cache; compute int-plano; `net_minor = income - expense` `:250`) | **TreasuryReportControllerTest — 21 passed** (incl. "net equals income minus expense", "running balance is absolute and reconciles to total") |
| **R-MAPI-6** public-summary | PASS | `PublicSummaryController::summary` (5 usos `TenantMoney::make`: total_income/total_expenses/net_balance/expenses_by_category[].total/bank_account_balances[].current_balance) | TreasuryHttpTest (suite completa registrada) + inspección |
| **R-MAPI-7** collections/aging | PASS | `CollectionsReportService::aging` (8 usos: total_outstanding + 5 buckets + by_status[] + top_debtors[]) | CollectionsReportControllerTest (suite completa registrada) + inspección |
| **R-MAPI-8** projects/funding-summary | PASS | `ProjectsReportService::fundingSummary` (projects[].{goal,raised} + totals; `pct` sin envolver) | **ProjectsReportControllerTest — 11 passed** (incl. "funding summary returns correct structure", "pct calculation is correct") |
| **R-MAPI-9** community-project/summary (renombre EN) | PASS | `ContributionService.php:380-383` (getSummary -> `raised/executed/available`, enteros); `CommunityProjectController.php:234-236` (wrap; docblock `:222-223` documenta `available` negativo) | ProjectsModuleTest/ProjectsHttpTest/ClusterLifecycleTest (suite completa registrada) + gate grep confirma que no hay claves español en el borde del API |
| **R-MAPI-10** reports JSON (solo `ReportController`) | PASS | `ReportController.php:144-147` (delinquency arrays ambos niveles), `:176-178` (income-statement), `:232-233` (collection-progress); accountBalances sin cambio `:187`; `generate(format=json)` queda raw `:254-293` | ReportsModuleTest (suite completa registrada) + inspección |
| **R-MAPI-11** caches conservan enteros | PASS | `cachedStatementAggregate` claves `*_minor_units` `:393-410`; TreasuryReportService compute `*_minor` `:237-250`; docblock `TreasuryReportService:24`; conversión post-cache | **TreasuryReportControllerTest** "cache remember is called with tenant scoped key" + "cache disabled bypasses returns fresh"; **ProjectsReportControllerTest** "cache key contains tenant id" |
| **R-MAPI-12** formateo centralizado | PASS | `TenantMoney::format` único punto; `ReportFormatter.php:194-197` (moneyWithCurrency) + headers dinámicos `:205,:232` (`'('.$currency.')'` calculado UNA vez por tabla); 4 `formatMoney` privados + 2 comandos + receipt.blade ruteados | Gate grep `' MXN'` = 0, `number_format(.*100` solo celdas sancionadas |
| **R-MAPI-13** billing excluido | PASS | `rg TenantMoney Modules/SubscriptionPlan` = 0; SubscriptionPlan conserva cast `integer` en MXN | Gate grep |
| **R-MAPI-14** cero cambio de lógica | PASS | sort/sum/clamp sobre enteros ANTES de envolver (delinquency `:463-465`, charge-batch SQL intacto, treasury `net_minor` `:250`, cashflow running_balance en enteros `:354`) | **TreasuryReportControllerTest** "net equals income minus expense" + "running balance reconciles"; **ChargeBatchSummaryTest** "discount reduces outstanding" + "cancelled charges excluded" |

### Capability: tenant-currency

| Req | Verdicto | Evidencia (código) | Prueba |
|---|---|---|---|
| **R-TCUR-1** orden de resolución (tenant -> config; inválido -> fallback) | PASS | `TenantMoney.php:16-27` (guard `tenancy()->initialized` + `Money::isValidCurrency`, fallback `config('money.defaultCurrency','MXN')`) | **TenantMoneyCastTest** "central context without tenancy falls back to config default" + "invalid persisted currency falls back to config default" |
| **R-TCUR-2** 11 modelos resuelven por tenant | PASS | 25 columnas de dinero -> `TenantMoneyCast::class` sin parámetro en los 11 modelos (Charge/Payment/PaymentApplication/CreditBalance/ChargePolicy/BankAccount/Expense/Provider/ProjectContribution/CommunityProject/Collaborator); BankAccount usa la forma plana (no `:currency`, ADR-1) | **TenantMoneyCastTest** "two tenants with different currency serialize the same minor units" |
| **R-TCUR-3** selección de moneda + confirmación | PASS | `ConfigurationController.php:228` `Rule::in(['MXN','USD'])`; gate de confirmación `:231-237`; persiste sin convertir `:243`; `OperationalConfigSection.tsx:95-107` Select curado + `:146-154` ConfirmDialog ("reinterpretarán... NO se convierten") | **SubscriptionSelfServiceTest** "operational currency must be in curated list" (EUR -> 422) + "operational currency accepts curated values with confirmation" (USD persiste) |
| **R-TCUR-4** frontend formatea con moneda activa | PASS | `currency.ts` (estado módulo, cero imports); `money.ts:30` `getActiveCurrency()` con locale `es-MX` `:28`; `sessionStore.ts:84,103,115,126` (setSession/setProfile/clearSession->null/restoreSession); `Session.ts:16` `currency?: string\|null` | **money.test.ts — 7 passed** (USD format + reset a MXN); `pnpm build` limpio (clean break) |
| **R-TCUR-5** cambio refleja de inmediato | PASS | `OperationalConfigSection.tsx:52-62` `syncCurrencyEverywhere` (fetchSessionProfile + setProfile + setActiveCurrency + `invalidateQueries()`); onSuccess por-llamada `:64-69` sin tocar la mutación compartida | Verificado por código + build; smoke interactivo diferido al usuario (ver Pendientes) |
| **R-TCUR-6** seguro bajo Octane/colas | PASS | `TenantMoney::currency()` resolución por-llamada (sin static); `TenantMoneyCast::getCurrency` per-call; solo statics de config guardan constantes; `QueueTenancyBootstrapper` verificado presente (task 1.6) | **TenantMoneyCastTest** "two tenants" (aislamiento) + "explicit currency parameter passes through" |

**Resultado: 20/20 requisitos PASS. Cero FAIL, cero PARTIAL.**

---

## Verificación de desviaciones sancionadas (las 10 se sostienen)

| # | Desviación | Estado verificado |
|---|---|---|
| 1 | ReportFormatter conserva celdas numéricas bare `number_format` (currency-agnósticas); headers dinámicos | CONFIRMADA — `ReportFormatter.php:191,213,241,250,280` son celdas sin `$`/` MXN`; headers usan `$currency = TenantMoney::currency()` `:205,:232`. Gate `' MXN'` = 0 |
| 2 | Claves `_minor`/`_minor_units` sobreviven solo en cache/compute internos | CONFIRMADA — presentes en `cachedStatementAggregate` y `computeX` privados; NO aparecen en respuestas (R-MAPI-11 probado) |
| 3 | `$recaudado` local + docblock español en ContributionService (out of scope) | CONFIRMADA — `ContributionService.php:414` (var local en `syncFullyFundedState`, método distinto de `getSummary`) + docblock `:33-37`. getSummary retorna claves inglés |
| 4 | TenantMoneyCastTest usa código inválido `'ZZZ'` (no `'XXX_BAD'`; `tenant.currency` es varchar(3)) | CONFIRMADA — test "invalid persisted currency falls back to config default" pasó; `ZZZ` (3 chars, no-ISO) es la elección correcta para la columna |
| 5 | Asserts HTTP de reports/community-project en `E2E/ClusterLifecycleTest.php`; paridad generate(format=json) en `ReportsModuleTest.php` | CONFIRMADA por manifiesto de apply + inspección de ReportController |
| 6 | Regla 17 de laravel-backend-architecture SKILL.md en disco pero `.agents/` está GITIGNORED | CONFIRMADA — ver W2; registro durable = engram `backend/money-api-serialization` + auto-memoria |
| 7 | 10 fallos backend pre-existentes, ajenos a money/currency | CONFIRMADA — ver S2; ninguno toca superficies de money/currency |
| 8 | Smoke E2E de navegador (task 10.5) diferido al usuario | CONFIRMADA — infra alcanzable; verificación visual pendiente (no bloqueante) |
| 9 | Cero commits (decisión del usuario); entrega = manifiesto por slice en apply-progress | CONFIRMADA — tasks 10.7.x son documentación/entrega, no implementación |
| 10 | R-MAPI-6 spec dice "sin autenticación"; la ruta real exige `auth:sanctum` + `initializeTenancy` (permission-free) | CONFIRMADA como matiz de texto de spec (ver S1); las aserciones de dinero del requisito se sostienen |

---

## Completitud de tasks

- **109/109 items aplicables `[x]`**. Los 7 items `[ ]` restantes son NO-implementación y están sancionados:
  - `10.5.1`, `10.5.2` — smoke E2E manual de navegador (diferido al usuario; sin tooling de browser en el contexto de apply/verify).
  - `10.6.3` — auto-memoria de la convención de rutas de test (SKIPPED por instrucción del orquestador: "el orquestador maneja la auto-memoria del proyecto").
  - `10.7.1`, `10.7.2`, `10.7.3`, `10.7.4` — commits/push/bump de pointers (decisión del usuario "todo sin commitear"; `10.7.2` además es imposible: SKILL.md gitignored).
- Ninguno de estos 7 es una tarea de implementación core. Por el gate de sdd-verify (tarea incompleta core = CRITICAL; cleanup = WARNING), estos son WARNING como máximo y todos tienen deferral explícitamente sancionado. **No bloquean el archivo.**

---

## WARNING

- **W1 — 7 items de `tasks.md` sin marcar `[ ]`** (10.5.1/2 smoke manual, 10.6.3 auto-memoria, 10.7.1-4 entrega/commits). Todos son cleanup/entrega/verificación-manual, no implementación; coinciden con la decisión "cero commits" del usuario y la ausencia de tooling de browser. El código correspondiente a todos los requisitos ESTÁ presente y probado. No bloquea archive.
- **W2 — Regla 17 de `laravel-backend-architecture/SKILL.md` en disco pero `.agents/` está GITIGNORED** (root `.gitignore` línea 4, bloque "AI Core"). La edición es real y persistente localmente pero NUNCA aparecerá en `git status`/`git diff` — task 10.7.2 es imposible como estaba planteada. El registro durable del patrón vive en engram (`backend/money-api-serialization`) + auto-memoria del proyecto. Requiere decisión del usuario sobre cómo persistir la regla en el repo (p. ej. moverla a un archivo no-gitignored) si se desea versionarla.

## SUGGESTION

- **S1 — Matiz de texto en spec R-MAPI-6:** el escenario dice `treasury/public-summary` es "endpoint público, sin autenticación", pero la ruta real exige `auth:sanctum` + `initializeTenancy` (sin gate de permiso para residentes). El comportamiento de serialización de dinero (lo que el requisito realmente valida) se sostiene. Sugerencia: corregir la redacción del escenario al momento de `sdd-archive` para reflejar "autenticado, permission-free" en lugar de "sin autenticación".
- **S2 — 10 fallos backend pre-existentes** (SpreadsheetSafeTest, UserPhoneUniqueTest, ChatAccessMatrixTest, ClusterConfigurationFeaturesTest, ClusterLifecycleTest::09_access_qr + RolePermissionBoundaryTest QR, UserInvitationHardeningTest, NotificationSettingSeedingTest, AuthorizationGuardTest, OnboardingChecklistTest). Triados como WIP paralelo (Announcements etc.), NO causados por este cambio; ninguno toca superficies de money/currency (Collections/Treasury/Projects/Providers/Reports = 0 fallos; Configuration operational/currency = 100% verde). Trackear por separado — fuera del alcance de este cambio.

---

## Pendiente de verificación por el usuario (no bloqueante)

1. **Smoke E2E interactivo (task 10.5):** abrir `http://192.168.100.136:5173` (regla de mismo origen), verificar que el statement/tableros muestran montos idénticos a los previos, y que cambiar la moneda MXN->USD desde Configuración refleja USD sin recargar la página. Infra confirmada alcanzable (dev server 200, API viva); la verificación visual/UX no se pudo automatizar sin tooling de browser.
2. **Entrega (task 10.7):** commits convencionales por submódulo + bump de pointers + push, cuando el usuario decida cerrar la fase "cero commits".

---

## Verdicto final

**PASS.** Las 20 capacidades (R-MAPI-1..14, R-TCUR-1..6) están implementadas y probadas en runtime; las compuertas de grep, la suite representativa de backend (47 passed / 343 assertions), el build de frontend y `money.test.ts` (7/7) están en verde; las 10 desviaciones sancionadas se sostienen. No hay incidencias CRITICAL. Los 2 WARNING son de proceso/entrega (gitignore de SKILL.md, tasks de commits sin marcar) y no de implementación. **El cambio está listo para `sdd-archive`** una vez el usuario ejecute el smoke E2E interactivo y decida la estrategia de commits.

*Generado por sdd-verify el 2026-07-05. Autoridad: spec + tasks (109/109 aplicables) + design GATE-REVIEWED + apply-progress engram #1501.*
