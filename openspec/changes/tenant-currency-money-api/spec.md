# Spec — Tenant Currency & Money API Serialization

- **Change:** `tenant-currency-money-api`
- **Project:** `residia-monorepo` (Kivo7)
- **Phase:** spec
- **Source of truth:** formaliza el contrato de capacidades de la propuesta (`openspec/changes/tenant-currency-money-api/proposal.md` §3) y la síntesis de exploración aprobada (engram `sdd/tenant-currency-money-api/explore`). No re-abre decisiones de alcance ya cerradas.

## Purpose

Ninguna de las dos capacidades de este cambio tiene entrada previa en `openspec/specs/` — ambas son nuevas. Este documento formaliza: (1) **`money-api-serialization`** — el dinero cruza la frontera del API únicamente como objeto Money serializado, nunca como entero crudo en minor units, a través de los 8 grupos de endpoints donde hoy esto se rompe; y (2) **`tenant-currency`** — la moneda se resuelve de punta a punta desde `tenant.currency` (con fallback central seguro), es seleccionable por el admin del clúster, y el frontend formatea con la moneda activa de la sesión. Ningún requisito de este documento cambia lógica de negocio (fórmula de outstanding, FIFO, FSM, clamps SQL quedan byte a byte iguales) ni requiere migraciones de BD (`tenant.currency` ya existe).

---

## Capability: money-api-serialization

Dinero cruza la frontera del API únicamente como objeto Money serializado `{amount, currency, formatted}` — el mismo shape que ya producen los modelos hidratados vía `MoneyIntegerCast`/`ArrayMoneySerializer`. Los enteros crudos en minor units quedan confinados a la capa de servicio/cache; la conversión ocurre al armar la respuesta. Counts, porcentajes, labels y fechas NO se envuelven — siguen siendo valores planos.

## ADDED Requirements

### Requirement: El dinero serializa como objeto Money en cualquier borde del API (R-MAPI-1)

Todo valor monetario devuelto por un endpoint del API (JSON) DEBE serializar como un objeto `{amount: string, currency: string, formatted: string}`. Un entero crudo en minor units (p. ej. `150000`) NUNCA DEBE aparecer como valor de un campo de dinero en la respuesta de un endpoint. Los campos que NO representan dinero (conteos, porcentajes, IDs, fechas, labels) NO se ven afectados — siguen siendo el tipo plano que ya son hoy.

#### Scenario: Un campo de dinero agregado devuelve el objeto Money

- GIVEN un tenant tiene cargos por $1,500.00 MXN
- WHEN se llama cualquier endpoint agregado que reporte ese monto
- THEN el campo de dinero correspondiente es `{"amount": "150000", "currency": "MXN", "formatted": "$1,500.00"}`, no el entero `150000` ni un string ad-hoc

#### Scenario: Conteos y porcentajes no se envuelven

- GIVEN un endpoint agregado devuelve un conteo de unidades con adeudo y un porcentaje de cobranza
- WHEN se llama el endpoint
- THEN esos campos siguen siendo valores numéricos planos (enteros), no objetos Money

---

### Requirement: Contrato del estado de cuenta — `GET /unit/{id}/statement` (R-MAPI-2)

El campo `credit_balance` y todos los campos de `totals` (`expected`, `discount`, `surcharge`, `paid`, `outstanding`) DEBEN serializar como objeto Money. Los sufijos `_minor_units` DESAPARECEN de las claves. El array `charges[]` (ya serializado correctamente vía casts de modelo) y el objeto `totals` DEBEN compartir el mismo shape de Money — ya no puede existir un payload que mezcle objetos Money con enteros crudos en la misma respuesta.

#### Scenario: totals y charges comparten el mismo shape de dinero

- GIVEN una unidad tiene cargos parcialmente pagados y un saldo a favor
- WHEN se llama `GET /unit/{id}/statement`
- THEN cada entrada de `charges[]` y cada campo de `totals` (incluido `credit_balance`) es un objeto `{amount, currency, formatted}`
- AND ninguna clave del payload conserva el sufijo `_minor_units`

#### Scenario: totals.surcharge está presente y tipado como dinero

