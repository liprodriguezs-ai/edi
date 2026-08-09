---
name: click-action-seam
description: click_action convention — how backend sets it, how frontend navigates, which routes are registered (2026-06-28 review)
metadata:
  type: project
---

Reviewed 2026-06-28. All 19 click_action destinations set by backend services have matching routes in `frontend/src/App.tsx`.

## Convention

- Backend stores `click_action` as a string on the `notification` record (`$fillable`, Notification.php:39).
- The field is exposed via the `notification` include on `notification_user` (NotificationUserController::includes()).
- Bell component (`notification-bell.tsx:62-66`) reads `item.notification?.click_action` and navigates only if it `startsWith('/')`.
- FCM foreground handler (`useFirebaseMessaging.ts:125-126`) reads `payload.data?.click_action` with the same guard.
- `SendPushNotificationJob:67` always emits `click_action` as a string in the FCM data payload (defaults to `'/'` if absent).

## Verified routes (2026-06-28)

All confirmed present in App.tsx:

| click_action | App.tsx line | PermissionEntity |
|---|---|---|
| `/payment/:id` | 331 | `payment_application` |
| `/statement` | 330 | `payment_application` |
| `/charge-batch` | 315 | `charge_batch` |
| `/announcement` | 265 | `announcement` |
| `/board-period` | 226 | `board_period` |
| `/chat-channel` | 267 | `chat_channel` |
| `/expense` | 234 | `expense` |
| `/bank-account` | 231 | `bank_account` |
| `/provider` | 286 | `provider` |
| `/vehicle` | 218 | `vehicle` |
| `/my-unit` | 209 | `unit` |
| `/collaborator/me` | 387 | (no gate) |
| `/collaborator/:id` | 391 | `collaborator` |
| `/reports` | 257 | `report` |
| `/community-project/:id` | 289 | `community_project` |
| `/my-access-log` | 211 | `unit` |
| `/access-log` | 275 | `access_log` |
| `/visitor` | 277 | `visitor` |
| `/tableros/tesoreria` | 246 | `report_treasury` |

## Notes

- `/my-access-log` and `/my-unit` share the `unit` PermissionEntity gate — residents need `read.unit`.
- `/payment/:id` is gated under `payment_application` (not `payment`) intentionally: residents have `read.payment_application` but not `read.payment`; backend falls back to unit-ownership check.

[[payment-detail-seam]]
