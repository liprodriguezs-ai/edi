# Design: Account Owner Welcome & Transfer Emails

**Date:** 2026-03-08  
**Status:** Approved

## Objetivo

Enviar email al usuario cuando recibe el rol **account.owner**:
- **Primer usuario del tenant** (registro o primer usuario creado): email "welcome-owner" (cuenta lista, añadir colaboradores, etc.).
- **Usuario ya existente** (transferencia/cambio de rol): email "owner-transferred" (ahora eres titular de la cuenta).

Enfoque: **servicio único + llamadas explícitas**; envío mediante **Job en cola** con Resend.

---

## 1. Servicio y responsabilidad

| Aspecto | Decisión |
|--------|----------|
| **Clase** | `AccountOwnerNotificationService` |
| **Ubicación** | `Modules/Email/app/Services/AccountOwnerNotificationService.php` |
| **Responsabilidad** | Decidir qué email enviar (welcome vs transfer) y enviarlo. No asigna roles ni crea usuarios. |
| **Método público** | `notify(User $user): void` |

**Flujo interno de `notify(User $user)`:**
1. Obtener tenant: `$tenant = $user->tenants()->first()`.
2. Si no hay tenant, return (no enviar nada).
3. `$isFirstUser = $tenant->users()->count() === 1`.
4. Si `$isFirstUser` → enviar **welcome-owner**; si no → enviar **owner-transferred** (renderizar vista, armar HTML, enviar vía EmailSenderService/Resend).

**Call sites:**
- `App\Http\Controllers\AuthController::register`: después del `DB::commit()`, dispatch Job con el `$user` recién creado.
- `Modules\User\Services\UserService`: en `create()` (y en update de rol si aplica), cuando el rol asignado sea account.owner, después de `assignRole` y persistir, dispatch Job con ese `User`.

---

## 2. Criterio "primer usuario del tenant"

- **Definición:** El tenant tiene exactamente un usuario asociado (`$tenant->users()->count() === 1`).
- **Uso:** Dentro del servicio; no hace falta comprobar quién tenía antes el rol; solo el count.
- Registro: tras `syncWithoutDetaching`, el count es 1 → welcome.
- Cambio de rol (panel): el usuario ya estaba en el tenant → count ≥ 2 → owner-transferred.

---

## 3. Contenido y plantillas

| Email | Plantilla | Variables | Notas |
|-------|-----------|-----------|--------|
| **welcome-owner** | Existente: `Modules/Email/resources/views/welcome-owner.html` | `$user`, `$emailBaseUrl` | Blade; usar `view()` para renderizar. |
| **owner-transferred** | Nueva: `Modules/Email/resources/views/owner-transferred.blade.php` (o .html según convención) | `$user`, `$emailBaseUrl` | Mensaje: "Ahora eres titular de la cuenta"; mismo estilo que welcome-owner. |

**Asuntos y textos:**
- welcome-owner: asunto tipo "Bienvenido a EDI" / "Tu cuenta está lista".
- owner-transferred: asunto "Ahora eres titular de la cuenta".
- Claves en `Modules/Email/lang/es/email.php` (ej. `welcome_owner.subject`, `owner_transferred.subject`, etc.) y referenciar en vistas para i18n.

---

## 4. Envío: Job en cola

| Aspecto | Decisión |
|--------|----------|
| **Proveedor** | Resend (igual que el resto del módulo Email). |
| **Modo** | Encolar envío (Job). |
| **Job** | `Modules/Email/app/Jobs/SendAccountOwnerNotificationJob`. Recibe `User` (id para serialización). En `handle()`: resolver User, inyectar `AccountOwnerNotificationService`, llamar `notify($user)`. |
| **Call sites** | Tras commit/assignRole: `SendAccountOwnerNotificationJob::dispatch($user)`. |

**Errores:** Si el Job falla (Resend, vista), Laravel reintenta según configuración de cola. No bloquear registro/cambio de rol. Opcional: log o notificación si falla tras reintentos.

---

## 5. Plan de implementación (orden sugerido)

1. **Módulo Email**
   - Crear `AccountOwnerNotificationService` con `notify(User $user)`: obtener tenant, count users, renderizar vista correcta (welcome-owner o owner-transferred), enviar con EmailSenderService/Resend. Asegurar `emailBaseUrl` desde config.
   - Crear Job `SendAccountOwnerNotificationJob` que reciba user id y llame al servicio.
   - Añadir vista `owner-transferred` (Blade) y claves en `lang/es/email.php` (y en si aplica `lang/en/email.php`).
   - Verificar nombre de vista del módulo (namespace `email::` o `email.` según registro en ServiceProvider).

2. **AuthController**
   - Tras `DB::commit()` en `register()`, dispatch `SendAccountOwnerNotificationJob::dispatch($user)`.

3. **UserService (User module)**
   - En `create()` (y en update de rol si existe), cuando el rol asignado sea `Role::SLUG_ACCOUNT_OWNER`, después de `assignRole` y `return`, dispatch `SendAccountOwnerNotificationJob::dispatch($entity)`.

4. **Tests**
   - Feature: registro envía Job (y opcionalmente que el Job envía welcome-owner).
   - Feature: crear usuario con rol account.owner en tenant con más de un usuario envía Job (owner-transferred).
   - Unit/Feature: servicio con tenant null no envía; con 1 user envía welcome; con 2+ users envía owner-transferred.

5. **Pint**
   - Ejecutar `vendor/bin/pint --dirty` antes de finalizar.

---

## Resumen de archivos tocados/nuevos

- **Nuevos:** `Modules/Email/app/Services/AccountOwnerNotificationService.php`, `Modules/Email/app/Jobs/SendAccountOwnerNotificationJob.php`, `Modules/Email/resources/views/owner-transferred.blade.php`, claves en `Modules/Email/lang/es/email.php` (y en si aplica en).
- **Modificados:** `App\Http\Controllers\AuthController.php` (dispatch Job en register), `Modules\User\Services\UserService.php` (dispatch Job cuando rol = account.owner).