- GIVEN una unidad tiene un cargo con recargo aplicado
- WHEN se llama `GET /unit/{id}/statement`
- THEN `totals.surcharge` está presente en la respuesta como objeto Money (cierra el drift donde el tipo del frontend omitía este campo)

---

### Requirement: Contrato de morosidad — endpoint delinquency (R-MAPI-3)

El campo `outstanding` por unidad y `total_outstanding` a nivel de respuesta DEBEN serializar como objeto Money. El criterio de ordenamiento (sort) y la suma total se calculan sobre los enteros ANTES de envolver el resultado — el criterio de clasificación no cambia.

#### Scenario: outstanding por unidad y el total general son objetos Money

- GIVEN 3 unidades con distintos montos vencidos
- WHEN se llama el endpoint de morosidad
- THEN cada entrada devuelve `outstanding` como objeto Money y la respuesta expone `total_outstanding` como objeto Money
- AND el orden de las unidades en la respuesta es idéntico al que producía la suma en enteros antes del cambio

---

### Requirement: Contrato de resumen de lote de cobro — `charge-batch/{id}/summary` (R-MAPI-4)

Los valores de dinero del resumen del lote DEBEN serializar como objeto Money; las claves NO cambian de nombre. El cálculo SQL subyacente (incluido el clamp `SUM(CASE WHEN...)`) DEBE permanecer intacto — el cambio es solo de serialización en el borde de respuesta, nunca de la consulta.

#### Scenario: El resumen del lote expone dinero como objeto sin renombrar claves

- GIVEN un lote de cobro con cargos parcialmente pagados y algunos cancelados
- WHEN se llama `GET /charge-batch/{id}/summary`
- THEN cada clave de dinero conserva su nombre original pero su valor es un objeto Money
- AND el monto total calculado es idéntico (en minor units) al que el SQL con clamp producía antes del cambio

---

### Requirement: Contrato de los tableros de Tesorería (R-MAPI-5)

Los 3 endpoints de tableros de Tesorería (`treasury/income-expense-series`, `treasury/cashflow-series`, `treasury/expenses-breakdown`) DEBEN eliminar el sufijo `_minor` de sus claves de dinero y serializar esos valores como objeto Money: `series[].{income,expense,net}`, `accounts[].current_balance`, `series[].{inflow,outflow,running_balance}`, `totals.*`, `by_category[].amount`, `by_provider[].amount`, `monthly[].amount`.

#### Scenario: income-expense-series expone net como Money

- GIVEN un mes con ingresos y egresos calculados
- WHEN se llama `GET /treasury/income-expense-series`
- THEN cada entrada de `series` expone `income`, `expense` y `net` como objetos Money, sin sufijo `_minor`
- AND el valor de `net` en minor units sigue siendo `income - expense`, igual que antes del cambio

#### Scenario: cashflow-series expone running_balance acumulado como Money

- GIVEN dos meses consecutivos con flujos de entrada y salida
- WHEN se llama `GET /treasury/cashflow-series`
- THEN `running_balance` de cada mes es un objeto Money cuyo `amount` en minor units es idéntico al cálculo acumulado previo al cambio

#### Scenario: expenses-breakdown expone montos por categoría y proveedor como Money

- GIVEN egresos distribuidos en varias categorías y proveedores
- WHEN se llama `GET /treasury/expenses-breakdown`
- THEN `by_category[].amount`, `by_provider[].amount` y `monthly[].amount` son objetos Money
- AND `pct` en `by_category` sigue siendo un entero plano (no se envuelve)

---

### Requirement: Contrato del resumen público de tesorería — `treasury/public-summary` (R-MAPI-6)

Las claves del resumen público ya son correctas (sin sufijo) y NO cambian de nombre; sus valores (`total_income`, `total_expenses`, `net_balance`, `expenses_by_category[].total`, `bank_account_balances[].current_balance`) DEBEN serializar como objeto Money.

#### Scenario: El resumen público expone dinero como objeto Money

- GIVEN un tenant con ingresos, egresos y cuentas bancarias
- WHEN se llama `GET /treasury/public-summary` (endpoint público, sin autenticación)
- THEN `total_income`, `total_expenses`, `net_balance` y cada entrada de `expenses_by_category`/`bank_account_balances` exponen su monto como objeto Money

---

### Requirement: Contrato de antigüedad de cartera — `collections/aging` (R-MAPI-7)

