---
name: billing-slice6-patterns
description: Billing Slice 6 (grant-free/revoke + preferential-swap) — patrones validados, gaps de atomicidad, race condition única de entitlement. Review 2026-06-30.
metadata:
  type: project
---

Revisado 2026-06-30. Controllers, service, seeder y tests para los endpoints de super-admin: activar/revocar paquete free (parte A) + swap al paquete preferencial oculto (parte B). Builds on [[billing-slice3-manual-ledger]] y [[billing-slice2-patterns]].

**Patrones validados (compliant)**
- Controllers extienden `Illuminate\Routing\Controller` — patrón billing confirmado (no-Orion).
- Gate `tokenCan('update.tenant') || tokenCan('*')` — espejo exacto de `SubscriptionPaymentController`.
- Rutas sin `initializeTenancy` — correcto (central, cross-tenant super-admin).
- Parity `proration_date`: preview lee `$invoice->subscriptionProrationDate()`, apply recibe el int y lo pasa a `swap(['proration_date' => ...])`. No inyectar en `previewInvoice()` (clobbearía `subscription_details` en Cashier). Correcto.
- `applyManual()` correctamente envuelto en `DB::transaction()` con savepoints anidados.
- `hasIncompletePayment` guard presente antes del swap CARD.
- Seeder idempotente via `updateOrCreate`; `preferentialBands()` hace `unset($band['proposed'])` — correcto.

**MEDIUM 1 — revoke(): update + recompute no atómicos** (`TenantEntitlementController::revoke()`)
`$entitlement->update(['expires_at' => now()])` se commitea antes de que `recompute()` abra su transacción. Si recompute lanza, el entitlement está expirado pero `module_tenant` sigue habilitado. Fix: envolver ambas en `DB::transaction` (igual que `applyManual()`).

**MEDIUM 2 — applyCard(): update + recompute no atómicos** (`PreferentialSwapService::applyCard()`)
`$from->update(['billing_package_id' => $toPackage->id])` + `recompute()` están separados. Si recompute falla, el entitlement apunta al paquete preferencial pero los módulos no se actualizan. Fix: envolver ambas en `DB::transaction` DESPUÉS del `$subscription->swap()`.

**MEDIUM 3 — applyCard(): race condition unique constraint** (`PreferentialSwapService::applyCard()`)
`tenant_entitlement` tiene unique(`tenant_id`, `billing_package_id`). Si el webhook de Stripe llega y es procesado ANTES de que `$from->update(['billing_package_id' => toPackage.id])` corra, el webhook crea la fila `(tenant_id, base_preferential.id)` y luego el update del controller choca contra esa unique → PostgreSQL lanza error 500. Fix: reemplazar `$from->update()` con el patrón expire + `grant()` (upsert) del `applyManual()`, que es idempotente frente a la webhook race.

**WARNING — DRY**: `PreferentialSwapController::activeSubscriptionForTenant()` duplica `SubscriptionPaymentController::accountOwnerForTenant()`. Extraer a trait `ResolvesAccountOwner` o servicio `AccountOwnerResolver`.

**SUGGESTION — mapLines()**: usa `mixed $invoice` en vez de `?Invoice`. Sin impacto funcional (cast correcto).
