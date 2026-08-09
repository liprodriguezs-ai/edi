# SDD Proposal — Moneda por tenant y serialización de dinero en el API

- **Change:** `tenant-currency-money-api`
- **Project:** `residia-monorepo` (Kivo7)
- **Phase:** propose
- **Artifact store:** hybrid (este archivo + engram `sdd/tenant-currency-money-api/proposal`)
- **Date:** 2026-07-04
- **Source of truth:** formaliza la síntesis de exploración aprobada por el usuario (engram `sdd/tenant-currency-money-api/explore`, con evidencia file:line y tablas de contrato por endpoint). Las decisiones de alcance ya están cerradas — esta propuesta no las re-abre.

---

## 1. Intención

### Problema
El dinero sale del API en dos formas. Los modelos hidratados serializan Money como `{amount, currency, formatted}` (`MoneyIntegerCast` de cknow + `ArrayMoneySerializer`), pero **8 grupos de endpoints** emiten enteros crudos en minor units porque sus agregados (`selectRaw` / `sum()` / `getAttributes()` / `withSum`) se saltan los casts de Eloquent. `GET /unit/{id}/statement` mezcla AMBAS formas en el mismo payload. Los nombres son inconsistentes (`_minor_units`, `_minor`, sin sufijo) e incluso hay claves en español (`recaudado/ejecutado/disponible` — viola la regla HARD de identificadores en inglés).

Además, la moneda está hardcodeada de punta a punta: los 11 modelos de dinero del dominio declaran `MoneyIntegerCast::class.':MXN'`, 7+ lugares del backend copian `'$'.number_format($raw/100,2).' MXN'`, y los helpers del frontend fijan `es-MX`/`MXN` — a pesar de que `tenant.currency` existe, `PUT /config/operational` ya lo persiste y el login ya lo envía en el JSON de sesión (la UI de configuración lo muestra read-only y el tipo `SessionTenant` del FE lo omite). `config/money.php` no está publicado (locale vendor `en`, moneda default USD, enmascarados por los parámetros `:MXN`).

### Por qué ahora
El bug se reportó en statement, pero el barrido de exploración demostró que es sistémico: cada endpoint de agregación nuevo copia el patrón roto. La selección de moneda por tenant ya está a medio construir (columna + persistencia listas, UI muerta); terminarla ahora evita otra media-implementación. Existe drift real FE↔BE hoy: el backend regresa `totals.surcharge_minor_units` y el `StatementResponse` del FE lo omite.

### Cómo se ve el éxito
- El dinero cruza la frontera del API **solo** como objeto Money serializado `{amount, currency, formatted}`; los enteros minor-units quedan confinados a la capa de servicio/cache.
- La moneda se resuelve desde `tenant()->currency` de punta a punta (casts, agregados, notificaciones/PDF, frontend); el admin puede elegir MXN/USD en Configuración con efecto inmediato.
- La regla queda institucionalizada (regla 17 del skill laravel-backend-architecture + patrón en engram) para que no se reintroduzca.

---

## 2. Alcance

### Dentro del alcance
1. **Publicar `backend/config/money.php`**: `locale => es_MX`, `defaultCurrency => MXN` (fallback para contexto central). Valores constantes → Octane-safe.
2. **`QCH\Core\Casts\TenantMoneyCast`** (nuevo): extiende `Cknow\Money\Casts\MoneyIntegerCast`, override de `getCurrency()` → moneda del tenant con resolución por llamada; swap en los 11 modelos de dinero del dominio (Collections ×5, Treasury ×2, Providers ×1, Projects ×2, Collaborator ×1). Borrar el cast legacy muerto `backend/app/Casts/MoneyCast.php`.
3. **`QCH\Core\Support\TenantMoney`** (nuevo): `currency(): string` / `make(int): Money` / `format(int): string` — el único punto de conversión para agregados y strings de notificación/PDF.
4. **Estandarización clean-break de contratos** en los 8 grupos de endpoints: los valores de dinero se vuelven objetos Money, se eliminan los sufijos `_minor`/`_minor_units`, y se renombra `recaudado/ejecutado/disponible` → `raised/executed/available`. Counts, porcentajes, labels y fechas no cambian.

   | Grupo de endpoints | Cambio de contrato (**M** = `{amount, currency, formatted}`) |
   |---|---|
   | `unit/{id}/statement` — `PaymentController::statement` | `credit_balance_minor_units` → `credit_balance: M`; `totals.{expected,discount,surcharge,paid,outstanding}_minor_units` → `totals.{…}: M` |
   | `delinquency` — `PaymentController::delinquency` | `outstanding_minor_units` → `outstanding: M`; `total_outstanding_minor_units` → `total_outstanding: M` |
   | `charge-batch/{id}/summary` — `ChargeBatchController` | claves conservan nombre; valores → M (SQL con clamp `SUM(CASE WHEN…)` intacto) |
   | Tableros de Treasury — `TreasuryReportService` (3 endpoints) | se eliminan sufijos `*_minor`: `series[].*`, `accounts[].current_balance`, `totals.*`, `by_category[].amount`, `by_provider[].amount`, `monthly[].amount` → M |
   | `treasury/public-summary` — `PublicSummaryController` | claves ya limpias; valores → M |
   | `collections/aging` — `CollectionsReportService::aging` | `totals.total_outstanding`, `buckets.*`, `by_status[].amount`, `top_debtors[].outstanding` → M (se elimina `_minor`) |
   | `projects/funding-summary` — `ProjectsReportService` | `projects[].{goal,raised}`, `totals.{goal,raised}` → M |
   | `community-project/{id}/summary` — `ContributionService::getSummary` | renombre a inglés `raised/executed/available` + valores → M (`available` puede ser negativo — Money lo soporta) |
   | Reports JSON — solo `ReportController` | `reports/delinquency`, `reports/income-statement`, `reports/collection-progress`: totales → M; `reports/account-balances` sin cambio (ya hidratado); `generate(format=json)` queda raw (paridad con exports) |

