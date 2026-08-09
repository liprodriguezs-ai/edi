---
name: nightwatch-notifications-blindspot
description: Why Laravel Nightwatch's "Notifications" panel shows 0 — the dispatcher is 100% custom and never fires NotificationSending/NotificationSent
metadata:
  type: project
---

> **CORRECTION 2026-07-30 (same day, later):** the "always 0" conclusion is FIXED, not wrong — the diagnosis
> below still describes the engine accurately, but the panel now populates. See
> [[nightwatch-observability-events]]: `DeliverChannelMessageJob` emits the `NotificationSending`/`NotificationSent`
> pair around every queued send, using `App\Notifications\DispatchedNotification::for($eventKey)`. No channel
> was re-hosted, so the "SDD-sized change" estimate at the bottom of this file was pessimistic.

**Nightwatch's Notifications panel reads 0 for this app** (verified 2026-07-30, before the fix above).

Nightwatch instruments notifications ONLY via `Illuminate\Notifications\Events\NotificationSending` /
`NotificationSent` (`vendor/laravel/nightwatch/src/NightwatchServiceProvider.php:348` →
`Hooks/NotificationListener.php` → `Sensors/NotificationSensor.php`). Those events are emitted exclusively by
`Illuminate\Notifications\NotificationSender` (i.e. `Notification::send()`, `Notification::route()`,
`$notifiable->notify()`).

This project NEVER uses that pipeline:
- Engine = custom `NotificationDispatcher` (`backend/app/Notifications/Services/NotificationDispatcher.php`)
  + `NotifiesResidents` trait + `NotificationChannelInterface` channels + `DeliverChannelMessageJob`.
  No class extends `Illuminate\Notifications\Notification`, no `via()`, no channel registered with
  `ChannelManager::extend`.
- `App\Models\User:38` uses the `Notifiable` trait, but nothing calls `->notify()`.
- `Modules/User/app/Notifications/EmailVerificationNotification.php` LOOKS native but extends
  `Modules\Notification\Models\Notification` (an Eloquent model!) — dead code, never dispatched.
- `User::sendPasswordResetNotification()` (`app/Models/User.php:179`) is OVERRIDDEN to use
  `SystemMailer` + `ResetPasswordMail` → bypasses the notification pipeline too.

**What Nightwatch DOES see:** the Mail panel (every send goes through `Mail::html()` /
`Mail::to()->send()`), queued jobs (`DeliverChannelMessageJob`, `NotificationJob`, `NotificationUserJob`),
outgoing HTTP (Twilio SMS), queries, exceptions.

**Nightwatch install state (backend):** `laravel/nightwatch ^1.25` in `require` (prod), auto-discovered
(`extra.laravel.dont-discover: []`), `config/nightwatch.php` NOT published → all defaults from
`vendor/laravel/nightwatch/config/nightwatch.php`, incl. `filtering.ignore_notifications = false`. So
nothing is filtering notifications out — there is simply nothing to capture.

To make it visible you'd need a real `Illuminate\Notifications\Notification` + custom
`Illuminate\Notifications\Channels` (see the report of 2026-07-30 for the seam analysis: cheapest insertion
point is `NotificationDispatcher::dispatch()`, but ~7 channel classes + address routing + preference
filtering + queueing would have to be re-hosted, so it's an SDD-sized change).