Los campos `totals.total_outstanding`, cada bucket de `buckets` (`current`, `d1_30`, `d31_60`, `d61_90`, `d90_plus`), `by_status[].amount` y `top_debtors[].outstanding` DEBEN serializar como objeto Money, eliminando el sufijo `_minor`.

#### Scenario: Los buckets de antigüedad exponen Money sin perder la clasificación

- GIVEN cargos vencidos distribuidos en distintos rangos de antigüedad
- WHEN se llama `GET /collections/aging`
- THEN cada bucket (`current`…`d90_plus`) es un objeto Money
- AND el cargo cae en el mismo bucket que antes del cambio (la clasificación por antigüedad no se altera)

---

### Requirement: Contrato de financiamiento de proyectos — `projects/funding-summary` (R-MAPI-8)

`projects[].{goal,raised}` y `totals.{goal,raised}` DEBEN serializar como objeto Money.

#### Scenario: goal y raised de cada proyecto son objetos Money

- GIVEN un proyecto comunitario con meta y aportaciones parciales
- WHEN se llama `GET /projects/funding-summary`
- THEN `goal` y `raised` de ese proyecto (y los mismos campos en `totals`) son objetos Money
- AND `pct` sigue siendo un entero plano 0–100

---

### Requirement: Contrato del resumen de aportaciones — `community-project/{id}/summary` (R-MAPI-9)

Las claves en español `recaudado`, `ejecutado`, `disponible` DEJAN de existir en la respuesta del API — se renombran a `raised`, `executed`, `available` (regla HARD de identificadores en inglés). Los tres campos DEBEN serializar como objeto Money. `available` PUEDE ser un monto negativo (cuando lo ejecutado excede lo recaudado) — el objeto Money DEBE soportarlo sin error.

#### Scenario: Las claves salen en inglés

- GIVEN un proyecto comunitario con aportaciones registradas
- WHEN se llama `GET /community-project/{id}/summary`
- THEN la respuesta contiene `raised`, `executed` y `available` como objetos Money
- AND NINGUNA clave `recaudado`, `ejecutado` o `disponible` aparece en el payload

#### Scenario: available negativo no produce error

- GIVEN un proyecto donde lo ejecutado supera lo recaudado
- WHEN se llama `GET /community-project/{id}/summary`
- THEN `available` es un objeto Money cuyo `amount` es un valor negativo, y la respuesta es 200 sin error de serialización

---

### Requirement: Contrato de los bordes JSON de Reportes (R-MAPI-10)

Solo los bordes JSON de `ReportController` cambian: `reports/delinquency` (`total_overdue_amount` en ambos niveles), `reports/income-statement` (`total_income`, `total_expenses`, `net`) y `reports/collection-progress` (`total_expected`, `total_collected`) DEBEN serializar sus totales como objeto Money. `ReportService`, `ReportRegistry` y `ReportFormatter` SIGUEN operando internamente con enteros crudos en minor units (alimentan el pipeline PDF/CSV/XLSX) — la conversión ocurre EXCLUSIVAMENTE en el borde de `ReportController` cuando arma el JSON.

#### Scenario: Los totales de los 3 reportes JSON son objetos Money

- GIVEN datos de morosidad, estado de resultados y avance de cobranza para un tenant
- WHEN se llaman `reports/delinquency`, `reports/income-statement` y `reports/collection-progress`
- THEN los totales mencionados en cada uno son objetos Money
- AND el PDF/CSV/XLSX generado para el mismo reporte muestra montos idénticos a los que mostraba antes del cambio (el pipeline de exportación no se tocó)

#### Scenario: account-balances y el export JSON quedan sin cambio

- GIVEN un tenant con cuentas bancarias
- WHEN se llama `reports/account-balances`
- THEN la respuesta no cambia (ya proviene de modelos hidratados con Money vía cast)
- AND cuando `generate` se llama con `format=json`, los montos siguen siendo enteros crudos en minor units (paridad intencional con los formatos de exportación)

---

### Requirement: Los caches internos conservan enteros planos (R-MAPI-11)