5. **Centralizar formateo backend**: `ReportFormatter::moneyMxn()` → `moneyWithCurrency()` vía `TenantMoney::format()`; rutear los ~12 sitios inline y los headers `(MXN)` hardcodeados; reemplazar los 4 `formatMoney()` privados idénticos (PaymentService, ExpenseService, SendMonthlyFinancialSummaryCommand, ContributionService) + `SendChargeRemindersCommand` + `receipt.blade.php`.
6. **Infraestructura frontend**: `shared/utils/currency.ts` nuevo (`set/getActiveCurrency`); `formatMinorUnits` y `displayFormatCurrency` usan la moneda activa (locale queda `es-MX`); `SessionTenant.currency` + sincronización en `sessionStore`; migración de todos los consumidores (statement/delinquency — corrige el drift de `surcharge` —, charge batch, 5 dashboards, public summary, contribution summary, previews de reports) con tipos `number` → `IMoney`.
7. **UI de configuración + validación**: `OperationalConfigSection` habilita un `Select` de moneda (lista curada `MXN`, `USD`) con diálogo de confirmación (los montos se **reinterpretan, no se convierten**) y `confirmed: true`; backend valida `Rule::in(['MXN','USD'])`; al éxito se actualiza la sesión + `setActiveCurrency()` + invalidación de queries.
8. **Tests**: nuevo `tests/Feature/Core/TenantMoneyCastTest.php` (tenant MXN vs USD, fallback central, passthrough con parámetro explícito); actualización de asserts de shape en ~10 suites backend; `money.test.ts` + fixtures de los 5 dashboards en FE.
9. **Grabar la regla**: regla 17 en `.agents/skills/laravel-backend-architecture/SKILL.md` + engram (`backend/money-api-serialization`) + auto-memoria del proyecto.

### Fuera del alcance (deliberado)
- **Billing de plataforma queda en enteros/MXN**: el payload de `TenantBillingController` es uniformemente cast `'integer'` y el zod del FE espera `z.number()`; el pricing de Stripe de Kivo7 es MXN. Frontera ya explícita — ningún modelo de SubscriptionPlan usa MoneyIntegerCast.
- **`ReportService`/`ReportRegistry`/`ReportFormatter` siguen con enteros crudos internamente** (alimentan el pipeline PDF/CSV/XLSX); la conversión es solo en los bordes JSON de `ReportController`.
- **Cero migraciones de BD** (`tenant.currency` ya existe) y **cero cambios de lógica de negocio** (fórmula de outstanding, FIFO, FSM y clamps SQL byte a byte iguales).
- Monedas por cuenta bancaria (`bank_account.currency` queda como dato durmiente), conversión histórica de montos al cambiar de moneda, locale por tenant.
- `core/src/Http/Controllers/ReportExport.php` (dominio CRM legacy) y `OwnershipShareWithinLimit` (porcentaje, no dinero).

---

## 3. Capacidades (contrato con sdd-spec)

> `openspec/specs/` no existe aún en el repo; ambas capacidades son nuevas.

