---
name: system-email-unification
description: SystemMailer is the single ungated path for system/transactional email (invitations, reset, welcome); domain email goes through the gated dispatcher/blade_email
metadata:
  type: project
---

# Two email paths, one transport (Cambio 5, 2026-06-28)

The app now has exactly TWO conceptual email paths, both on the SAME transport
(Laravel `Mail` facade → `config('mail.default')`; `resend` driver in prod) and
the SAME compiled `email::` Blade templates:

1. **DOMAIN / notifiable email** (gated): domain event → `NotifiesResidents::notifyEvent()`
   → `NotificationDispatcher::dispatch()` → **`BladeEmailChannel`** (`blade_email`).
   Honors per-tenant `NotificationSetting`, per-user `NotificationPreference`, quiet hours.
   - `blade_email` is the CANONICAL domain email channel. Per-tenant settings + every
     seeded event's `default_channels` map email→`blade_email` (verified: no seeder uses
     `resend_email`).
   - `ResendEmailChannel` (`resend_email`) is LEGACY: calls the Resend API directly with
     unrendered `nl2br(htmlspecialchars($body))` (no template render). It is NOT produced
     by the settings/defaults flow — kept ONLY because `tests/Feature/Notifications/NotificationDeliveryTest`
     binds it as an address-resolution spy (`$this->app->instance(ResendEmailChannel::class, $spy)`).
     Do not remove it without rewriting that test to spy on `blade_email`.

2. **SYSTEM email** (ungated, must-always-deliver): `Modules\Email\Services\SystemMailer::send($to, Mailable)`
   = `Mail::to($recipients)->send($mailable)`. DELIBERATELY bypasses the notification engine
   (dispatcher / NotificationSetting / NotificationPreference / quiet hours) — a tenant
   toggle or user preference must NEVER suppress a legal/security email.
   - NULL/EMPTY GUARD: signature is `send(string|array|null $to, Mailable)` — nullable ON
     PURPOSE because callers pass `$user->email` which is NULLABLE ("Everything is User":
     passwordless/email-less people). The body filters to non-empty string recipients; if
     none, it `Log::warning('SystemMailer: skipping email, recipient empty', [...])` and
     returns. The param MUST be nullable — a non-nullable `string|array` would TypeError at
     the call boundary before any in-body guard could run (PHP 8.4). One guard here protects
     every system-email path.

## All 5 system mailables implement ShouldQueue (async + retry)
`ResetPasswordMail`, `UserInvitationMail`, `ResidentInvitationMail`, `WelcomeOwnerMail`,
`OwnerTransferredMail` all `implements ShouldQueue` (added 2026-06-28 after a review WARNING:
reset had regressed from queued to sync, risking a 500 on forgot-password if Resend fails
with no retry). So `SystemMailer::send()` QUEUES them (off the request path).
- TENANT CONTEXT on the worker is safe: `config/tenancy.php` has `QueueTenancyBootstrapper`
  ALWAYS active (serializes tenant_id at dispatch, re-inits tenancy before the job runs).
  Additionally `ResidentInvitationMail` reads tenant data from a pre-resolved SCALAR
  `$context` array (not live queries), so it serializes/renders fine regardless.
- TEST IMPACT (important): under `Mail::fake()` a ShouldQueue mailable is recorded as
  QUEUED, NOT sent (MailFake::sendMail routes ShouldQueue → queue()). Assert with
  `Mail::assertQueued` / `Mail::queued` / `Mail::assertNotQueued` — `Mail::assertSent`
  FAILS even with QUEUE_CONNECTION=sync (fake intercepts before the queue). Updated
  SystemMailerTest, UserInvitationHardeningTest, EmployeeHttpTest, ResidentInvitationTest.

## Callers migrated to SystemMailer
- `Modules/User/app/Services/UserInvitationService::sendInvitationEmail()` →
  `app(SystemMailer::class)->send($invitation->email, $mailable)` (UserInvitationMail / ResidentInvitationMail).
- `app/Models/User::sendPasswordResetNotification()` → `app(SystemMailer::class)->send($this->email, new ResetPasswordMail($url, $this))`.
  (Password broker still calls this method; the `ResetPasswordNotification` wrapper was deleted.)
- `Modules/Email/app/Services/AccountOwnerNotificationService` → SystemMailer + the two new mailables.

## New mailables (render `email::welcome-owner` / `email::owner-transferred`)
- `Modules\Email\Emails\WelcomeOwnerMail(User $user)` — subject "Bienvenido a Kivo7".
- `Modules\Email\Emails\OwnerTransferredMail(User $user)` — subject "Eres titular de una cuenta en Kivo7".
- Both pass `user`, `url` (→ `/dashboard`), `emailBaseUrl` non-null (blades interpolate `$user->name`, `$url`, `$emailBaseUrl`).
- NOTE: the old `AccountOwnerNotificationService` referenced `__('email::email.welcome_owner.subject')`,
  a lang key that does NOT exist in lang/en or lang/es (would render the literal key). Replaced with explicit Spanish subjects.

## Deleted (dead/legacy)
- `Modules/Email/app/Services/EmailService.php` (Resend API direct — was unused).
- `Modules/Email/app/Services/EmailSenderService.php` (`Mail::send([])` raw — only used by AccountOwnerNotificationService).
- `Modules/User/app/Notifications/ResetPasswordNotification.php` (replaced by SystemMailer + ResetPasswordMail).

## Gotchas
- `ResetPasswordMail` lives in `Modules\User\Emails`, NOT `Modules\Email\Emails` (the only transactional mailable outside the Email module).
- `Mail::fake()` does NOT render Blade — a green Mail::assertSent test does not prove a template renders. Always `->render()` via tinker after touching a mailable/blade.
- Guard tests: `tests/Feature/Email/SystemMailerTest.php` proves invitations survive all per-tenant settings off + empty dispatcher channels, and reset survives user email-preference off.