Cualquier valor monetario persistido en cache DEBE seguir siendo un entero plano en minor units, bajo las mismas claves internas que usa hoy. La conversión a objeto Money ocurre EXCLUSIVAMENTE al armar la respuesta del endpoint, después de leer el cache (`cachedReport()`/`Cache::remember` o equivalente) — nunca antes de persistir en cache.

#### Scenario: Un valor cacheado antes del deploy se sigue leyendo correctamente

- GIVEN una entrada de cache fue escrita ANTES de este cambio, con un entero plano
- WHEN el código posterior al cambio lee esa misma entrada de cache
- THEN el valor se interpreta correctamente como entero plano y se envuelve en Money al momento de responder — no hay error de deserialización ni necesidad de invalidar el cache en el deploy

#### Scenario: Un cambio de moneda del tenant surte efecto en la siguiente lectura de cache

- GIVEN un tenant cambia su moneda de MXN a USD
- AND existe una entrada de cache escrita mientras la moneda era MXN
- WHEN se solicita el mismo reporte después del cambio de moneda
- THEN el valor entero cacheado se envuelve con la NUEVA moneda al momento de responder (la conversión post-cache siempre usa la moneda vigente al momento de la lectura)

---

### Requirement: Los strings de notificaciones/PDF/CSV usan un formateador centralizado (R-MAPI-12)

Todo string de dinero embebido en una notificación, PDF o recibo (fuera de los objetos Money serializados del JSON) DEBE producirse a través de un único punto de formateo centralizado (no debe existir más de una función que reconstruya manualmente un string de dinero). El patrón `'$'.number_format($raw/100,2).' MXN'` (u otro equivalente hardcodeado a MXN) NO DEBE existir en ningún punto del código tras el cambio. Los encabezados `(MXN)` hardcodeados en tablas de reportes DEBEN calcularse dinámicamente a partir de la moneda vigente.

#### Scenario: Un recibo de pago muestra el monto formateado vía el punto centralizado

- GIVEN un pago confirmado para un tenant con moneda USD
- WHEN se genera el recibo (PDF) o la notificación de confirmación de pago
- THEN el monto se muestra formateado en USD, producido por el único punto de formateo centralizado — no por una reconstrucción manual de string

#### Scenario: No sobreviven strings de dinero hardcodeados a MXN

- GIVEN el código fuente completo del backend tras el cambio
- WHEN se busca el patrón `'$'.number_format(...).' MXN'` (o equivalente hardcodeado)
- THEN no se encuentra ninguna ocurrencia fuera del punto de formateo centralizado

#### Scenario: Un encabezado de reporte muestra la moneda vigente, no MXN fijo

- GIVEN un tenant con moneda `USD` genera un reporte tabular
- WHEN el reporte arma el encabezado de la columna de dinero
- THEN el encabezado muestra `(USD)`, calculado a partir de la moneda vigente del tenant, no el literal `(MXN)`

---

### Requirement: El billing de plataforma queda excluido deliberadamente (R-MAPI-13)

El payload de facturación de plataforma (`TenantBillingController` y todo lo relacionado con `SubscriptionPlan`/Stripe) NO se ve afectado por este cambio: sigue serializando montos como enteros (`integer` cast) en MXN. Ningún modelo de `SubscriptionPlan` usa el cast tenant-aware de dinero.

#### Scenario: El billing de plataforma sigue devolviendo enteros

- GIVEN un tenant con una suscripción activa
- WHEN se llama cualquier endpoint de `TenantBillingController`
- THEN los montos siguen siendo enteros planos en MXN, sin cambio de shape

---

### Requirement: Cero cambio de lógica de negocio (R-MAPI-14)

Este cambio es exclusivamente de serialización y resolución de moneda. La fórmula de `outstanding` (expected efectivo + recargo, con clamp por fila), el algoritmo FIFO de aplicación de pagos, las transiciones de la máquina de estados de cargos (FSM) y cualquier cláusula SQL de agregación (incluidos los clamps `SUM(CASE WHEN...)`) DEBEN producir el mismo resultado en minor units, byte a byte, que antes del cambio.

#### Scenario: Los montos en minor units no cambian antes/después

- GIVEN un conjunto de datos de prueba con cargos, pagos parciales y recargos
- WHEN se comparan los valores en minor units devueltos por cada endpoint agregado ANTES y DESPUÉS del cambio
- THEN los valores numéricos (una vez extraídos del objeto Money) son idénticos — solo cambia el shape de serialización

