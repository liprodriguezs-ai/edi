# SDD Tasks — Moneda por tenant y serialización de dinero en el API

- **Change:** `tenant-currency-money-api`
- **Project:** `residia-monorepo` (Kivo7)
- **Phase:** tasks
- **Artifact store:** hybrid (este archivo + engram `sdd/tenant-currency-money-api/tasks`)
- **Date:** 2026-07-04
- **Delivery:** entrega única, `size:exception` ya aceptado por el usuario (ver §"Review Workload Forecast" al final)
- **Depends on:** spec (engram #1497, `openspec/changes/tenant-currency-money-api/spec.md`), design GATE-REVIEWED (engram #1498, `openspec/changes/tenant-currency-money-api/design.md`)
- **Guardrail transversal:** este cambio es EXCLUSIVAMENTE de serialización y resolución de moneda (R-MAPI-14). Ninguna tarea de este documento debe alterar la fórmula de `outstanding`, el algoritmo FIFO, las transiciones de la FSM de cargos, ni ninguna cláusula SQL de agregación (incluidos los clamps `SUM(CASE WHEN...)`). Si una tarea parece requerir ese tipo de cambio, DETENERSE — es una señal de que algo se entendió mal.
- **Convención de rutas de test verificada en esta fase:** los tests Feature de Collections están MEZCLADOS entre `backend/Modules/Collections/tests/Feature/` (módulo-local, p. ej. `StatementEndpointTest.php`, `ChargeBatchSummaryTest.php`) y `backend/tests/Feature/Collections/` (central, p. ej. `CollectionsReportControllerTest.php`, `CollectionsModuleTest.php`, `CollectionsBatch3Test.php`). Treasury, Projects y Reports usan la ruta CENTRAL exclusivamente: `backend/tests/Feature/{Treasury,Projects,Reports}/`. Cada tarea de este documento usa la ruta exacta ya verificada — no la abrevies al ejecutar.
- **Runner backend:** SIEMPRE php del HOST (`php artisan test <path>`, NUNCA `sail`), Postgres `127.0.0.1:5432`, UN SOLO proceso de test a la vez sobre la DB `testing` compartida.
- **Runner frontend:** SIEMPRE desde `frontend/` (`pnpm test`, `pnpm build`).

---

## Resumen de slices

| # | Slice | Límite de commit (submódulo) | Paralelizable después de |
|---|---|---|---|
| 1 | Mecanismo core (cast + helper + swap de 11 modelos) | `feat(core): add tenant-aware money cast and serialization support` (backend) | Arranca de inmediato |
| 2 | Statement + delinquency | `feat(collections): serialize statement and delinquency money fields as Money objects` (backend) | Después de 1 |
| 3 | Charge-batch summary | `feat(collections): serialize charge-batch summary money fields as Money objects` (backend) | Después de 1 (paralelo con 2) |
| 4 | Tableros de Treasury + public-summary | `feat(treasury): serialize dashboard and public-summary money fields as Money objects` (backend) | Después de 1 (paralelo con 2, 3) |
| 5 | Aging + funding + contribution + reports JSON | `feat: serialize aging, funding, contribution and reports JSON money fields as Money objects` (backend) | Después de 1 (paralelo con 2, 3, 4) |
| 6 | Grupo C — centralización de formateo | `refactor: centralize money formatting through TenantMoney::format()` (backend) | Después de 1 (puede correr en paralelo con 2-5; toca sitios distintos) |
| 7 | Infra frontend (moneda activa + sesión) | `feat(shared): add active-currency module and wire session sync` (frontend) | Arranca de inmediato (independiente del backend) |
| 8 | Consumidores frontend | `feat: migrate money consumers to IMoney and active-currency formatting` (frontend) | Después de 7. Tolera aterrizar en cualquier orden relativo a 2-6 (`moneyToMinorUnits` acepta objeto\|int\|string) |
| 9 | UI de configuración + validación de moneda | `feat(configuration): enable tenant currency selection with confirmation` (backend + frontend, 2 commits) | Después de 1 (backend) y 7 (frontend) |
| 10 | Compuertas de completitud + documentación + entrega | `docs: record tenant-aware money API pattern` (root) + bump de pointers | Después de 1-9 |

---

## Slice 1 — Mecanismo core

Satisface: R-TCUR-1, R-TCUR-2, R-TCUR-6

### 1.1 `backend/config/money.php`

- [x] **1.1.1** Publicar la config del paquete: `php artisan vendor:publish --provider="Cknow\Money\MoneyServiceProvider" --tag=config` (desde `backend/`). Verificar que crea `backend/config/money.php`.
- [x] **1.1.2** Editar SOLO `locale` → `'es_MX'` y `defaultCurrency` → `'MXN'`. Dejar `defaultFormatter` y `defaultSerializer` en `null` (carga el `ArrayMoneySerializer` del vendor — produce `{amount, currency, formatted}`; NO tocar). Conservar el branch `is_dir(__DIR__.'/../vendor')` de `isoCurrenciesPath` VERBATIM.
  - Satisface: R-TCUR-1.

Verificación: `php artisan tinker --execute="dd(config('money.locale'), config('money.defaultCurrency'));"` (desde `backend/`) → debe imprimir `es_MX` / `MXN`.

### 1.2 `backend/core/src/Casts/TenantMoneyCast.php` (nuevo)

- [x] **1.2.1** Crear el directorio `backend/core/src/Casts/` (no existe aún — confirmado) y el archivo `TenantMoneyCast.php`, namespace `QCH\Core\Casts`, extendiendo `Cknow\Money\Casts\MoneyIntegerCast`.
- [x] **1.2.2** Override de `protected function getCurrency(array $attributes)` (SIN return type — espejo exacto de la firma del vendor en `vendor/cknow/laravel-money/src/Casts/MoneyCast.php:98-116`): si `$this->currency !== null`, delega a `parent::getCurrency($attributes)` (passthrough del parámetro explícito, p. ej. `TenantMoneyCast::class.':EUR'`); si no, retorna `Money::parseCurrency(TenantMoney::currency())`.
  - Satisface: R-TCUR-1, R-TCUR-2, R-TCUR-6 (resolución por llamada, sin static por tenant).

### 1.3 `backend/core/src/Support/TenantMoney.php` (nuevo)

- [x] **1.3.1** Crear el directorio `backend/core/src/Support/` (no existe aún — confirmado) y el archivo `TenantMoney.php`, namespace `QCH\Core\Support`.
- [x] **1.3.2** Método estático `currency(): string` — si `tenancy()->initialized` y `tenant()->currency` es un código válido (`Money::isValidCurrency()`), lo retorna; si no, retorna `config('money.defaultCurrency', 'MXN')`.
- [x] **1.3.3** Método estático `make(int $minorUnits): Money` — `return money($minorUnits, self::currency());`.
- [x] **1.3.4** Método estático `format(int $minorUnits): string` — `return self::make($minorUnits)->format();` (reemplaza todo patrón `'$'.number_format($raw/100,2).' MXN'` — ver Slice 6).
  - Satisface: R-TCUR-1, R-TCUR-6.

### 1.4 Swap de casts en los 11 modelos de dinero + borrado del cast legacy

- [x] **1.4.1** `backend/Modules/Collections/app/Models/Charge.php` (líneas 51-55) — `MoneyIntegerCast::class.':MXN'` → `TenantMoneyCast::class` (sin parámetro) para cada columna de dinero. Agregar `use QCH\Core\Casts\TenantMoneyCast;`.
- [x] **1.4.2** `backend/Modules/Collections/app/Models/Payment.php` (línea 47) — mismo swap.
- [x] **1.4.3** `backend/Modules/Collections/app/Models/PaymentApplication.php` (línea 41) — mismo swap.
- [x] **1.4.4** `backend/Modules/Collections/app/Models/CreditBalance.php` (línea 31) — mismo swap.
- [x] **1.4.5** `backend/Modules/Collections/app/Models/ChargePolicy.php` (líneas 56-60) — mismo swap.
- [x] **1.4.6** `backend/Modules/Treasury/app/Models/BankAccount.php` (líneas 43-44) — mismo swap. NO usar la forma `:currency` por atributo (ADR-1 del design: las sumas entre cuentas asumen una sola moneda por tenant; la forma por atributo del vendor escribe la columna `currency` como efecto colateral en `set()`).
- [x] **1.4.7** `backend/Modules/Treasury/app/Models/Expense.php` (línea 56) — mismo swap.
- [x] **1.4.8** `backend/Modules/Providers/app/Models/Provider.php` (línea 50) — mismo swap.
- [x] **1.4.9** `backend/Modules/Projects/app/Models/ProjectContribution.php` (líneas 34-35) — mismo swap.
- [x] **1.4.10** `backend/Modules/Projects/app/Models/CommunityProject.php` (líneas 53-57) — mismo swap.
- [x] **1.4.11** `backend/Modules/Collaborator/app/Models/Collaborator.php` (línea 114, campo `base_salary`) — mismo swap.
- [x] **1.4.12** Borrar `backend/app/Casts/MoneyCast.php` (legacy, confirmado sin referencias vivas — verificar con `rg -n "App\\\\Casts\\\\MoneyCast" backend` antes de borrar, debe dar 0 hits fuera del propio archivo).
  - Satisface: R-TCUR-2.

Verificación: `rg -n "MoneyIntegerCast::class.':MXN'" backend/Modules` (desde la raíz del repo) → 0 hits.

### 1.5 `backend/tests/Feature/Core/TenantMoneyCastTest.php` (nuevo)

- [x] **1.5.1** Crear el archivo (directorio `backend/tests/Feature/Core/` ya existe — confirmado, contiene `OrionValidationWiringTest.php`, `ModelCachingIsolationTest.php`).
- [x] **1.5.2** Caso "aislamiento por tenant": crear tenant A (`currency=MXN`) y tenant B (`currency=USD`), cada uno con un registro de dinero (p. ej. un `Charge`) con el MISMO entero en minor units. Inicializar la tenancy de cada uno y serializar → `currency` difiere (`MXN` vs `USD`), `amount` es idéntico.
- [x] **1.5.3** Caso "fallback central": `tenancy()->end()` → serializar el mismo modelo (o instanciar sin tenant) → `currency` es `MXN` (de `config('money.defaultCurrency')`).
- [x] **1.5.4** Caso "passthrough explícito": instanciar directamente `new TenantMoneyCast('USD')` y llamar `get()` con la tenancy activa en MXN → retorna Money en USD (semántica del vendor preservada, no la de `TenantMoney::currency()`).
- [x] **1.5.5** Caso "moneda inválida persistida": tenant con `currency='XXX_BAD'` → fallback a MXN (guard `isValidCurrency`), sin excepción.
  - Satisface: R-TCUR-1, R-TCUR-2, R-TCUR-6.

Verificación: `php artisan test tests/Feature/Core/TenantMoneyCastTest.php` (desde `backend/`) — 4 tests en verde.

### 1.6 Verificación de edge case Octane/colas (sin código nuevo)

- [x] **1.6.1** Confirmar (SIN modificar) que `QueueTenancyBootstrapper` reinicializa tenancy en jobs encolados — revisar `backend/config/tenancy.php` (bootstrappers registrados). El mecanismo ya existe; esta tarea es de verificación, no de implementación.
  - Satisface: R-TCUR-6.

---

## Slice 2 — Statement + delinquency

Satisface: R-MAPI-2, R-MAPI-3, R-MAPI-11, R-MAPI-14

Archivo: `backend/Modules/Collections/app/Http/Controllers/PaymentController.php`

### 2.1 `statement` — mapeo de respuesta

- [x] **2.1.1** En el método `statement` (líneas 304-340), dejar `cachedStatementAggregate()` (líneas 354-408) INTACTO — el shape del cache (claves `*_minor_units`, líneas 384-393) y el `Cache::remember` (líneas 403-407) no cambian.
- [x] **2.1.2** Editar el literal de respuesta (líneas 334-339): `credit_balance_minor_units` → `credit_balance: TenantMoney::make(...)`; `totals.{expected,discount,surcharge,paid,outstanding}_minor_units` → `totals.{expected,discount,surcharge,paid,outstanding}: TenantMoney::make(...)`. El array `charges[]` no cambia (ya serializa Money vía los casts del modelo tras el swap de Slice 1).
  - Satisface: R-MAPI-2, R-MAPI-11.

### 2.2 `delinquency` — mapeo de respuesta

- [x] **2.2.1** En el método `delinquency` (líneas 413-463), dejar el agrupado/sort/suma INTACTO sobre enteros (líneas 429-456, incl. `sortByDesc('outstanding_minor_units')` y la suma total) — el criterio de clasificación y ordenamiento no cambia.
- [x] **2.2.2** Envolver SOLO al construir el JSON (líneas 458-462): cada fila mapea `outstanding_minor_units` → `outstanding: TenantMoney::make(...)`; el top-level `total_outstanding_minor_units` → `total_outstanding: TenantMoney::make(...)` (la suma se calcula en enteros primero, se envuelve después). `units_with_debt`, `charge_count`, `oldest_due_date` no cambian.
  - Satisface: R-MAPI-3, R-MAPI-14.

### 2.3 Test: `StatementEndpointTest.php`

- [x] **2.3.1** Actualizar `backend/Modules/Collections/tests/Feature/StatementEndpointTest.php` (líneas 83-132): reemplazar asserts de enteros por asserts de path (`assertJsonPath('totals.expected.amount', '300000')->assertJsonPath('totals.expected.currency', 'MXN')` — recordar que `amount` es STRING en JSON). Agregar un caso que cubra `totals.surcharge` presente como Money (cierra el drift que el tipo del frontend omitía).
  - Satisface: R-MAPI-2.

Verificación: `php artisan test Modules/Collections/tests/Feature/StatementEndpointTest.php` (desde `backend/`).

### 2.4 Barrido de suites Collections generales (locate-then-fix)

- [x] **2.4.1** Ejecutar `rg -n "_minor|minor_units" backend/tests/Feature/Collections/CollectionsModuleTest.php backend/tests/Feature/Collections/CollectionsBatch3Test.php` (ambos en ruta CENTRAL, no módulo-local) para localizar cada assert afectado por este cambio (probablemente cubren statement/delinquency/charge a través de rutas HTTP).
- [x] **2.4.2** Corregir cada hit localizado en 2.4.1 al shape Money por path (mismo patrón que 2.3.1). Si algún hit no corresponde a un campo tocado por este cambio (p. ej. un campo interno de compute que no cruza el borde del API), dejarlo intacto y documentarlo en el mensaje de commit.
  - Satisface: R-MAPI-2, R-MAPI-3.

Verificación: `php artisan test tests/Feature/Collections/CollectionsModuleTest.php tests/Feature/Collections/CollectionsBatch3Test.php` (desde `backend/`).

---

## Slice 3 — Charge-batch summary

Satisface: R-MAPI-4, R-MAPI-14

Archivo: `backend/Modules/Collections/app/Http/Controllers/ChargeBatchController.php`

### 3.1 Mapeo de respuesta

- [x] **3.1.1** En el método `summary` (líneas 186-210), dejar el SQL (líneas 186-197) BYTE A BYTE igual — incluido el clamp `SUM(CASE WHEN…)`. Editar SOLO el armado de la respuesta (líneas 199-210): los valores de dinero pasan a `TenantMoney::make(...)`; las claves NO cambian de nombre; los counts siguen enteros planos.
  - Satisface: R-MAPI-4, R-MAPI-14.

### 3.2 Test: `ChargeBatchSummaryTest.php` (4 métodos)

- [x] **3.2.1** Actualizar `backend/Modules/Collections/tests/Feature/ChargeBatchSummaryTest.php` en sus 4 métodos afectados: el `assertExactJson` de la línea 83, y los asserts de enteros por path en las líneas 117-121, 142-146 y 183-185. Migrar todos a asserts de path con shape Money (`amount`/`currency`), preservando intactas las expectativas numéricas del clamp en minor units.
  - Satisface: R-MAPI-4.

Verificación: `php artisan test Modules/Collections/tests/Feature/ChargeBatchSummaryTest.php` (desde `backend/`).

---

## Slice 4 — Tableros de Treasury + public-summary

Satisface: R-MAPI-5, R-MAPI-6, R-MAPI-14

Archivo de servicio: `backend/Modules/Treasury/app/Services/TreasuryReportService.php` — los `computeX()` privados y `cachedReport()` quedan INT-PLANOS (claves `*_minor` DENTRO del cache sin cambio). El mapeo es código NUEVO en los 3 métodos públicos (hoy son passthrough puro de `cachedReport()`).

### 4.1 `incomeExpenseSeries` — mapeo

- [x] **4.1.1** En el método público `incomeExpenseSeries()`, agregar el mapeo tras `cachedReport()`: `series[].{income,expense,net}_minor` → `{income,expense,net}: TenantMoney::make(...)`; `totals.{income,expense,net}_minor` → Money. Iterar la colección `series[]` completa.
  - Satisface: R-MAPI-5.

### 4.2 `cashflowSeries` — mapeo

- [x] **4.2.1** En el método público `cashflowSeries()`: `accounts[].current_balance_minor` → `current_balance: TenantMoney::make(...)`; `series[].{inflow,outflow,running_balance}_minor` → Money; `totals.{total_balance,current_month_inflow,current_month_outflow}_minor` → Money.
  - Satisface: R-MAPI-5.

### 4.3 `expensesBreakdown` — mapeo

- [x] **4.3.1** En el método público `expensesBreakdown()`: `by_category[].amount_minor` → `amount: TenantMoney::make(...)` (conservar `pct` como entero plano, sin envolver); `by_provider[].amount_minor` → `amount: Money`; `monthly[].amount_minor` → `amount: Money`; `totals.{total,recurring,one_off}_minor` → Money.
  - Satisface: R-MAPI-5.

### 4.4 `treasury/public-summary` — mapeo

- [x] **4.4.1** En `backend/Modules/Treasury/app/Http/Controllers/PublicSummaryController.php`, método `summary` (líneas 53-105): editar el armado de `$data` (desde línea 83, sin cache propio) — `total_income`, `total_expenses`, `net_balance`, `expenses_by_category[].total`, `bank_account_balances[].current_balance` → `TenantMoney::make(...)`. Las claves ya son correctas (sin sufijo), no cambian de nombre.
  - Satisface: R-MAPI-6.

### 4.5 Test: `TreasuryReportControllerTest.php`

- [x] **4.5.1** Actualizar `backend/tests/Feature/Treasury/TreasuryReportControllerTest.php` (líneas 221-372) — requiere proporcionalmente MÁS asserts por path que otras suites (5 colecciones anidadas × 3 endpoints: `series[]`, `accounts[]`, `by_category[]`, `by_provider[]`, `monthly[]`). Migrar cada assert de entero a assert de path (`amount`/`currency`), sin tocar los asserts de `pct`/counts (siguen enteros).
  - Satisface: R-MAPI-5.

Verificación: `php artisan test tests/Feature/Treasury/TreasuryReportControllerTest.php` (desde `backend/`).

### 4.6 Test: public-summary

- [x] **4.6.1** Localizar los asserts de `treasury/public-summary` en `backend/tests/Feature/Treasury/TreasuryHttpTest.php` (confirmado — es la suite que referencia `PublicSummaryController` en este repo) y migrarlos al shape Money por path.
  - Satisface: R-MAPI-6.

Verificación: `php artisan test tests/Feature/Treasury/TreasuryHttpTest.php` (desde `backend/`).

---

## Slice 5 — Aging + funding + contribution + reports JSON

Satisface: R-MAPI-7, R-MAPI-8, R-MAPI-9, R-MAPI-10, R-MAPI-14

### 5.1 `collections/aging` — mapeo

- [x] **5.1.1** En `backend/Modules/Collections/app/Services/CollectionsReportService.php`, método `aging()`: mapear DESPUÉS de `cachedReport()` (líneas 63-70) — `totals.total_outstanding_minor` → `total_outstanding: TenantMoney::make(...)`; `buckets.{current,d1_30,d31_60,d61_90,d90_plus}` → Money; `by_status[].amount_minor` → `amount: Money`; `top_debtors[].outstanding_minor` → `outstanding: Money`. El compute interno queda intacto.
  - Satisface: R-MAPI-7.

- [x] **5.1.2** Actualizar `backend/tests/Feature/Collections/CollectionsReportControllerTest.php` (ruta CENTRAL) al shape Money por path.

Verificación: `php artisan test tests/Feature/Collections/CollectionsReportControllerTest.php` (desde `backend/`).

### 5.2 `projects/funding-summary` — mapeo

- [x] **5.2.1** En `backend/Modules/Projects/app/Services/ProjectsReportService.php`, método `fundingSummary()` (líneas 53-60): mapear DESPUÉS de `cachedReport()` — `projects[].{goal,raised}` y `totals.{goal,raised}` → `TenantMoney::make(...)`. `pct` sigue siendo entero plano.
  - Satisface: R-MAPI-8.

- [x] **5.2.2** Actualizar `backend/tests/Feature/Projects/ProjectsReportControllerTest.php` al shape Money por path.

Verificación: `php artisan test tests/Feature/Projects/ProjectsReportControllerTest.php` (desde `backend/`).

### 5.3 `community-project/{id}/summary` — renombre a inglés + mapeo

- [x] **5.3.1** En `backend/Modules/Projects/app/Services/ContributionService.php`, método `getSummary()` (líneas 355-381): renombrar las claves del array retornado de español a inglés — `recaudado` → `raised`, `ejecutado` → `executed`, `disponible` → `available`. El SERVICE sigue retornando ENTEROS (con nombres en inglés) — el wrapping a Money ocurre en el controller (5.3.2). `available` puede ser negativo — no agregar ningún guard que lo impida.
  - Satisface: R-MAPI-9 (regla HARD de identificadores en inglés).

- [x] **5.3.2** En `backend/Modules/Projects/app/Http/Controllers/CommunityProjectController.php`, método `summary` (líneas 223-232): envolver `raised`, `executed`, `available` con `TenantMoney::make(...)` (soporta negativos sin error).
  - Satisface: R-MAPI-9.

- [x] **5.3.3** Actualizar `backend/tests/Feature/Projects/ProjectsModuleTest.php` (líneas 559-563, 819-829) y `backend/tests/Feature/Projects/ProjectsHttpTest.php` (línea 456-457) — estas suites llaman al SERVICE directo (no al endpoint HTTP), así que el cambio es SOLO renombre de claves (`recaudado/ejecutado/disponible` → `raised/executed/available`); los valores siguen siendo enteros planos, NO se envuelven a Money aquí.
  - Satisface: R-MAPI-9.

- [x] **5.3.4** Actualizar `backend/tests/Feature/E2E/ClusterLifecycleTest.php` (líneas 670-674) — esta suite llama al ENDPOINT HTTP, así que necesita AMBOS cambios: renombre de claves Y conversión al shape Money (`amount` como string + `currency`). La línea 674 con `disponible` está DENTRO del rango a corregir — no dejarla pasar por alto.
  - Satisface: R-MAPI-9.

Verificación: `php artisan test tests/Feature/Projects/ProjectsModuleTest.php tests/Feature/Projects/ProjectsHttpTest.php tests/Feature/E2E/ClusterLifecycleTest.php` (desde `backend/`).

### 5.4 Reports JSON — solo `ReportController`

- [x] **5.4.1** En `backend/Modules/Reports/app/Http/Controllers/ReportController.php` (líneas 128-196): agregar el envoltorio en los 3 bordes JSON (hoy son passthrough puro `response()->json($this->reportService->x())`).
  - `reports/delinquency`: en `reports/delinquency` las filas `units[]` son ARRAYS PLANOS (no modelos) — mapear la colección de arrays Y el escalar top-level: `total_overdue_amount` → Money en AMBOS niveles (por unidad y total).
  - `reports/income-statement`: `total_income`, `total_expenses`, `net` → Money.
  - `reports/collection-progress`: `total_expected`, `total_collected` → Money.
  - `reports/account-balances`: SIN CAMBIO (ya proviene de modelos hidratados con Money vía cast).
  - `generate(format=json)`: queda raw (enteros crudos) — documentar en el docblock del método que es paridad intencional con los formatos de exportación (PDF/CSV/XLSX), NO un descuido.
  - `ReportService`, `ReportRegistry`, `ReportFormatter` NO se tocan en esta tarea — siguen operando enteros crudos internamente (alimentan el pipeline de exportación).
  - Satisface: R-MAPI-10.

- [x] **5.4.2** Actualizar `backend/tests/Feature/Reports/ReportsCatalogTest.php` y `backend/tests/Feature/Reports/ReportsModuleTest.php` (confirmado — son las suites que assertan `total_overdue_amount`/`total_income`/`total_expenses`/`total_expected`/`total_collected`) al shape Money por path para los 3 endpoints JSON. Agregar un caso que confirme que el PDF/CSV/XLSX generado para el mismo reporte muestra montos idénticos a los que mostraba antes del cambio (el pipeline de exportación no se tocó) y que `generate(format=json)` sigue devolviendo enteros crudos.
  - Satisface: R-MAPI-10.

Verificación: `php artisan test tests/Feature/Reports/ReportsCatalogTest.php tests/Feature/Reports/ReportsModuleTest.php` (desde `backend/`).

### 5.5 Guardrail — billing de plataforma excluido

- [x] **5.5.1** Confirmar (SIN modificar) que `backend/Modules/SubscriptionPlan/app/Http/Controllers/TenantBillingController.php` y los modelos de `SubscriptionPlan` NO se tocan en ninguna tarea de este documento — deben seguir serializando enteros (`integer` cast) en MXN.
  - Satisface: R-MAPI-13.

Verificación: `rg -n "TenantMoneyCast|QCH\\\\Core\\\\Support\\\\TenantMoney" backend/Modules/SubscriptionPlan` (desde la raíz del repo) → 0 hits.

---

## Slice 6 — Grupo C: centralización de formateo (backend)

Satisface: R-MAPI-12

- [x] **6.1.1** `backend/Modules/Reports/app/Services/ReportFormatter.php`: renombrar `moneyMxn()` → `moneyWithCurrency()`, implementado como `return TenantMoney::format($minorUnits);`. Rutear los ~12 sitios inline `'$'.number_format(...).' MXN'` del mismo archivo por el método. Los headers `(MXN)` hardcodeados de las tablas → `'('.TenantMoney::currency().')'`, calculado UNA VEZ por tabla (variable local antes del loop de filas, NO recalculado por fila).

- [x] **6.1.2** `backend/Modules/Collections/app/Services/PaymentService.php` (líneas 56-59): reemplazar el cuerpo del `formatMoney()` privado por `return TenantMoney::format($minorUnits);` — el call site no cambia.

- [x] **6.1.3** `backend/Modules/Treasury/app/Services/ExpenseService.php` (líneas 77-80): mismo reemplazo.

- [x] **6.1.4** `backend/Modules/Treasury/app/Console/Commands/SendMonthlyFinancialSummaryCommand.php` (líneas 92-95): mismo reemplazo.

- [x] **6.1.5** `backend/Modules/Projects/app/Services/ContributionService.php` (líneas 54-57): mismo reemplazo.

- [x] **6.1.6** `backend/Modules/Collections/app/Console/Commands/SendChargeRemindersCommand.php` (línea 96): reemplazar el `number_format` inline por `TenantMoney::format(...)`.

- [x] **6.1.7** `backend/Modules/Collections/resources/views/pdf/receipt.blade.php` (línea 20): reemplazar la expresión inline por `{{ \QCH\Core\Support\TenantMoney::format($raw) }}` (el template PDF corre dentro del request, con tenancy inicializada).

Verificación:
- `rg -n "number_format\(.*100" backend/Modules` (desde la raíz del repo) → 0 hits de dinero fuera del punto de formateo centralizado.
- `rg -n "' MXN'|\" MXN\"" backend/Modules` → 0 hits hardcodeados.
- `php artisan test --filter=PaymentServiceTest` (si existe) y `php artisan test Modules/Collections/tests/Feature` completo como smoke de que nada rompió por el cambio de firma interna.

---

## Slice 7 — Infraestructura frontend (moneda activa + sesión)

Satisface: R-TCUR-4 (infraestructura), R-TCUR-6 (lado frontend — no aplica static de proceso)

### 7.1 `frontend/src/shared/utils/currency.ts` (nuevo)

- [x] **7.1.1** Crear el archivo (confirmado que NO existe aún). Estado a nivel de módulo, CERO imports (evita ciclos): `let activeCurrency = 'MXN'`, `setActiveCurrency(currency: string | null | undefined): void` (normaliza vacío/null a `'MXN'`), `getActiveCurrency(): string`.
  - Satisface: R-TCUR-4.

### 7.2 Wiring de sesión

- [x] **7.2.1** `frontend/src/shared/auth/Session.ts` (líneas 11-16, confirmado que el archivo existe en esta ruta): agregar `currency?: string | null` a la interfaz `SessionTenant` (el JSON de login/refresh/`/me` YA lo envía — `AuthController.php:134,195,240` — el tipo lo omitía).
- [x] **7.2.2** `frontend/src/shared/auth/sessionStore.ts` (confirmado — ruta correcta, NO `shared/store/sessionStore.ts`): llamar `setActiveCurrency(profile?.tenant?.currency)` dentro de `setSession`, `setProfile` y `restoreSession` (restauración del estado persistido); llamar `setActiveCurrency(null)` (reset a MXN) dentro de `clearSession`.
  - Satisface: R-TCUR-4.

### 7.3 Helpers de dinero (locale queda `es-MX`)

- [x] **7.3.1** `frontend/src/shared/utils/money.ts` (líneas 26-31, función `formatMinorUnits`): `currency: 'MXN'` → `currency: getActiveCurrency()` (import de `./currency`, mismo directorio).
- [x] **7.3.2** `frontend/src/shared/utils/currencyHelper.ts` (líneas 11-16, función `displayFormatCurrency`): mismo cambio.
- [x] **7.3.3** Confirmar (SIN modificar) que `moneyToMinorUnits`/`displayMoney` ya toleran `IMoney | number | string` (verificado `money.ts:35-48`) — esto permite migrar los consumidores del Slice 8 en cualquier orden.
  - Satisface: R-TCUR-4.

### 7.4 Test: `money.test.ts`

- [x] **7.4.1** Agregar casos: `setActiveCurrency('USD')` → `formatMinorUnits` emite USD; reset a MXN tras `setActiveCurrency(null)`.

Verificación: `pnpm test src/shared/utils/money.test.ts` (desde `frontend/`).

---

## Slice 8 — Consumidores frontend

Satisface: R-MAPI-1..10 (lado de consumo), R-TCUR-4

Patrón único en todas las tareas de este slice: tipos `number` → `IMoney` en `schema.ts`/interfaces; display vía `displayMoney()`; aritmética vía `moneyToMinorUnits()`; derivados de gráfica (puntos de serie) vía `moneyToMinorUnits` por punto. Este slice puede aterrizar en cualquier orden relativo a los Slices 2-6 (tolerancia de tipo ya confirmada en 7.3.3).

### 8.1 Statement / delinquency

- [x] **8.1.1** `frontend/src/modules/payment/schema.ts` (líneas 88-114, confirmado existe): retipar campos de dinero a `IMoney`; AGREGAR el campo `surcharge` que faltaba en `totals` (corrige el drift donde el backend ya lo enviaba y el tipo del frontend lo omitía).
- [x] **8.1.2** `frontend/src/modules/payment/StatementPage.tsx`: migrar displays y la acumulación de grand totals a `moneyToMinorUnits`/`displayMoney`.
- [x] **8.1.3** `frontend/src/modules/payment/components/RegisterPaymentSheet.tsx`: migrar interfaz local + displays.
- [x] **8.1.4** `frontend/src/modules/payment/components/SubmitPaymentSheet.tsx`: mismo patrón.
- [x] **8.1.5** `frontend/src/modules/payment/DelinquencyPage.tsx`: mismo patrón (campo `outstanding`, `total_outstanding`).
- [x] **8.1.6** `frontend/src/modules/payment/services/PaymentResource.ts`: actualizar tipos de retorno.
- [x] **8.1.7** `frontend/src/modules/treasurerPanel/components/TreasurerHome.tsx`: migrar cualquier lectura de `formatMinorUnits` de statement/delinquency a `displayMoney` + acumulación en enteros vía `moneyToMinorUnits`.
  - Satisface: R-MAPI-2, R-MAPI-3.

Verificación: `pnpm test src/modules/payment` (desde `frontend/`).

### 8.2 Charge batch

- [x] **8.2.1** `frontend/src/modules/chargeBatch/services/ChargeBatchResource.ts` (líneas 10-25, confirmado existe): retipar campos de dinero a `IMoney`.
- [x] **8.2.2** `frontend/src/modules/chargeBatch/hooks/useChargeBatchStats.ts`: migrar aritmética a `moneyToMinorUnits`.
- [x] **8.2.3** `frontend/src/modules/chargeBatch/ChargeBatchDetail.tsx`: `progressPct` vía `moneyToMinorUnits` para los operandos de dinero.
  - Satisface: R-MAPI-4.

Verificación: `pnpm test src/modules/chargeBatch` (desde `frontend/`).

### 8.3 Los 5 dashboards

- [x] **8.3.1** `frontend/src/modules/dashboards/resultados/{schema.ts,services/ResultadosResource.ts,ResultadosDashboard.tsx,__tests__/resultados.test.ts}` (confirmado existen): retipar dinero a `IMoney`, migrar displays, actualizar fixtures del test al shape Money.
- [x] **8.3.2** `frontend/src/modules/dashboards/tesoreria/{schema.ts,services/TesoreriaResource.ts,TesoreriaDashboard.tsx,__tests__/tesoreria.test.ts}`: mismo patrón.
- [x] **8.3.3** `frontend/src/modules/dashboards/gastos/{schema.ts,services/GastosResource.ts,GastosDashboard.tsx,__tests__/gastos.test.ts}`: mismo patrón.
- [x] **8.3.4** `frontend/src/modules/dashboards/cobranza/{schema.ts,services/CobranzaResource.ts,CobranzaDashboard.tsx,__tests__/cobranza.test.ts}`: mismo patrón.
- [x] **8.3.5** `frontend/src/modules/dashboards/proyectos/{schema.ts,services/ProyectosResource.ts,ProyectosDashboard.tsx,__tests__/proyectos.test.ts}`: mismo patrón.
  - Satisface: R-MAPI-5, R-MAPI-7, R-MAPI-8.

Verificación: `pnpm test src/modules/dashboards` (desde `frontend/`).

### 8.4 Public summary

- [x] **8.4.1** `frontend/src/modules/expense/services/ExpenseResource.ts` (líneas 5-18, confirmado existe): retipar a `IMoney`.
- [x] **8.4.2** `frontend/src/modules/treasurerPanel/components/TreasurerHome.tsx`: migrar `formatMinorUnits` → `displayMoney` + acumulación en enteros para los campos de `public-summary`.
  - Satisface: R-MAPI-6.

Verificación: `pnpm test src/modules/expense` (desde `frontend/`).

### 8.5 Contribution summary

- [x] **8.5.1** `frontend/src/modules/communityProject/schema.ts` (líneas 68-72, confirmado existe): `raised/executed/available: IMoney` (reemplaza los campos en español que ya no existen en el payload).
- [x] **8.5.2** `frontend/src/modules/communityProject/services/CommunityProjectResource.ts` (líneas 33-35): actualizar tipos.
- [x] **8.5.3** `frontend/src/modules/communityProject/CommunityProjectDetailPage.tsx`: el check de disponibilidad negativa pasa a `moneyToMinorUnits(available) >= 0`; labels en español de la UI quedan intactos (solo cambian los identificadores del payload, no el texto visible).
  - Satisface: R-MAPI-9.

Verificación: `pnpm test src/modules/communityProject` (desde `frontend/`).

### 8.6 Reports previews

- [x] **8.6.1** `frontend/src/modules/reports/services/ReportsResource.ts` (líneas 7-27, confirmado existe): actualizar SOLO tipos a `IMoney` para los 3 campos de R-MAPI-10.
- [x] **8.6.2** Confirmar (SIN modificar) que `frontend/src/modules/reports/components/ReportPreviewDialog.tsx` ya renderiza con `displayMoney` — no requiere cambio de lógica, solo se beneficia del retipado.
  - Satisface: R-MAPI-10.

Verificación: `pnpm test src/modules/reports` (desde `frontend/`).

### 8.7 Compuerta general del slice

- [x] **8.7.1** `pnpm build` (tsc) — compuerta del clean break: cada lector no migrado de un campo renombrado/retipado debe aparecer como error de compilación. Cero `any` nuevo.

Verificación: `pnpm build` (desde `frontend/`).

---

## Slice 9 — UI de configuración + validación de moneda

Satisface: R-TCUR-3, R-TCUR-5

### 9.1 Backend — validación

- [x] **9.1.1** `backend/Modules/Configuration/app/Http/Controllers/ConfigurationController.php` (línea 228, confirmado existe): `'currency' => ['sometimes', 'string', 'max:10']` → `['sometimes', 'string', Rule::in(['MXN', 'USD'])]` (`Rule` ya está importado, se usa en línea 229). El gate de confirmación YA EXISTE server-side (líneas 231-240, responde 422 con `requires_confirmation: true`) — CERO cambios de flujo, solo el swap de la regla de validación.
  - Satisface: R-TCUR-3.

Verificación: `php artisan test --filter=ConfigurationController` o el path exacto de la suite de configuración operacional (localizar con `rg -rl "operational" backend/tests backend/Modules/Configuration/tests` si el nombre exacto no es evidente).

### 9.2 Frontend — Select + confirmación

- [x] **9.2.1** `frontend/src/modules/configuration/components/OperationalConfigSection.tsx` (líneas 40-43, confirmado existe): el `Input` de moneda `disabled readOnly` → `Select` habilitado con lista curada (`MXN`, `USD`), gateado por `canUpdate`.
- [x] **9.2.2** En `saveOperational` (líneas 21-29): si `form.currency` cambió respecto al valor original, abrir un `AlertDialog` (shadcn) de confirmación advirtiendo que los montos existentes se REINTERPRETAN, NUNCA se convierten aritméticamente. Al confirmar, incluir `currency` + `confirmed: true` en el payload.
- [x] **9.2.3** Al éxito de la mutación: actualizar `tenant.currency` en la sesión, llamar `setActiveCurrency(nuevaMoneda)`, y `queryClient.invalidateQueries()` — de lo contrario los `formatted` ya cacheados en el cliente quedarían con la moneda vieja.
  - Satisface: R-TCUR-3, R-TCUR-5.

Verificación: `pnpm test src/modules/configuration` (desde `frontend/`) + smoke manual (ver Slice 10, tarea E2E).

---

## Slice 10 — Compuertas de completitud, documentación y entrega

Satisface: cierre transversal de R-MAPI-1..14 y R-TCUR-1..6

### 10.1 Compuertas de grep (completitud)

- [x] **10.1.1** `rg -n "MoneyIntegerCast::class.':MXN'" backend/Modules` (desde la raíz) → 0 hits.
- [x] **10.1.2** `rg -n "number_format\(.*100" backend/Modules` → 0 hits de dinero fuera de `TenantMoney::format()`.
- [x] **10.1.3** `rg -n "_minor|minor_units|recaudado|' MXN'" backend/tests backend/Modules/*/tests` → los únicos sobrevivientes deben ser claves internas de cache/cómputo (documentadas en el design como intencionalmente sin cambio); CADA otro hit debe corregirse antes de cerrar esta tarea.

### 10.2 Pint

- [x] **10.2.1** `vendor/bin/pint --dirty` (desde `backend/`) — cero errores de formato.

### 10.3 Suite completa backend

- [x] **10.3.1** `php artisan test` completo (desde `backend/`, UN SOLO proceso a la vez sobre la DB `testing`) — verde.

### 10.4 Frontend — build + test

- [x] **10.4.1** `pnpm build` (desde `frontend/`) — verde, cero `any`.
- [x] **10.4.2** `pnpm test` (desde `frontend/`) — todos los Vitest verdes (schemas, resources, render smokes, los 5 fixtures de dashboards).

### 10.5 Smoke E2E manual

- [ ] **10.5.1** Abrir `http://192.168.100.136:5173` (regla de mismo origen — NUNCA `localhost:5173` para esta app, ver CLAUDE.md). Verificar: el estado de cuenta (`/unit/{id}/statement`) muestra montos idénticos a los de antes del cambio; los KPIs de `charge-batch/{id}/summary` no cambian.
- [ ] **10.5.2** Cambiar la moneda del tenant de MXN a USD desde Configuración → confirmar que el estado de cuenta, los tableros y los previews de reportes ya abiertos reflejan USD sin recargar la página completa (tras la siguiente actualización de datos).

### 10.6 Documentación y memoria

- [x] **10.6.1** `.agents/skills/laravel-backend-architecture/SKILL.md` — agregar la regla 17 en la sección 3 (después de la regla 16 actual, "NUNCA uses columnas `is_active`..."): el patrón de dinero en el API (dinero cruza la frontera SOLO como objeto Money serializado `{amount, currency, formatted}`; enteros minor-units viven en servicio/cache; moneda desde `tenant()->currency`, nunca hardcodeada). Este archivo vive en la RAÍZ del repo, no en el submódulo `backend/`.
- [x] **10.6.2** Guardar patrón en engram: `backend/money-api-serialization` (title + topic_key), type `pattern`, resumiendo el mecanismo (`TenantMoneyCast`/`TenantMoney`) y el punto de mapeo post-cache.
- [ ] **10.6.3** Guardar en la memoria automática del proyecto (`/Users/rodriguez/.claude/projects/-Users-rodriguez-code-residia/memory/`) el hallazgo de convención de rutas de test (módulo-local vs central) si no está ya cubierto por una memoria existente.

### 10.7 Entrega

- [ ] **10.7.1** Confirmar que cada slice (1-9) ya quedó commiteado con conventional commits dentro de su submódulo (`backend`, `frontend`) según la tabla de "Resumen de slices". Ningún worktree — edición directa en el checkout de cada submódulo.
- [ ] **10.7.2** Commit del root repo con la edición de `.agents/skills/laravel-backend-architecture/SKILL.md` (10.6.1).
- [ ] **10.7.3** Bump de los pointers de los submódulos `backend` y `frontend` en el root repo (un solo commit al final, después de que ambos submódulos tengan su HEAD final pusheado).
- [ ] **10.7.4** Push de las ramas de `backend` y `frontend`, y de la rama del root repo. NO mergear a `main`/`master` sin aprobación explícita.

---

## Matriz de trazabilidad Spec → Tasks

| Requisito | Satisfecho por |
|---|---|
| R-MAPI-1 (dinero serializa como Money en cualquier borde) | Slices 2-6 (mapeo por endpoint) + 10.1 (compuerta de grep) |
| R-MAPI-2 (statement) | 2.1, 2.3 |
| R-MAPI-3 (delinquency) | 2.2, 2.3 |
| R-MAPI-4 (charge-batch summary) | 3.1, 3.2 |
| R-MAPI-5 (tableros Treasury ×3) | 4.1, 4.2, 4.3, 4.5 |
| R-MAPI-6 (public-summary) | 4.4, 4.6 |
| R-MAPI-7 (collections/aging) | 5.1 |
| R-MAPI-8 (projects/funding-summary) | 5.2 |
| R-MAPI-9 (community-project/summary, renombre EN) | 5.3, 8.5 |
| R-MAPI-10 (reports JSON) | 5.4, 8.6 |
| R-MAPI-11 (caches conservan enteros) | 2.1.1 (patrón inaugural) + invariante respetado en 3.1, 4.1-4.4, 5.1-5.4 |
| R-MAPI-12 (formateo centralizado) | Slice 6 |
| R-MAPI-13 (billing excluido) | 5.5 |
| R-MAPI-14 (cero cambio de lógica) | Guardrail transversal + asserts "byte a byte" en 2.3, 3.2, 4.5, 5.1.2, 5.2.2 + smoke 10.5 |
| R-TCUR-1 (orden de resolución) | 1.1, 1.2, 1.3, 1.5 |
| R-TCUR-2 (11 modelos resuelven por tenant) | 1.4, 1.5 |
| R-TCUR-3 (selección de moneda en config) | 9.1, 9.2 |
| R-TCUR-4 (frontend formatea con moneda activa) | Slice 7, Slice 8 |
| R-TCUR-5 (cambio de moneda refleja de inmediato) | 9.2.3, 10.5.2 |
| R-TCUR-6 (Octane/colas seguro) | 1.2, 1.5, 1.6 |

---

## Review Workload Forecast

| Métrica | Estimación |
|---|---|
| Archivos backend nuevos | ~3 (`config/money.php` publicado, `TenantMoneyCast.php`, `TenantMoney.php`) + 1 test nuevo (`TenantMoneyCastTest.php`) |
| Archivos backend modificados | ~11 modelos (swap de cast) + ~9 controllers/services (mapeo por endpoint, Slice 2-5) + ~7 sitios de formateo (Slice 6) + 1 borrado (`app/Casts/MoneyCast.php`) + `ConfigurationController.php` ≈ 29 archivos |
| Suites backend modificadas | ~11 (`StatementEndpointTest`, `ChargeBatchSummaryTest`, `TreasuryReportControllerTest`, `TreasuryHttpTest`, `CollectionsReportControllerTest`, `ProjectsReportControllerTest`, `ProjectsModuleTest`, `ProjectsHttpTest`, `ClusterLifecycleTest`, `ReportsCatalogTest`, `ReportsModuleTest`) + barrido `CollectionsModuleTest`/`CollectionsBatch3Test` |
| Archivos frontend nuevos | 1 (`currency.ts`) |
| Archivos frontend modificados | ~24 (`Session.ts`, `sessionStore.ts`, `money.ts`, `currencyHelper.ts`, 7 archivos de statement/delinquency, 3 de charge batch, 20 de los 5 dashboards, 2 de expense/public-summary, 3 de contribution summary, 2 de reports previews, `OperationalConfigSection.tsx`) |
| **Líneas estimadas de cambio** | ~1200-1700 líneas a través de 2 submódulos |
| PRs encadenados recomendados | Evaluados y NO requeridos — ver decisión resuelta abajo |

**Decisión de entrega ya resuelta por el usuario:** entrega única con `size:exception`. No se abre un PR encadenado por slice; los 10 slices se commitean secuencialmente dentro de cada submódulo (conventional commits, ver tabla de "Resumen de slices") y el pointer del root repo se bumpea una sola vez al final (Slice 10.7.3). El tamaño total (~1200-1700 líneas) excede el umbral habitual de PR, pero es completamente trazable por límite de commit por slice — cada reviewer puede auditar slice por slice en lugar de todo el diff de una vez. Dado que el diff toca superficies de `payments`/`update` (statement, cargos, configuración de moneda), el trigger de 400+ líneas cambiadas del proyecto activa recomendación de correr en paralelo `review-risk`, `review-resilience`, `review-readability` y `review-reliability` (4R completo) antes de abrir el PR — y `judgment-day` está fuertemente recomendado post-apply por las trigger rules del proyecto para este tipo de cambio (fase apply de alto impacto).

---

*Generado por sdd-tasks el 2026-07-04. Autoridad: spec (engram #1497), design GATE-REVIEWED (engram #1498).*