### Nuevas
- `money-api-serialization`: el dinero cruza la frontera del API únicamente como objeto Money serializado `{amount, currency, formatted}`; los agregados convierten en el borde de la respuesta con `TenantMoney::make()`; los caches persisten enteros planos; los strings de notificación/PDF usan `TenantMoney::format()`. Cubre los contratos estandarizados de los 8 grupos de endpoints y la excepción deliberada del billing de plataforma.
- `tenant-currency`: la moneda se resuelve por tenant (`tenant.currency`, fallback `config('money.defaultCurrency')`), es seleccionable (MXN/USD) en la configuración operacional con confirmación explícita, y el frontend formatea con la moneda activa de la sesión.

### Modificadas
- Ninguna (no hay specs formales previas; los deltas de contrato quedan cubiertos por `money-api-serialization`).

---

## 4. Enfoque (altura intención/alcance — la implementación pertenece a sdd-design)

> **Patrón a institucionalizar:** el dinero cruza la frontera del API SOLO como objeto Money serializado; los enteros minor-units viven en la capa de servicio/cache. La moneda sale de `tenant()->currency`, nunca hardcodeada.

Tres piezas pequeñas — se reutiliza cknow, SIN capa nueva de Resources:
1. `config/money.php` publicado (constantes → Octane-safe).
2. `TenantMoneyCast`: seam de subclase verificado — `MoneyCast::$currency` es `protected` y tanto `get()` como `set()` pasan por `protected getCurrency(array $attributes)`, así que un solo override cubre lectura y escritura (`vendor/cknow/laravel-money/src/Casts/MoneyCast.php:52-116`). Resolución por llamada del estado de tenancy del request = Octane-safe por construcción; **NUNCA** `Money::setDefaultCurrency()` por tenant.
3. `TenantMoney` como único punto de conversión: `make()` para bordes de respuesta, `format()` para strings.

Decisiones clave ya cerradas por el usuario:
- **`BankAccount` usa `TenantMoneyCast`, NO la forma `:currency` por atributo** — las sumas entre cuentas asumen una sola moneda por tenant, y la forma por atributo del vendor escribe la columna `currency` en `set()` como efecto colateral.
- **Regla de cache**: los caches siguen guardando arrays de enteros planos con sus claves internas actuales (deploy-safe con entradas viejas; un cambio de moneda surte efecto en la siguiente lectura); la conversión a Money ocurre al armar la respuesta, DESPUÉS de `cachedReport()` / `Cache::remember`.
- **Clean break de contratos permitido**: los submódulos FE+BE se despliegan juntos; los renombres de tipos convierten a los lectores viejos en errores de compilación (`tsc` como red de seguridad).
- **Entrega** (ya resuelta): una sola entrega con `size:exception`, slices verticales BE+FE+tests en orden — mecanismo core → statement/delinquency → charge-batch → treasury+public-summary → aging/funding/contribution/reports → centralización de formateo → infra FE → UI de config → docs/memoria. Sin worktree (submódulos); commits por submódulo + bump del pointer raíz.

---

## 5. Áreas afectadas

| Área | Impacto | Descripción |
|---|---|---|
| `backend/config/money.php` | Nuevo | locale `es_MX`, `defaultCurrency` `MXN` |
| `backend/core/src/Casts/TenantMoneyCast.php` | Nuevo | cast tenant-aware (extiende `MoneyIntegerCast`) |
| `backend/core/src/Support/TenantMoney.php` | Nuevo | `currency()` / `make()` / `format()` |
| `backend/app/Casts/MoneyCast.php` | Eliminado | cast legacy muerto (cero referencias) |
| `Modules/Collections` | Modificado | 5 modelos → cast; statement/delinquency/batch-summary → contrato M; aging; `PaymentService`, `SendChargeRemindersCommand`, `receipt.blade.php` → `TenantMoney::format()` |
| `Modules/Treasury` | Modificado | `BankAccount`/`Expense` → cast; `TreasuryReportService` (3 tableros), `PublicSummaryController`; `ExpenseService`, `SendMonthlyFinancialSummaryCommand` |
| `Modules/Projects` | Modificado | 2 modelos → cast; `ContributionService::getSummary` (renombre EN), `ProjectsReportService`, `CommunityProjectController::summary` |
| `Modules/Providers` / `Modules/Collaborator` | Modificado | `Provider` / `Collaborator.base_salary` → cast |
| `Modules/Reports` | Modificado | `ReportController` (3 bordes JSON) → M; `ReportFormatter` centralizado |
| `Modules/Configuration` | Modificado | validación `currency` → `Rule::in(['MXN','USD'])` |
| `frontend/src/shared` | Nuevo/Mod | `utils/currency.ts` (nuevo); `money.ts`, `currencyHelper.ts`, `auth/Session.ts`, `sessionStore.ts` |
| `frontend/src/modules` | Modificado | payment, chargeBatch, 5 dashboards, expense/treasurerPanel, communityProject, reports, configuration (`OperationalConfigSection` → Select) |
| Tests BE/FE | Nuevo/Mod | `TenantMoneyCastTest` (nuevo); asserts de shape en ~10 suites BE; `money.test.ts` + fixtures FE |
| `.agents/skills/laravel-backend-architecture/SKILL.md` | Modificado | regla 17 (patrón de dinero en API) |