---

## Capability: tenant-currency

La moneda se resuelve por tenant (`tenant.currency`, con fallback central a `config('money.defaultCurrency')`), es seleccionable (MXN/USD) desde la configuración operacional con confirmación explícita, y el frontend formatea con la moneda activa de la sesión. La resolución es por-llamada (nunca un default estático mutado) para ser segura bajo Octane/colas.

## ADDED Requirements

### Requirement: Orden de resolución de moneda (R-TCUR-1)

La moneda vigente para cualquier operación de dinero DEBE resolverse en este orden: (1) `tenant()->currency` cuando la tenancy está inicializada; (2) `config('money.defaultCurrency')` (MXN) en contexto central (sin tenancy inicializada, p. ej. comandos CLI). Si el valor almacenado en `tenant.currency` no es un código de moneda válido, el sistema DEBE recurrir de forma segura al default de config en lugar de fallar.

#### Scenario: Contexto de tenant resuelve su propia moneda

- GIVEN un tenant tiene `currency = 'USD'`
- WHEN se resuelve la moneda dentro de una request con tenancy inicializada para ese tenant
- THEN la moneda resuelta es `USD`

#### Scenario: Contexto central cae al default de config

- GIVEN un comando de consola corre sin tenancy inicializada
- WHEN se resuelve la moneda en ese contexto
- THEN la moneda resuelta es el valor de `config('money.defaultCurrency')` (`MXN`)

#### Scenario: Un código de moneda inválido no rompe la resolución

- GIVEN un tenant tiene almacenado un valor de `currency` que no es un código ISO válido
- WHEN se resuelve la moneda para ese tenant
- THEN el sistema recurre al default de config en lugar de lanzar una excepción

---

### Requirement: Los 11 modelos de dinero del dominio resuelven moneda por tenant (R-TCUR-2)

Los 11 modelos de dinero del dominio (Collections: `Charge`, `Payment`, `PaymentApplication`, `CreditBalance`, `ChargePolicy`; Treasury: `BankAccount`, `Expense`; Providers: `Provider`; Projects: `ProjectContribution`, `CommunityProject`; Collaborator: `Collaborator.base_salary`) DEBEN resolver la moneda del tenant vigente en cada lectura/escritura — ninguno DEBE tener un parámetro de moneda hardcodeado (p. ej. `:MXN`). `BankAccount` DEBE resolver la moneda por tenant de la MISMA manera que los demás modelos (nunca vía la forma de columna `:currency` por atributo del cast del vendor), porque las sumas entre cuentas del mismo tenant asumen una única moneda; la columna `bank_account.currency` queda como dato durmiente y NO debe influir en la moneda serializada.

#### Scenario: Un modelo de dinero serializa con la moneda del tenant activo

- GIVEN un tenant con `currency = 'USD'` tiene un `Charge` con `expected_amount = 150000`
- WHEN se serializa ese `Charge`
- THEN el campo de dinero expone `currency: "USD"`, no `MXN`

#### Scenario: Dos tenants con distinta moneda serializan el mismo entero distinto

- GIVEN el tenant A tiene `currency = 'MXN'` y el tenant B tiene `currency = 'USD'`, y ambos tienen un registro de dinero con el mismo valor entero en minor units
- WHEN se serializa el registro de cada tenant dentro de su propio contexto de tenancy
- THEN el registro del tenant A expone `currency: "MXN"` y el del tenant B expone `currency: "USD"`, con el mismo `amount`

#### Scenario: La columna durmiente de BankAccount no afecta la moneda serializada

- GIVEN un `bank_account` tiene un valor almacenado en su columna `currency` que difiere de `tenant.currency`
- WHEN se serializa un campo de dinero de esa cuenta (p. ej. `current_balance`)
- THEN la moneda expuesta es `tenant.currency`, no el valor de la columna `bank_account.currency`

---

### Requirement: Selección de moneda en configuración operacional (R-TCUR-3)

El admin del clúster DEBE poder elegir la moneda del tenant entre una lista curada (`MXN`, `USD`) desde la configuración operacional. La actualización DEBE requerir una confirmación explícita (`confirmed: true`) en la petición, dejando claro que los montos existentes se REINTERPRETAN en la nueva moneda, NUNCA se convierten aritméticamente. El backend DEBE validar el valor recibido contra la lista curada — cualquier otro valor es rechazado.

