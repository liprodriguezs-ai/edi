# Signup: plan obligatorio + tarjeta + trial de 30 días en Stripe (2026-08-07)

Auditoría de costo del cambio sin commitear (backend + frontend + landing).

## Presupuesto de queries de `EnsureSubscriptionActive` (middleware GLOBAL del grupo `api`)

Medido leyendo el árbol de trabajo, no impresiones:

- **Allowlist ANTES de auth** (`api/auth/*`, `api/billing/*`, `api/config/subscription*`,
  `api/onboarding*`, `api/app-config`, `api/stripe/*`, `api/track`) → 0 queries. Es el
  corto circuito clave: toda la pantalla /select-plan cae acá.
- Request autenticado NO-allowlisted, dueño que paga: **3 queries**
  1. `hasRole()` en `ActiveTenantResolver` (carga la relación de roles de Spatie),
  2. `$user->tenants()->get()` en el resolver,
  3. `TenantEntitlement ... ->active()->get()`.
  El `hasRole()` de `forUser`/`forTenant` reusa la relación ya cargada → 0 extra.
  `activeEntitlementState()` es 100% en memoria → 0 extra. **Un dueño que paga NO paga
  ninguna query adicional por el cambio.**
- Rama bloqueada: +1 (`everHadEntitlement exists`) y +1 más para `payment_issue`, pero
  el `&&` de PHP corta antes: un registrante fresco tiene `everHadEntitlement=false` →
  nunca llega a la 3ª. Buen diseño, no marcar.

**`ownerHasTrialingStripeSubscription()` NO EXISTE** (fue reemplazado por el marcador
en metadata antes de esta auditoría). No re-auditarlo como si existiera.

## Duplicado PRE-EXISTENTE: `ActiveTenantResolver::resolve()` corre 2× por request

`EnsureSubscriptionActive` (grupo `api` global) y `InitializeTenancyFromUser` (por ruta)
lo llaman cada uno; `resolve()` no memoiza y `$user->tenants()->get()` es query fresca →
**1 SELECT duplicado sobre `user_tenant`⋈`tenant` en CADA request autenticado, de todo
tenant**. No lo introdujo este cambio. Fix Octane-safe: memoizar en
`$request->attributes` (NO en la instancia del servicio — los singletons persisten entre
requests en Octane).

## `stripe_trialing`: lectura sin escritura (bug funcional, no de costo)

`SubscriptionStateService::isTrialLike()` lee `metadata['stripe_trialing']`, pero NADIE
lo escribe (grep: 3 hits, todos lecturas/doc en el mismo archivo). La LIMPIEZA sí está
(`handleInvoicePaymentSucceeded` hace `update(['expires_at'=>..., 'metadata'=>null])`).
Probado: `SubscriptionStateAccessTest::test_owner_in_a_live_stripe_trial...` FALLA
(`'trial'` esperado, `'active'` obtenido). Fix de 1 línea en
`grantEntitlementsFromItems`: `'metadata' => $isTrialing ? ['stripe_trialing'=>true] : null`.

**El riesgo de "queda marcado en trial para siempre" que preocupaba está estructuralmente
resuelto**: `EntitlementService::grant()` PISA `metadata` entera en cada upsert
(`$metadata !== null ? json_encode(...) : null`), así que cualquier grant/reconcile
posterior de una suscripción no-trialing la limpia sola. Único hueco: la limpieza de
`handleInvoicePaymentSucceeded` va colgada del update monotónico
(`whereNotNull('expires_at')->where('expires_at','<',$periodEnd)`) — si la factura llega
con un periodEnd igual/anterior o nulo, no limpia. Cubierto igual por el reconcile de
`customer.subscription.updated`.

## Limpio — NO re-marcar

- `PlanPricingService` `is_purchasable`: puro en memoria sobre `$pricesByPlan` ya
  agrupada, DENTRO del build cacheado (`billing.plan_catalog`, TTL 300 s). **Cero
  queries extra, cero impacto en la caché.** `SyncBillingStripeCommand` ahora hace
  `Cache::forget` de esa clave (arregla staleness). El schema del front lo declara
  `optional()` y `isPurchasablePlan` trata ausente como `true` → la ventana de rollout
  con catálogo cacheado viejo no rompe checkouts.
- `VerifyBillingCatalogCommand`: 1 query; el `with([...])` no dispara nada cuando el
  set viene vacío (Eloquent salta el eager load). Comando manual/cron.
- Sondeo de `SuccessView` (SelectPlanPage): `setInterval` 3 s, `MAX_ATTEMPTS=20`,
  `clearInterval` en éxito, en tope y en unmount; "Reintentar" resetea `attemptsRef` y
  requiere clic humano. **Acotado y frena bien.**
- `AuthController::register` BORRÓ `grantOnboardingTrial()` → el registro es más barato.
  `useSession.register` borró un `trackEvent('StartTrial')` → un POST /track menos.
- Landing: solo copy estático (Astro). Costo cero.
- `assertNoLiveStripeClobber` (+1 query) y `tenantEverHadEntitlement` (+1 query): rutas
  de super-admin / checkout, frecuencia despreciable.

## Abierto (SUGGESTION)

- `POST /billing/plan-quote` corre **~7 queries sin caché** (BillingPackage, isContactSales
  exists, pricedModuleKeys, BillableModule is_base, BillableModule whereIn, planModuleFlags,
  y `activePrice()` DENTRO del foreach → N+1 por módulo). Público, `throttle:60,1` por IP.
  Ya estaba (ver `billing-public-endpoints.md`), pero ahora **todo registro pasa
  obligatoriamente por /select-plan**, así que el volumen de esta ruta sube mucho. El
  catálogo cacheado ya tiene todos esos datos.
- El sondeo pega a `/auth/me`, que es gordo (~una docena de queries: getUser + 3 queries de
  tenants con eager loads + permisos + roles + estado de suscripción + terms). Hasta 20×
  por alta. Un endpoint flaco de confirmación (`GET /billing/subscription-state`, ~2
  queries) bajaría eso ~6×.
