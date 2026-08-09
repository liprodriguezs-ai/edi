---
name: billing-slice2-patterns
description: Patrones y desviaciones del Billing Slice 2 (multi-item checkout + webhook entitlements)
metadata:
  type: project
---

## Patrón establecido: controllers no-Orion de Billing extienden `Illuminate\Routing\Controller`

`BillingCatalogController`, `BillingQuoteController` y ahora `BillingCheckoutController` (Slice 2) extienden `Illuminate\Routing\Controller` directamente, no el `QCH\Core\Http\Controllers\Controller` del proyecto. Es una desviación consolidada del módulo SubscriptionPlan para rutas puras custom (no Orion). No introducir más controllers con este patrón en otros módulos — solo billing ya lo usa.

**Why:** las Billing routes son custom (no resource Orion) y el equipo eligió el routing controller liviano.

**How to apply:** si ves un controller de billing solo extendiendo `Illuminate\Routing\Controller`, no es un bug nuevo — es el patrón histórico del módulo. Si lo ves en OTRO módulo, sí es CRITICAL.

## Stripe basil API: `current_period_end` por ITEM

El listener (Slice 2 / B2) correctamente lee `item['current_period_end']` (vía `itemPeriodEnd()`), no el campo raíz del Subscription que fue removido en basil. Ver [[cashier-stripe-api-version]].

## Patrón de idempotencia de webhook: 4-day cache TTL

`Cache::add('stripe:evt:'.$eventId, true, now()->addDays(4))` — la ventana es 4 días porque Stripe reintenta hasta ~3 días. Anterior (Slice 1) era 1 día. Correcto.

## N+1 conocido en `packageForStripePrice()`

`StripeWebhookListener::packageForStripePrice()` hace una query por `stripe_price_id` y se llama en loop dentro de `grantEntitlementsFromItems` y `reconcileSubscriptionEntitlements` (doble llamada por item en reconcile). Para 1-3 items en webhook async es aceptable; pero si el catálogo de packages crece, habría que precargar con `whereIn`.

## Transacciones anidadas en `EntitlementService::grant()`

`grant()` abre `DB::transaction()` e internamente llama `recompute()` que abre otro `DB::transaction()`. En PostgreSQL esto genera SAVEPOINTs. Si `recompute()` falla, la excepción propaga y la transacción externa también rollbackea. No hay riesgo de estado orphan — verificado en revisión de Slice 2.

## Owner gate en BillingCheckoutController

El gate de "solo el account_owner puede iniciar checkout" se implementa manualmente: lookup del owner por rol + comparación de IDs (`$owner->id !== $user->id`). Si el tenant tiene 0 o >1 owners → 422 (no 403). Es el mismo patrón que `ConfigurationController`. No usar `tokenCan` aquí porque la restricción es de PERSONA, no de ability.