#### Scenario: Cambiar la moneda del tenant persiste sin convertir montos

- GIVEN un tenant tiene `currency = 'MXN'` y un cargo con `expected_amount = 150000`
- WHEN el admin actualiza la configuración operacional con `currency: 'USD', confirmed: true`
- THEN `tenant.currency` pasa a `USD`
- AND el cargo conserva el mismo entero `150000` en minor units (se reinterpreta como USD, no se recalcula)

#### Scenario: Un valor de moneda fuera de la lista curada es rechazado

- GIVEN un admin intenta actualizar la configuración operacional con `currency: 'EUR'`
- WHEN se envía la petición
- THEN la petición es rechazada (422) y `tenant.currency` no cambia

#### Scenario: La confirmación es obligatoria para cambiar de moneda

- GIVEN un admin envía `currency: 'USD'` sin `confirmed: true`
- WHEN se procesa la petición
- THEN el cambio de moneda no se aplica

---

### Requirement: El frontend formatea con la moneda activa de la sesión (R-TCUR-4)

La sesión del usuario DEBE transportar `tenant.currency`. El frontend DEBE usar esa moneda activa (no un valor hardcodeado) para formatear cualquier monto de dinero mostrado en la interfaz. El locale de formateo permanece `es-MX` (la UI sigue en español) independientemente de la moneda activa.

#### Scenario: La sesión expone la moneda del tenant

- GIVEN un usuario inicia sesión en un tenant con `currency = 'USD'`
- WHEN se consulta el perfil de sesión (login, refresh o `/me`)
- THEN el objeto de tenant en la sesión incluye `currency: "USD"`

#### Scenario: Un monto se formatea con la moneda activa, no con MXN fijo

- GIVEN la sesión activa tiene la moneda `USD`
- WHEN la interfaz formatea un monto de dinero para mostrarlo
- THEN el resultado usa el símbolo/código de USD, no MXN, manteniendo el locale `es-MX`

---

### Requirement: Un cambio de moneda se refleja de inmediato en la UI (R-TCUR-5)

Tras guardar un cambio de moneda exitoso en configuración operacional, la sesión activa y las vistas que muestran dinero (estado de cuenta, tableros, previews de reportes) DEBEN reflejar la nueva moneda sin requerir un refresco manual de página ni un re-login. Dado que los caches internos guardan enteros planos (R-MAPI-11), basta con invalidar las consultas en curso para que la siguiente lectura muestre el `formatted` correcto.

#### Scenario: Cambiar la moneda actualiza los tableros sin recargar la página

- GIVEN un admin cambia la moneda del tenant de MXN a USD desde configuración
- WHEN el cambio se guarda exitosamente
- THEN el estado de cuenta, los tableros y los previews de reportes ya abiertos muestran montos en USD tras la siguiente actualización de datos, sin necesidad de recargar la página completa

---

### Requirement: Resolución segura bajo Octane y colas (R-TCUR-6)

La resolución de moneda DEBE ser por-llamada, leyendo el estado de tenancy vigente en cada invocación — NUNCA debe mutarse un valor de moneda por defecto a nivel estático/proceso (p. ej. `Money::setDefaultCurrency()`). Los jobs en cola y los crons por tenant DEBEN resolver la moneda a través de la tenancy ya inicializada para ese job/cron; en ausencia de tenancy inicializada, el contexto cae al default central.

#### Scenario: Dos requests concurrentes de distintos tenants no se contaminan

- GIVEN el tenant A (MXN) y el tenant B (USD) hacen requests concurrentes bajo el mismo proceso Octane
- WHEN cada request resuelve su moneda
- THEN el tenant A obtiene `MXN` y el tenant B obtiene `USD`, sin importar el orden de ejecución ni el estado dejado por la request anterior

#### Scenario: Un job en cola resuelve la moneda del tenant que lo encoló

- GIVEN un job encolado para el tenant B (USD) necesita formatear un monto de dinero
- WHEN el job se ejecuta (tenancy reinicializada para ese job)
- THEN el monto se formatea en USD, la moneda del tenant que originó el job
