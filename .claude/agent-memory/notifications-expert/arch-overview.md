---
name: arch-overview
description: Notification system architecture — event catalog, per-tenant config model, dispatch flow, channel mapping, dispatch-outside-transaction rule
metadata:
  type: project
---

## Event catalog (CENTRAL)

`notification_event` — sin `tenant_id`. Campos: `key` (slug), `audience` (`resident`|`board`), `default_channels` (JSON array), `supports_email/push/whatsapp`. UUID const en el modelo. Seeded en `CatalogSeeder::seedNotificationEvents()`. Aparece automáticamente en la UI del admin.

## Per-tenant config (TENANT)

`notification_setting` — `is_active` + `channel_email` + `channel_push` + `channel_whatsapp` (booleans) por evento por tenant. `'log'` (in-app) SIEMPRE incluido, no hay toggle para él.

`notification_setting_board_role` — pivot TENANT: qué `board_role_id` recibe cada evento con `audience=board`. Para eventos `audience=resident` este pivot se ignora; el destinatario es siempre `unitBillingUserId()` (is_billing_owner en unit_user).

## Channel mapping (NotificationSettingService::channelsFromSetting)

| Setting column | Dispatcher channel name |
|---|---|
| `channel_email=true` | `blade_email` |
| `channel_push=true` | `firebase_push` |
| `channel_whatsapp=true` | `whatsapp` |
| (always) | `log` |

## Dispatch flow

1. Domain service llama `notifyEvent($eventKey, $recipientUserIds, $context, ...)` desde `NotifiesResidents` trait.
2. `notifyEvent` llama `NotificationSettingService::resolve($eventKey)` → `{is_active, channels, board_role_ids}`.
3. Si `!is_active` → return inmediato, sin registrar nada.
4. Pone `$data['email_template']=$eventKey` + `$data['email_context']=$context`.
5. Llama `notifyUsersFromTemplate` → `notifyUsers` → `DB::afterCommit($dispatch)`.
6. Dentro del afterCommit: `recordInApp()` (Notification + NotificationUser rows) + `NotificationDispatcher::dispatch()`.

## Dispatch-outside-transaction (HARD rule)

`notifyEvent()` usa `DB::afterCommit`. Las llamadas a `notifyEvent()` deben ir DESPUÉS del `DB::transaction()` block del dominio. Una falla de notificación NUNCA debe rollbackear un pago. `notifyUsers` también tiene try/catch + report() interno.

## Admin UI

- `GET /config/notifications` + `PUT /config/notifications` → `ConfigurationController::getNotificationConfig/updateNotificationConfig`
- Frontend: `frontend/src/modules/configuration/components/NotificationsConfigSection.tsx`
- UI es data-driven (eventos del catálogo aparecen sin código adicional)

## Board vs resident recipients

- `audience=board`: usar `boardRecipientsForEvent($eventKey, $preResolved)` — resuelve board_role_ids → spatie slugs → user ids dentro del tenant. SIEMPRE pasar `$preResolved` si ya se llamó `resolve()` antes (evitar double-resolve).
- `audience=resident`: resolver manualmente con `unitBillingUserId($unitId)`.

## Key files

- `backend/app/Notifications/Services/NotificationDispatcher.php` — CHANNEL_MAP, CHANNEL_ADDRESS_MAP, PREFERENCE_CHANNEL_MAP
- `backend/app/Notifications/Concerns/NotifiesResidents.php` — trait que usan los domain services
- `backend/Modules/Notification/app/Services/NotificationSettingService.php` — resolve(), allForTenant(), updateForTenant()
- `backend/Modules/Notification/app/Models/NotificationEvent.php` — catálogo central
- `backend/Modules/Notification/app/Models/NotificationSetting.php` — config por tenant
- `backend/Modules/Configuration/app/Http/Controllers/ConfigurationController.php` — endpoints admin
- `backend/database/seeders/CatalogSeeder.php` — seedNotificationEvents()
