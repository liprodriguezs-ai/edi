<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Modules\Tracking\Models\TrackingEvent;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('telescope:prune --hours=72')->daily();
// §18.6.1 — inactive sessions older than 90 days may be physically removed.
Schedule::command('sanctum:prune-expired --hours=2160')->daily();
// Non-stripe grants (trial, manual, super_admin_free) have no Stripe webhook to
// enforce lapses, so this daily run blocks + recomputes any organization whose such
// entitlement expired with no remaining active coverage (WU4). Stripe = webhook.
Schedule::command('billing:expire-manual-entitlements')->daily();
Schedule::command('media:prune-cache')->dailyAt('00:00');
// Compensating control for the single-transaction media attach in PackageService
// (design §5): prunes deprecated conversions and media whose owning record no longer
// exists, including rows stranded by a rolled-back package intake/handover.
Schedule::command('media-library:clean')->weekly();
// tracking_event accumulates from public, throttled-but-anonymous /api/track traffic
// (landing + app) with no per-tenant scope; bounded like every other log above
// instead of growing forever on the shared Postgres DB. Retention: tracking.retention_days.
Schedule::command('model:prune', ['--model' => TrackingEvent::class])->daily();
// follow_up.follow_up_status_id converges pending<->overdue vs due_date, per
// tenant, both directions (design D7, REQ-FUP-005). Idempotent. Hourly because
// "today" is each tenant's civil day (TenantTime): every tenant midnight is covered.
Schedule::command('follow-up:refresh-overdue')->hourlyAt(15);
