<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Notification\Database\Seeders\TenantNotificationSettingSeeder;
use Modules\Notification\Models\NotificationEvent;
use Modules\Tenant\Models\Tenant;

return new class extends Migration
{
    /**
     * Flip channel_email=true on existing tenants' notification_setting rows
     * for the 5 amenity-reservation events that just gained email capability
     * (created, approved, payment_confirmed, payment_rejected,
     * surcharge_added).
     *
     * Two-step because tenants created BEFORE the AmenityReservations module
     * (S10) have ZERO notification_setting rows for any amenity_reservation.*
     * event — those rows are only ever seeded on TenantCreated or when an
     * admin saves the notifications config UI, so a plain UPDATE is a no-op
     * for them and their President/Treasurer board-role recipients never get
     * created either:
     *
     *   1. Run TenantNotificationSettingSeeder (firstOrCreate-based, so
     *      already-safe to re-run) inside every existing tenant's context.
     *      It backfills any missing notification_setting row — including the
     *      11 amenity_reservation.* rows — and the default President +
     *      Treasurer board-role recipients for amenity_reservation.created.
     *   2. Explicitly flip channel_email=true on the 5 in-scope events for
     *      ALL tenants. This is deliberately NOT left to the seeder's
     *      default_channels lookup: at migrate time on an old database,
     *      CatalogSeeder's blade_email addition to default_channels may not
     *      have run yet, so relying on it here would silently no-op.
     *
     * TenantNotificationSettingSeeder uses firstOrCreate, so re-seeding does
     * NOT update pre-existing rows — step 2 is what makes existing rows catch
     * up. The toggle was never visible before this change, so no admin ever
     * made a deliberate off-choice here; flipping the default to on
     * overrides nothing. New tenants (created after this migration) are
     * covered by TenantCreated seeding, same as any other event.
     *
     * Fresh-DB safety: Tenant::all() is empty on a brand-new database (no
     * tenants exist yet), so the loop no-ops cleanly.
     *
     * Plain DB::table()/seeder calls, no schema change, so the migration
     * ->comment() column rule does not apply (mirrors
     * 2026_07_05_000003_purge_dead_notification_events_and_hide_trial_expired.php).
     */
    public function up(): void
    {
        Tenant::all()->each(function (Tenant $tenant): void {
            $tenant->run(function (): void {
                (new TenantNotificationSettingSeeder)->run();
            });
        });

        DB::table('notification_setting')
            ->whereIn('notification_event_id', [
                NotificationEvent::AMENITY_RESERVATION_CREATED,
                NotificationEvent::AMENITY_RESERVATION_APPROVED,
                NotificationEvent::AMENITY_RESERVATION_PAYMENT_CONFIRMED,
                NotificationEvent::AMENITY_RESERVATION_PAYMENT_REJECTED,
                NotificationEvent::AMENITY_RESERVATION_SURCHARGE_ADDED,
            ])
            ->whereNull('deleted_at')
            ->update(['channel_email' => true]);
    }

    /**
     * Restores the pre-change off-state on exactly the same 5 events. Does
     * NOT delete any notification_setting rows backfilled by up() — those
     * rows represent real per-cluster settings for events that existed all
     * along (the amenity_reservation.* events themselves are not new), so
     * removing them on rollback would drop legitimate configuration for
     * unrelated channels (push/whatsapp) and board-role recipients.
     */
    public function down(): void
    {
        DB::table('notification_setting')
            ->whereIn('notification_event_id', [
                NotificationEvent::AMENITY_RESERVATION_CREATED,
                NotificationEvent::AMENITY_RESERVATION_APPROVED,
                NotificationEvent::AMENITY_RESERVATION_PAYMENT_CONFIRMED,
                NotificationEvent::AMENITY_RESERVATION_PAYMENT_REJECTED,
                NotificationEvent::AMENITY_RESERVATION_SURCHARGE_ADDED,
            ])
            ->whereNull('deleted_at')
            ->update(['channel_email' => false]);
    }
};
