---
name: firebase-push-wiring
description: Firebase push channel wiring — token_sender, env setup, withConfiguredPushChannel gate, quiet hours
metadata:
  type: project
---

## Token del dispositivo

Vive en `user.token_sender` — columna única en la tabla central `user` (no hay tabla de tokens). Actualizada por la app móvil vía `UserController`.

## Address resolution

`CHANNEL_ADDRESS_MAP['firebase_push'] = 'token_sender'`
El dispatcher resuelve user ids → `token_sender`. Usuarios sin token son silenciosamente ignorados para este canal.

## Gate global (withConfiguredPushChannel)

`FirebasePushChannel` solo se anexa cuando `config('notifications.channels')` contiene `'firebase_push'`.
Controlado por env `NOTIFICATION_CHANNELS`.

## Para integrar Firebase (checklist)

1. Setear `FIREBASE_CREDENTIALS` = path o JSON de service account.
2. Agregar `firebase_push` a `NOTIFICATION_CHANNELS`.
3. Asegurarse de que la app móvil guarde `token_sender` en el user.
4. Verificar con device token real + FCM dashboard (no basta con tests unitarios).

## Quiet hours + urgencia

Push no urgente se suprime durante quiet hours del usuario.
Push urgente: `$data['urgent'] = true` en el call a `notifyEvent()`.

## Job

`SendPushNotificationJob` — `$tries=3`, deduplica tokens, usa `kreait/firebase-php` `sendMulticast()`, reintenta tokens fallidos.

## Preferencia por usuario

`PREFERENCE_CHANNEL_MAP['firebase_push'] = 'push'` — respeta el toggle de preferencia del usuario en `NotificationPreference`.
