---
name: notification-wiring-patterns
description: Patrones establecidos en el sistema de notificaciones de Kivo7 (Phases 1-5) — canales, dispatcher, job de entrega, trait NotifiesResidents, seguridad transaccional
metadata:
  type: project
---

El sistema de notificaciones usa una arquitectura de 4 capas:

1. **Canal** (`app/Notifications/Channels/`) — implementa `NotificationChannelInterface::send()`. BladeEmailChannel y WhatsAppChannel agregados en Phase 2. **Cambio 6 (Phase 5):** canales I/O PROPAGAN excepciones (no swallow) para que el job pueda reintentar. Canales `log` y `whatsapp` (placeholder) son la excepción — no tienen I/O real y no lanzan. `ResendEmailChannel` quedó con try/catch (bug — ver abajo).

2. **NotificationDispatcher** (`app/Notifications/Services/`) — 3 mapas constantes: `CHANNEL_MAP` (name→class), `CHANNEL_ADDRESS_MAP` (name→user attribute), `PREFERENCE_CHANNEL_MAP` (name→preference toggle). El canal `log` está deliberadamente ausente de los dos últimos. Nuevos canales requieren entrada en los 3 mapas.

3. **NotifiesResidents** trait (`app/Notifications/Concerns/`) — mixin para servicios de dominio. `notifyUsers()` usa `DB::afterCommit()` para garantizar que una falla de notificación nunca haga rollback de una transacción de dinero.

4. **notifyEvent()** — flujo de eventos configurables por tenant. Lee `NotificationSettingService::resolve($eventKey)`, setea `$data['email_template'] = $eventKey` para BladeEmailChannel, y delega a `notifyUsersFromTemplate()`.

**Regla crítica verificada:** todas las llamadas a `notifyEvent()` y `notifyUsers()` en `PaymentService` ocurren FUERA del bloque `DB::transaction()`. El `$connectionsToTransact = []` en los tests es el pattern correcto para verificar notificaciones que usan `DB::afterCommit`.

**Bug pattern conocido:** `$daysLeft = (int) $today->diffInDays($dueDate, false)` es NEGATIVO para cargos vencidos (mismo patrón que `collections-report-signed-diffindays`). Siempre pasar `abs()` al context del template o usar un campo separado `days_past_due`.

**Slug de template:** `notifyEvent($eventKey, ...)` genera slug `notification_template.$eventKey`. BladeEmailChannel convierte el eventKey con underscore→hyphen para el nombre de la vista Blade: `payment_accepted` → `email::payment-accepted`.

**WARNING de doble resolución:** cuando un caller llama `boardRecipientsForEvent($key)` Y luego `notifyEvent($key, ...)`, el `NotificationSettingService::resolve($key)` se ejecuta DOS VECES. Fix: extraer la resolución antes de ambas llamadas.

**Inconsistencia pendiente:** los templates no-payment del seeder (`charge_*`, `visitor_at_gate`, etc.) usan tokens en español (`{nombre_residente}`, `{concepto}`). Los templates de payment usan inglés (`{resident_name}`, `{amount}`). Debe corregirse antes de que los templates no-payment sean cableados.

**Why:** regla de proyecto — "English identifiers only", los tokens `{...}` son sustitución de código.
**How to apply:** al revisar seeders de templates o nuevos callers de `notifyEvent`, verificar que los tokens del body coincidan con keys en inglés.

**Cambio 6 — queueing asíncrono por destinatario (2026-06-28):**
- `DeliverChannelMessageJob` (`Modules/Notification/app/Jobs/`) es el job de entrega: `ShouldQueue`, `$tries=3`, backoff `[10,30,60]`, constructor con solo escalares/arrays (no Eloquent). El dispatcher encola un job por (canal I/O × destinatario). `log` sigue inline. Job seguro para workers sin contexto de tenant (addresses ya resueltas).
- `makeChannel()` en el dispatcher es `public` para uso del job (code smell menor, aceptado).
- **Bug abierto:** `ResendEmailChannel::send()` tiene un `try/catch (\Throwable)` que swallows todas las excepciones y solo loguea. El job cree que exitó con éxito, los `$tries=3` nunca se activan. Fix: eliminar el catch. Reportado en Cambio 6 como CRITICAL.
- `SendPushNotificationJob` fue eliminado; solo queda en vendor/composer autoload (normal).