---

## 6. Riesgos y mitigaciones

| Riesgo | Prob. | Mitigación |
|---|---|---|
| Estado estático con Octane (moneda de un tenant contamina a otro request) | Baja | resolución por llamada desde el estado de tenancy; los únicos statics guardan constantes de config; nunca `Money::setDefaultCurrency()`; test tenant A (MXN) vs tenant B (USD) |
| Payloads cacheados con shape viejo tras el deploy | Baja | los caches guardan enteros planos con claves internas SIN cambio → deploy-safe en ambos sentidos; conversión al armar la respuesta |
| Clean break rompe consumidores no migrados (FE o BE) | Media | submódulos FE+BE se despliegan juntos; tipos `number`→`IMoney` hacen que `tsc` falle en cada lector olvidado; `moneyToMinorUnits` ya tolera objeto\|int\|string (migración en cualquier orden); compuertas de grep |
| Jobs en cola / crons resuelven moneda sin tenant inicializado | Baja | `QueueTenancyBootstrapper` re-inicializa tenancy en jobs; los crons por tenant corren con tenancy inicializada; contexto central cae a `config('money.defaultCurrency')` |
| Churn de tests (muchos asserts de shape) | Media | assertear `amount` + `currency` por path, NUNCA el `formatted` textual (frágil por locale); barrido `rg "_minor|minor_units|recaudado|' MXN'"` sobre tests hasta 0 hits pendientes |
| Cambio de moneda malinterpretado como conversión de montos | Media | diálogo de confirmación en la UI (los montos se reinterpretan, no se convierten); lista curada MXN/USD; `confirmed: true` requerido; validación backend `Rule::in` |

---

## 7. Plan de rollback

Cero migraciones → revert de código puro: revertir los commits de los submódulos `backend` y `frontend` y hacer bump del pointer en la raíz. Los caches guardan enteros planos bajo claves sin cambio, así que el código viejo los lee sin flush. El valor `tenant.currency` persistido queda inerte bajo rollback (los casts vuelven a `:MXN`); los montos son enteros → sin daño de datos.

---

## 8. Criterios de éxito

- [ ] Compuertas de grep: `rg "MoneyIntegerCast::class.':MXN'" backend/Modules` → 0 hits; `rg "number_format\(.*100" backend/Modules` → 0 hits de dinero; `_minor`/`_minor_units` solo sobreviven como claves internas de cache/cómputo.
- [ ] Todos los valores de dinero de los 8 grupos serializan `{amount, currency, formatted}`; `recaudado/ejecutado/disponible` ya no existen en el API.
- [ ] Suites backend verdes en php del HOST (Postgres `127.0.0.1:5432`, UN solo proceso de test) + Pint limpio; `pnpm build` (tsc) + `pnpm test` verdes.
- [ ] Smoke E2E (`http://192.168.100.136:5173`, regla de mismo origen): statement muestra montos idénticos a antes; KPIs de charge-batch sin cambio; cambiar la moneda del tenant a USD en Configuración se refleja de inmediato en statement/dashboards/previews de reportes.
- [ ] Cero cambios de lógica de negocio: fórmula de outstanding, FIFO, FSM y clamps SQL byte a byte iguales.
- [ ] Regla grabada: regla 17 en el skill laravel-backend-architecture + engram `backend/money-api-serialization` + auto-memoria.

---

## 9. Próximas fases

- **sdd-spec** y **sdd-design** pueden correr en paralelo (ambos dependen solo de esta propuesta y de la síntesis de exploración).
- sdd-design formaliza las tablas ANTES→DESPUÉS por endpoint del plan aprobado (no las re-decide) y cierra: puntos exactos de mapeo post-cache por service, firma final de `TenantMoney`, estrategia de asserts por suite, y el flujo de confirmación de la UI de configuración.
- Post-apply: `judgment-day` fuertemente recomendado (trigger rules del proyecto) antes de `sdd-verify`.
