# SDD Design — Moneda por tenant y serialización de dinero en el API

- **Change:** `tenant-currency-money-api`
- **Project:** `residia-monorepo` (Kivo7)
- **Phase:** design (el CÓMO a nivel arquitectónico)
- **Artifact store:** hybrid (este archivo + engram `sdd/tenant-currency-money-api/design`)
- **Date:** 2026-07-04
- **Reads:** proposal (`openspec/changes/tenant-currency-money-api/proposal.md` + engram #1495), exploración (engram #1494), síntesis aprobada por el usuario (tablas ANTES→DESPUÉS por endpoint), código vivo verificado esta fase: `vendor/cknow/laravel-money/src/Casts/MoneyCast.php`, `vendor/cknow/laravel-money/config/config.php`, `vendor/cknow/laravel-money/src/MoneyServiceProvider.php`, `PaymentController.php:290-463`, `core/composer.json`, `core/src/Services/CachesTenantReports.php`, `TreasuryReportService.php`, `ConfigurationController.php:200-249`, `Session.ts`, `money.ts`, `currencyHelper.ts`, `OperationalConfigSection.tsx`.
- **Orden de autoridad ante conflicto:** skills laravel-backend-architecture / stancl-tenancy / react-frontend-architecture > `CLAUDE.md` > este design. Este design no re-decide nada: las decisiones están CERRADAS por el usuario; aquí se formalizan con precisión file:line y código final.

---

## 0. Resumen ejecutivo

Tres piezas nuevas y pequeñas — `config/money.php` publicado, `QCH\Core\Casts\TenantMoneyCast` (subclase de `MoneyIntegerCast` con un solo override de `getCurrency()`) y `QCH\Core\Support\TenantMoney` (`currency()/make()/format()`, el único punto de conversión) — hacen que el dinero cruce la frontera del API exclusivamente como objeto Money serializado `{amount, currency, formatted}` con la moneda resuelta por llamada desde `tenant()->currency`. Los 8 grupos de endpoints con agregados crudos mapean int→Money al armar la respuesta, SIEMPRE después de `Cache::remember`/`cachedReport()` (los caches siguen guardando enteros planos con claves internas sin cambio). El frontend gana `currency.ts` (moneda activa de sesión, módulo sin imports), los helpers dejan de hardcodear MXN, los tipos pasan de `number` a `IMoney` (tsc como red de seguridad del clean break) y la UI de configuración habilita el Select MXN/USD con confirmación. Cero migraciones, cero cambios de lógica de negocio.

---

## 1. Enfoque arquitectónico

### 1.1 El patrón a institucionalizar (regla 17 del skill laravel-backend-architecture)

> El dinero cruza la frontera del API SOLO como objeto Money serializado `{amount, currency, formatted}`; los enteros minor-units viven en la capa de servicio/cache. La moneda sale de `tenant()->currency` (fallback `config('money.defaultCurrency')`), nunca hardcodeada.

### 1.2 Flujo de datos (un agregado, de punta a punta)

```
DB (bigint minor units)
  └─ selectRaw/sum()/getAttributes()  ──►  Service/compute  (ints planos)
        └─ Cache::remember / cachedReport()  (payload = ints, claves internas SIN cambio)
              └─ borde de respuesta (controller o método público del service)
                    └─ TenantMoney::make($int)  ──►  JSON {amount:"<string>", currency, formatted}
                           ▲
                           └─ TenantMoney::currency(): tenancy()->initialized
                                ? tenant()->currency (guard isValidCurrency)
                                : config('money.defaultCurrency')   ← resolución POR LLAMADA
```

Los modelos hidratados ya serializan Money vía cast; solo cambia QUÉ cast (tenant-aware) y se elimina el parámetro `:MXN`.

---

## 2. DECISIÓN 1 — Mecanismo core (código final)

### 2.1 `backend/config/money.php` (publicar y editar 2 valores)

Publicar con `php artisan vendor:publish --provider="Cknow\Money\MoneyServiceProvider" --tag=config` (verificado en `MoneyServiceProvider.php:29` → `config_path('money.php')`) y cambiar SOLO `locale` y `defaultCurrency`:

```php
<?php

return [
    'locale' => 'es_MX',
    'defaultCurrency' => 'MXN',
    'defaultFormatter' => null,   // keep null: ArrayMoneySerializer emits {amount, currency, formatted}
    'defaultSerializer' => null,
    'isoCurrenciesPath' => is_dir(__DIR__.'/../vendor')
        ? __DIR__.'/../vendor/moneyphp/money/resources/currency.php'
        : __DIR__.'/../../../moneyphp/money/resources/currency.php',
    'currencies' => [
        'iso' => 'all',
        'bitcoin' => 'all',
        'custom' => [],
    ],
];
```

- El branch `is_dir` del vendor se conserva VERBATIM: desde `backend/config/` resuelve a `backend/vendor/moneyphp/money/resources/currency.php` (correcto en ambas ubicaciones — verificado contra `vendor/cknow/laravel-money/config/config.php:13-15`).
- `defaultSerializer => null` es load-bearing: el default del paquete es el `ArrayMoneySerializer` que produce `{amount, currency, formatted}`. No tocar.
- Valores constantes → Octane-safe: el static del vendor (`CurrenciesTrait.php:59` inicializa `setDefaultCurrency` desde config) guarda una CONSTANTE (MXN), nunca un valor por tenant.

### 2.2 `backend/core/src/Casts/TenantMoneyCast.php` (nuevo — PSR-4 `QCH\Core\ => src/` verificado en `core/composer.json`)

Seam verificado en `vendor/cknow/laravel-money/src/Casts/MoneyCast.php`: `$currency` es `protected` (línea 16), y TANTO `get()` (línea 58) COMO `set()` (línea 77) rutean por `protected function getCurrency(array $attributes)` (líneas 98-116, SIN return type) → un solo override cubre lectura y escritura.

```php
<?php

namespace QCH\Core\Casts;

use Cknow\Money\Casts\MoneyIntegerCast;
use Cknow\Money\Money;
use QCH\Core\Support\TenantMoney;

/**
 * Tenant-aware money cast. Resolves the currency from the current tenancy
 * context on EVERY call — no static state, no per-tenant default (Octane-safe).
 *
 * Usage: 'amount' => TenantMoneyCast::class   (NEVER append ':MXN')
 * An explicit parameter (e.g. TenantMoneyCast::class.':EUR') still wins via
 * the parent passthrough, preserving vendor semantics.
 */
class TenantMoneyCast extends MoneyIntegerCast
{
    /**
     * @return \Money\Currency
     */
    protected function getCurrency(array $attributes) // signature mirrors the vendor: no return type
    {
        if ($this->currency !== null) {
            return parent::getCurrency($attributes); // explicit-param passthrough
        }

        return Money::parseCurrency(TenantMoney::currency());
    }
}
```

### 2.3 `backend/core/src/Support/TenantMoney.php` (nuevo — el ÚNICO punto de conversión)

```php
<?php

namespace QCH\Core\Support;

use Cknow\Money\Money;

/**
 * Single conversion point between raw minor-unit integers and Money at the
 * response boundary. Aggregates and cache payloads stay plain ints; wrap with
 * make() when building JSON, and use format() for notification/PDF strings.
 * Currency resolves per call from tenancy — never cache it in a static.
 */
class TenantMoney
{
    /** Active currency code: tenant currency when tenancy is initialized, else central fallback. */
    public static function currency(): string
    {
        if (tenancy()->initialized) {
            $currency = (string) (tenant()->currency ?? '');

            if ($currency !== '' && Money::isValidCurrency($currency)) {
                return $currency;
            }
        }

        return (string) config('money.defaultCurrency', 'MXN');
    }

    /** Wrap raw minor units — serializes to {amount, currency, formatted} like the model casts. */
    public static function make(int $minorUnits): Money
    {
        return money($minorUnits, self::currency());
    }

    /** Display string for notifications/PDF (replaces '$'.number_format($raw/100, 2).' MXN'). */
    public static function format(int $minorUnits): string
    {
        return self::make($minorUnits)->format();
    }
}
```

- El guard `tenancy()->initialized` es el patrón del repo (`CachesTenantReports.php:78`, `PaymentController.php:400`).
- `Money::isValidCurrency()` protege contra valores basura persistidos antes de la validación `Rule::in` (§5.5).

### 2.4 Swap de casts en los 11 modelos + borrado del cast legacy

`MoneyIntegerCast::class.':MXN'` → `TenantMoneyCast::class` (sin parámetro) en: Collections `Charge.php:51-55`, `Payment.php:47`, `PaymentApplication.php:41`, `CreditBalance.php:31`, `ChargePolicy.php:56-60`; Treasury `BankAccount.php:43-44`, `Expense.php:56`; Providers `Provider.php:50`; Projects `ProjectContribution.php:34-35`, `CommunityProject.php:53-57`; Collaborator `Collaborator.php:114`. Borrar `backend/app/Casts/MoneyCast.php` (legacy muerto, cero referencias — verificado en exploración).

---

## 3. DECISIÓN 2 — Puntos de mapeo por endpoint (los 8 grupos)

Regla transversal: la conversión int→Money ocurre DESPUÉS de `Cache::remember`/`cachedReport()`, al armar la respuesta. Counts, porcentajes, labels y fechas no cambian. Claves internas de cache no cambian. **M** = `{amount, currency, formatted}`.

| # | Endpoint | Punto EXACTO de mapeo | Contrato ANTES → DESPUÉS |
|---|---|---|---|
| 1 | `unit/{id}/statement` — `PaymentController::statement` (`Modules/Collections/.../PaymentController.php:304-340`) | El literal de respuesta `:334-339`. `cachedStatementAggregate()` `:354-408` queda INTACTO (shape del cache `:384-393` con claves `*_minor_units` sin cambio; `Cache::remember` `:403-407` intacto) | `credit_balance_minor_units` → `credit_balance: M`; `totals.{expected,discount,surcharge,paid,outstanding}_minor_units` → `totals.{expected,discount,surcharge,paid,outstanding}: M` |
| 2 | `delinquency` — `PaymentController::delinquency` (`:413-463`) | El agrupado/sort/suma sigue sobre ENTEROS (`:429-456` intactos, incl. `sortByDesc('outstanding_minor_units')` y `sum(...)`); se envuelve al construir el JSON `:458-462` — cada fila mapea `outstanding_minor_units` → `outstanding: M` y el top-level suma primero, envuelve después | `data[].outstanding_minor_units` → `data[].outstanding: M`; `total_outstanding_minor_units` → `total_outstanding: M`; `units_with_debt`/`charge_count`/`oldest_due_date`/identificadores sin cambio |
| 3 | `charge-batch/{id}/summary` — `ChargeBatchController::summary` (`:186-210`) | SQL `:186-197` BYTE A BYTE igual (incl. clamp `SUM(CASE WHEN…)`); solo el armado de respuesta `:199-210` envuelve valores | Claves conservan nombre; valores de dinero → M; counts siguen enteros |
| 4 | Tableros Treasury ×3 — `TreasuryReportService::{incomeExpenseSeries,cashflowSeries,expensesBreakdown}` | Los `computeX()` privados y `cachedReport()` quedan int-planos (claves `*_minor` DENTRO del cache sin cambio); AGREGAR el mapeo al MÉTODO PÚBLICO (hoy los 3 métodos públicos son passthrough puros de `cachedReport()` — este mapeo es código NUEVO que itera 5 colecciones anidadas: `series[]`, `accounts[]`, `by_category[]`, `by_provider[]`, `monthly[]`, con ~15 renombres de clave a través de los 3 endpoints) | `series[].{income,expense,net}_minor` → `{income,expense,net}: M`; `accounts[].current_balance_minor` → `current_balance: M`; `series[].{inflow,outflow,running_balance}_minor` → M; `totals.{income,expense,net}_minor`, `totals.{total_balance,current_month_inflow,current_month_outflow}_minor`, `totals.{total,recurring,one_off}_minor` → M; `by_category[].amount_minor`/`by_provider[].amount_minor`/`monthly[].amount_minor` → `amount: M`; `margin_pct`/`recurring_pct`/`pct`/`account_count`/`provider_count` sin cambio |
| 5 | `treasury/public-summary` — `PublicSummaryController::summary` (`:53-105`) | Armado de respuesta: `$data` se construye desde `:83` y `response()->json` cierra en `:105` (sin cache propio) | Claves ya limpias; valores de `total_income`, `total_expenses`, `net_balance`, `expenses_by_category[].total`, `bank_account_balances[].current_balance` → M |
| 6 | `collections/aging` — `CollectionsReportService::aging` | Mapear después de `cachedReport` (`:63-70`); compute intacto | `totals.total_outstanding_minor` → `total_outstanding: M`; `buckets.{current,d1_30,d31_60,d61_90,d90_plus}` → M; `by_status[].amount_minor` → `amount: M`; `top_debtors[].outstanding_minor` → `outstanding: M` |
| 7 | `projects/funding-summary` — `ProjectsReportService::fundingSummary` (`:53-60`) | Mapear después de `cachedReport` | `projects[].{goal,raised}` y `totals.{goal,raised}` → M; `pct` sin cambio |
| 8 | `community-project/{id}/summary` — `ContributionService::getSummary` (`:355-381`) + `CommunityProjectController::summary` (`:223-232`) | El SERVICE regresa enteros con claves en INGLÉS (renombre `recaudado/ejecutado/disponible` → `raised/executed/available` — regla HARD de identificadores); el CONTROLLER envuelve con `TenantMoney::make()` | `recaudado/ejecutado/disponible` (int) → `raised/executed/available: M` (`available` puede ser NEGATIVO — Money lo soporta) |
| 9 | Reports JSON — SOLO `ReportController` (`:128-196`) | AGREGAR el envoltorio en los 3 bordes JSON (hoy los 3 métodos del controller son passthrough puros `response()->json($this->reportService->x())`; en `reports/delinquency` las filas `units[]` son ARRAYS PLANOS — no modelos — así que el controller mapea la colección de arrays Y reemplaza el escalar top-level); `ReportService`/`ReportRegistry`/`ReportFormatter` siguen int-planos (alimentan PDF/CSV/XLSX) | `reports/delinquency`: `total_overdue_amount` → M (ambos niveles); `reports/income-statement`: `total_income/total_expenses/net` → M; `reports/collection-progress`: `total_expected/total_collected` → M. `reports/account-balances`: SIN cambio (modelos hidratados). `generate(format=json)` queda raw — documentar en el docblock que es feed de paridad con exports |

Sketch del patrón (statement, el caso mixto que originó el bug):

```php
$aggregate = $this->cachedStatementAggregate($unitId); // cache: plain ints, keys unchanged

return response()->json([
    'unit_id' => $unitId,
    'charges' => $charges, // hydrated models — TenantMoneyCast serializes them
    'credit_balance' => TenantMoney::make($aggregate['credit_balance_minor_units']),
    'totals' => [
        'expected' => TenantMoney::make($aggregate['totals']['expected_minor_units']),
        'discount' => TenantMoney::make($aggregate['totals']['discount_minor_units']),
        'surcharge' => TenantMoney::make($aggregate['totals']['surcharge_minor_units']),
        'paid' => TenantMoney::make($aggregate['totals']['paid_minor_units']),
        'outstanding' => TenantMoney::make($aggregate['totals']['outstanding_minor_units']),
    ],
]);
```

---

## 4. DECISIÓN 3 — Grupo C: centralización de formateo (backend)

| Sitio | Cambio |
|---|---|
| `Modules/Reports/.../ReportFormatter.php` | `moneyMxn()` → `moneyWithCurrency()` implementado como `return TenantMoney::format($minorUnits);`; rutear los ~12 sitios inline `'$'.number_format(...).' MXN'` por el método; headers `(MXN)` hardcodeados → `'('.TenantMoney::currency().')'` calculado UNA VEZ por tabla (variable local antes del loop, no por fila) |
| 4 `formatMoney()` privados idénticos: Collections `PaymentService.php:56-59`, Treasury `ExpenseService.php:77-80`, Treasury `SendMonthlyFinancialSummaryCommand.php:92-95`, Projects `ContributionService.php:54-57` | Reemplazar SOLO el cuerpo por `return TenantMoney::format($minorUnits);` — call sites intactos, diff mínimo |
| Collections `SendChargeRemindersCommand.php:96` | Inline `number_format` → `TenantMoney::format()` |
| Collections `resources/views/pdf/receipt.blade.php:20` | Expresión inline → `{{ \QCH\Core\Support\TenantMoney::format($raw) }}` (template PDF dompdf, se genera dentro del request con tenancy inicializada) |

---

## 5. Diseño de frontend

### 5.1 `frontend/src/shared/utils/currency.ts` (nuevo — contrato)

```ts
/**
 * Active display currency for the session's tenant.
 * Module-level state with ZERO imports (no dependency cycles).
 * Written from SessionTenant.currency on login/refresh/restore; read by money helpers.
 */
let activeCurrency = 'MXN'

export function setActiveCurrency(currency: string | null | undefined): void {
  activeCurrency = currency && currency.trim() !== '' ? currency : 'MXN'
}

export function getActiveCurrency(): string {
  return activeCurrency
}
```

### 5.2 Wiring de sesión

- `shared/auth/Session.ts:11-16`: `SessionTenant` gana `currency?: string | null` (el JSON de login/refresh/`/me` YA lo envía — `AuthController.php:134,195,240`; el tipo lo omitía).
- `shared/auth/sessionStore.ts` (path VERIFICADO por el gate review — NO existe `shared/store/sessionStore.ts`): llamar `setActiveCurrency(profile?.tenant?.currency)` en `setSession`, `setProfile` y `restoreSession` (la restauración del estado persistido); `setActiveCurrency(null)` (reset a MXN) en `clearSession`.

### 5.3 Helpers de dinero (locale queda `es-MX` — la UI es en español por regla)

- `money.ts:26-31` (`formatMinorUnits`): `currency: 'MXN'` → `currency: getActiveCurrency()` (import de `./currency`, mismo directorio, sin ciclo).
- `currencyHelper.ts:11-16` (`displayFormatCurrency`): ídem.
- `moneyToMinorUnits`/`displayMoney`: SIN cambios — ya toleran `IMoney | number | string` (verificado `money.ts:35-48`), lo que permite migrar consumidores en cualquier orden. Nota: `amount` llega como STRING (moneyphp `jsonSerialize`) y `moneyToMinorUnits` ya lo parsea con `parseInt`.

### 5.4 Migración de consumidores (patrón + lista completa localizada)

Patrón único: tipos `number` → `IMoney` en `schema.ts`/interfaces; display vía `displayMoney()`; aritmética vía `moneyToMinorUnits()` y `formatMinorUnits()` para derivados; puntos de gráfica necesitan números → `moneyToMinorUnits` por punto de serie.

- **Statement/delinquency:** `payment/schema.ts:88-114` (AGREGAR el campo `surcharge` faltante — corrige el drift actual), `StatementPage.tsx` (interfaz local, displays, acumulación de grand totals), `RegisterPaymentSheet.tsx`, `SubmitPaymentSheet.tsx`, `DelinquencyPage.tsx`, `PaymentResource.ts`, `treasurerPanel/components/TreasurerHome.tsx`.
- **Charge batch:** `chargeBatch/services/ChargeBatchResource.ts:10-25`, `hooks/useChargeBatchStats.ts`, `ChargeBatchDetail.tsx` (progressPct vía `moneyToMinorUnits`).
- **5 dashboards:** `dashboards/{resultados,tesoreria,gastos,cobranza,proyectos}/{schema.ts, services/*Resource.ts, *Dashboard.tsx, __tests__/*.test.ts}`.
- **Public summary:** `expense/services/ExpenseResource.ts:5-18`, `TreasurerHome.tsx` (`formatMinorUnits`→`displayMoney` + acumulación en enteros).
- **Contribution summary:** `communityProject/schema.ts:68-72` (`raised/executed/available: IMoney`), `CommunityProjectResource.ts:33-35`, `CommunityProjectDetailPage.tsx` (check de negativo vía `moneyToMinorUnits(...) >= 0`; labels españoles de UI intactos).
- **Reports previews:** `reports/services/ReportsResource.ts:7-27` solo tipos — `ReportPreviewDialog.tsx` ya renderiza con `displayMoney`.

### 5.5 UI de configuración + validación backend

- `OperationalConfigSection.tsx:40-43`: el `Input` de moneda `disabled readOnly` → `Select` habilitado con lista curada (`MXN`, `USD`), gateado por `canUpdate`.
- `saveOperational` (`:21-29`): si `form.currency` cambió, abrir diálogo de confirmación (AlertDialog shadcn) advirtiendo que los montos se **reinterpretan, no se convierten**; al confirmar, incluir `currency` + `confirmed: true` en el payload.
- Al éxito de la mutación: actualizar `tenant.currency` en la sesión + `setActiveCurrency(nueva)` + `queryClient.invalidateQueries()` (los `formatted` del servidor quedarían viejos si no).
- **Backend** `ConfigurationController.php:228`: `'currency' => ['sometimes', 'string', 'max:10']` → `['sometimes', 'string', Rule::in(['MXN', 'USD'])]` (`Rule` ya está importado — se usa en `:229`). El gate de confirmación YA EXISTE server-side (`:231-240`, responde 422 con `requires_confirmation: true`) — cero cambios de flujo backend, solo el swap de la regla.

---

## 6. Diseño de tests

### 6.1 Nuevo `backend/tests/Feature/Core/TenantMoneyCastTest.php`

| Caso | Assert |
|---|---|
| Aislamiento por tenant | Tenant A (`currency=MXN`) vs tenant B (`currency=USD`): inicializar cada tenancy y serializar el mismo modelo de dinero → `currency` difiere (`MXN` vs `USD`) con el mismo `amount` |
| Fallback central | `tenancy()->end()` → serializa `MXN` (de `config('money.defaultCurrency')`) |
| Passthrough explícito | Instancia directa `new TenantMoneyCast('USD')` → `get()` regresa Money USD aunque la tenancy activa sea MXN (semántica del vendor preservada) |
| Moneda inválida persistida | Tenant con `currency='XXX_BAD'` → fallback MXN (guard `isValidCurrency`) |

### 6.2 Estrategia de migración de asserts (backend)

Regla: assertear `amount` + `currency` por path; NUNCA el `formatted` textual (frágil por locale/ICU). **`amount` es STRING en JSON** (moneyphp `jsonSerialize`) — los asserts esperan `'300000'`, no `300000`:

```php
$response->assertJsonPath('totals.expected.amount', '300000')
    ->assertJsonPath('totals.expected.currency', 'MXN');
```

Suites nombradas a actualizar: `StatementEndpointTest.php:83-132`; `ChargeBatchSummaryTest.php` — 4 métodos, no solo el `assertExactJson` de `:83`: también los asserts de enteros por path en `:117-121`, `:142-146` y `:183-185` (expectativas del clamp intactas en minor units); `TreasuryReportControllerTest.php:221-372` (las 3 suites de Treasury requieren proporcionalmente MÁS asserts por path — 5 colecciones anidadas × 3 endpoints); `CollectionsReportControllerTest.php`; `ProjectsReportControllerTest.php`; `ProjectsModuleTest.php:559-563,819-829` + `ProjectsHttpTest.php:456-457` (llaman al SERVICE directo → SOLO renombre de claves, los valores siguen siendo enteros); `CollectionsModuleTest.php`; `CollectionsBatch3Test.php`; `E2E/ClusterLifecycleTest.php:670-674` (llama al ENDPOINT HTTP → renombre de claves Y conversión al shape Money con `amount` string; OJO: la línea `:674` con `disponible` está DENTRO del rango a corregir).

Compuerta de completitud: `rg -n "_minor|minor_units|recaudado|' MXN'" backend/tests backend/Modules/*/tests` → corregir CADA hit hasta que los sobrevivientes sean solo claves internas de cache/compute.

TRAMPA pre-existente (NO tocar, NO "corregir"): las dos superficies de morosidad calculan `outstanding` con fórmulas DISTINTAS — `ReportService::delinquency()` (`ReportService.php:60`, alimenta `reports/delinquency`) usa `expected_amount - paid_amount` plano, mientras `PaymentController::delinquency()` (`:434-443`) usa la fórmula efectiva con clamp `max(0, max(0, expected-discount)+surcharge-paid)`. Divergen cuando hay descuentos/recargos. Es una inconsistencia PRE-EXISTENTE protegida por R-MAPI-14 (cero cambio de lógica): NO escribir asserts de paridad entre ambos endpoints durante la migración de tests.

Runner: php del HOST (NO sail), Postgres `127.0.0.1:5432`, UN SOLO proceso de test a la vez; suites por path primero, luego `php artisan test` completo; `vendor/bin/pint --dirty`.

### 6.3 Frontend (vitest)

- `money.test.ts`: casos existentes + `setActiveCurrency('USD')` → `formatMinorUnits` emite USD; reset a MXN.
- Fixtures de los 5 dashboards (`__tests__`) → shapes M (`{amount: '<string>', currency, formatted}`).
- `pnpm build` (tsc) es la compuerta del clean break: cada lector no migrado de un campo renombrado/retipado es error de compilación.

---

## 7. Secuenciación para apply (single delivery, `size:exception` ya resuelto)

Slices verticales BE+FE+tests, en orden:

1. **Mecanismo core:** `config/money.php` + `TenantMoneyCast` + `TenantMoney` + swap de 11 modelos + borrar cast legacy + `TenantMoneyCastTest`.
2. **Statement + delinquency** (grupo 1-2 de §3) + `StatementEndpointTest`.
3. **Charge-batch summary** (grupo 3) + su suite.
4. **Treasury tableros + public-summary** (grupos 4-5) + suites.
5. **Aging + funding + contribution + reports JSON** (grupos 6-9) + suites (incluye renombre inglés en `ContributionService`).
6. **Grupo C** (formateo §4).
7. **Infra FE:** `currency.ts` + helpers + `SessionTenant.currency` + `sessionStore`.
8. **Consumidores FE** (§5.4) — pueden aterrizar en CUALQUIER orden relativo a los slices BE gracias a la tolerancia de `moneyToMinorUnits` (objeto|int|string).
9. **UI de config + validación** (§5.5).
10. **Docs/regla:** regla 17 en laravel-backend-architecture SKILL.md (§1.1) + engram `backend/money-api-serialization` + auto-memoria.

Mecánica de entrega: SIN worktrees (los submódulos los rompen); commits convencionales por submódulo (`backend`, `frontend`) por slice + bump del pointer en la raíz al final. Rollback = revert de commits + bump del pointer (cero migraciones; caches int-planos legibles por código viejo).

---

## 8. Edge cases y seguridad en runtime

| Caso | Comportamiento diseñado |
|---|---|
| **Octane** | Resolución por llamada (`TenantMoney::currency()` lee tenancy del request actual); los únicos statics del sistema guardan constantes de config (vendor `CurrenciesTrait` default = MXN de config). PROHIBIDO `Money::setDefaultCurrency()` por tenant — static compartido entre requests |
| **Jobs en cola / crons** | `QueueTenancyBootstrapper` re-inicializa tenancy en jobs; los crons por tenant corren con tenancy inicializada → moneda correcta. Contexto genuinamente central → fallback `config('money.defaultCurrency')` = MXN |
| **Super-admin cross-tenant (`X-Tenant-Id`)** | La tenancy se inicializa como el tenant VISTO → casts y `TenantMoney` resuelven la moneda del tenant visualizado (comportamiento correcto por construcción) |
| **Payloads cacheados a través del deploy** | Caches guardan ints planos bajo claves SIN cambio → entradas viejas válidas con código nuevo Y entradas nuevas válidas bajo rollback. Cambio de moneda surte efecto en la siguiente lectura (Money se arma al responder, no al cachear) |
| **`available` negativo** | Money soporta montos negativos; el service regresa el int con signo y `make()` lo envuelve tal cual; el FE decide con `moneyToMinorUnits(...) >= 0` |
| **`amount` es STRING en JSON** | moneyphp `jsonSerialize` emite el amount como string — los asserts backend esperan strings (§6.2) y `moneyToMinorUnits` ya parsea strings (`money.ts:38`) |

---

## 9. Cambios de archivos (resumen)

| Archivo | Acción |
|---|---|
| `backend/config/money.php` | Crear (publish + editar locale/defaultCurrency) |
| `backend/core/src/Casts/TenantMoneyCast.php` | Crear |
| `backend/core/src/Support/TenantMoney.php` | Crear |
| `backend/app/Casts/MoneyCast.php` | Borrar (legacy muerto) |
| 11 modelos de dominio (§2.4) | Modificar (swap de cast) |
| `PaymentController`, `ChargeBatchController`, `TreasuryReportService`, `PublicSummaryController`, `CollectionsReportService`, `ProjectsReportService`, `ContributionService` + `CommunityProjectController`, `ReportController` | Modificar (mapeo en el borde, §3) |
| `ReportFormatter`, `PaymentService`, `ExpenseService`, `SendMonthlyFinancialSummaryCommand`, `ContributionService`, `SendChargeRemindersCommand`, `receipt.blade.php` | Modificar (formateo, §4) |
| `ConfigurationController.php:228` | Modificar (`Rule::in`) |
| `frontend/src/shared/utils/currency.ts` | Crear |
| `money.ts`, `currencyHelper.ts`, `Session.ts`, `sessionStore.ts` | Modificar (§5.1-5.3) |
| Consumidores FE (§5.4) + `OperationalConfigSection.tsx` | Modificar |
| `backend/tests/Feature/Core/TenantMoneyCastTest.php` | Crear |
| ~10 suites BE + `money.test.ts` + fixtures 5 dashboards | Modificar (§6) |
| `.agents/skills/laravel-backend-architecture/SKILL.md` | Modificar (regla 17) |

---

## 10. Registro de decisiones (ADR)

### ADR-1: Subclase `TenantMoneyCast` con override de `getCurrency()` (mecanismo de resolución)
- **Status:** Accepted (cerrada por el usuario).
- **Decisión:** Extender `MoneyIntegerCast` y sobreescribir `protected getCurrency(array $attributes)` — sin return type (espejo exacto de la firma del vendor); passthrough a `parent::` cuando `$this->currency !== null`.
- **Rechazado — bootstrapper `Money::setDefaultCurrency()` por tenant:** el default del vendor es un STATIC de proceso (`CurrenciesTrait`); bajo Octane el proceso sobrevive entre requests → la moneda del tenant A contamina al request del tenant B. Descalificado por construcción.
- **Rechazado — forma `:currency` por atributo para `BankAccount`:** las sumas entre cuentas (`cashflowSeries`, `public-summary`) asumen UNA moneda por tenant; además el `set()` del vendor escribe la columna `currency` como efecto colateral (`MoneyCast.php:86-88`) — mutación no deseada. `bank_account.currency` queda como dato durmiente (documentado fuera de alcance).
- **Rechazado — capa nueva de API Resources:** KISS; la convención del proyecto es el envelope de Orion + `response()->json` para rutas custom (`laravel-architecture.md` §4.5). Una capa de Resources introduciría un segundo patrón de serialización para resolver lo que un cast + un helper resuelven.

### ADR-2: Conversión en el borde de respuesta, DESPUÉS del cache
- **Status:** Accepted.
- **Decisión:** Los caches (`Cache::remember` de statement, `cachedReport()` de los report services) siguen guardando arrays de enteros planos con claves internas actuales; el mapeo int→Money vive en el armado de la respuesta.
- **Rationale:** deploy-safe en ambos sentidos (entradas viejas ↔ código nuevo); un cambio de moneda surte efecto en la siguiente lectura sin flush; payloads de cache más chicos.
- **Rechazado — cachear Money serializado:** entradas viejas incompatibles tras deploy/rollback; `formatted`/`currency` quedarían congelados tras un cambio de moneda hasta expirar el TTL.

### ADR-3: Clean break de contratos (sin dual keys)
- **Status:** Accepted.
- **Decisión:** Eliminar sufijos `_minor`/`_minor_units` y las claves en español en el MISMO cambio; tipos FE `number` → `IMoney`.
- **Rationale:** los submódulos FE+BE se despliegan juntos; `tsc` convierte cada lector olvidado en error de compilación; `moneyToMinorUnits` tolera ambas formas durante la migración interna.
- **Rechazado — dual keys transitorias:** prolongan el patrón roto, duplican payload y nadie las limpia después.

### ADR-4: Moneda activa FE como módulo sin imports (`currency.ts`)
- **Status:** Accepted.
- **Decisión:** Estado a nivel módulo con `set/getActiveCurrency`, escrito por `sessionStore` y leído por los helpers puros.
- **Rationale:** `formatMinorUnits`/`displayFormatCurrency` son funciones puras llamadas también fuera del árbol React; cero imports evita ciclos; el locale queda `es-MX` (UI en español por regla del proyecto).
- **Rechazado — Context/Zustand para la moneda:** acopla helpers puros al runtime de React y no aporta reactividad necesaria (el cambio de moneda ya fuerza `invalidateQueries` + re-render por sesión).

### ADR-5: Pipeline de Reports queda int-plano por dentro
- **Status:** Accepted.
- **Decisión:** `ReportService`/`ReportRegistry`/`ReportFormatter` siguen operando enteros crudos; SOLO los 3 bordes JSON de `ReportController` envuelven; `generate(format=json)` queda raw documentado.
- **Rationale:** los exporters PDF/CSV/XLSX consumen los mismos datasets; convertir dentro del pipeline duplicaría conversiones y rompería la paridad JSON↔export.

---

## Preguntas abiertas

Ninguna — las decisiones de alcance y mecanismo están cerradas por el usuario; los puntos de verificación en vivo restantes (números de línea que puedan derivar tras otros merges) se re-anclan en apply con los identificadores de método aquí documentados.
