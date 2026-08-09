---
name: email-template-contract
description: BladeEmailChannel naming contract (event_key → blade filename), silent fallback gotcha, copy-emails workflow
metadata:
  type: project
---

## Contrato de nombre (CRÍTICO)

`BladeEmailChannel::renderContent()` convierte el event key:
`payment_accepted` → `str_replace('_', '-', ...)` → busca vista `email::payment-accepted`
→ archivo físico: `backend/Modules/Email/resources/views/payment-accepted.blade.php`

Si la vista NO existe: `Log::warning` silencioso + fallback a `<p>nl2br(e($body))</p>`.

## Fuente de las plantillas

Las plantillas se AUTORAN en `frontend/emails/*.tsx` (React Email).
Compilación: `pnpm copy-emails` desde el repo raíz.
NO editar manualmente los `.blade.php` — son archivos generados.

## Gotcha: fallback invisible con Mail::fake()

`Mail::fake()` NO renderiza Blade → los tests unitarios no detectan un nombre de vista incorrecto.
Para verificar rendering real: `php artisan tinker` + `view('email::payment-accepted', $ctx)->render()` o abrir Mailpit en `localhost:8025` (dev host, MAIL_HOST=127.0.0.1).

## Variables de contexto

`BladeEmailChannel` pasa a la vista: `$data['email_context']` (seteado por `notifyEvent()`) + `['emailBaseUrl' => ...]`.
`emailBaseUrl` = `config('email.assets_url')` ?: `config('app.url') . '/email'`.

## previewText en templates

Los `previewText`/preheader NO deben interpolar PreviewProps (bake fallback data al compilar).
Mantener genérico o la cadena de preview quedará hardcodeada en el Blade compilado.

## Verified (2026-06-27)

`backend/app/Notifications/Channels/BladeEmailChannel.php` línea 73:
`$viewName = 'email::'.str_replace('_', '-', $templateSlug);`
