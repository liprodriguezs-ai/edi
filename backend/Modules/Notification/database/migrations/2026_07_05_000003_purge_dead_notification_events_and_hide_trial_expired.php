<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Purge two dead placeholder events (monthly_statement, urgent_notice) that had
     * no emitter, and hide the trial_expired system event from the tenant config UI
     * on existing databases (a fresh DB is corrected by CatalogSeeder).
     *
     * The FK notification_setting.notification_event_id is ON DELETE CASCADE, so
     * deleting an event cascades its per-tenant settings and board-role pivots.
     * Runs after 2026_07_05_000002 so the is_hidden column already exists.
     */
    public function up(): void
    {
        DB::table('notification_event')
            ->whereIn('key', ['monthly_statement', 'urgent_notice'])
            ->delete();

        DB::table('notification_event')
            ->where('key', 'trial_expired')
            ->update(['is_hidden' => true]);
    }

    /**
     * The deleted catalog rows cannot be restored here; only the is_hidden flag is
     * reversible. CatalogSeeder is the source of truth for event rows.
     */
    public function down(): void
    {
        DB::table('notification_event')
            ->where('key', 'trial_expired')
            ->update(['is_hidden' => false]);
    }
};
