<?php

use Illuminate\Database\Migrations\Migration;
use Modules\Configuration\Database\Seeders\NotificationTemplateSeeder;
use Modules\Setting\Models\Setting;
use Modules\Tenant\Models\Tenant;

return new class extends Migration
{
    /**
     * Backfill the `notification_template.charge_created_batch` Setting row for every
     * EXISTING tenant.
     *
     * The consolidated catch-up dispatch (ChargeGenerationService::notifyConsolidatedChargesIssued)
     * renders this plural copy variant instead of the singular
     * `notification_template.charge_created`. Templates are only ever seeded on
     * TenantCreated, so without this backfill every existing cluster would fall through
     * to the hardcoded fallback strings and could never customize the plural wording
     * from the templates screen (which lists whatever Setting rows exist).
     *
     * Deliberately NOT `(new NotificationTemplateSeeder)->run()`: that seeder is
     * updateOrCreate-based across ALL slugs, so running it here would silently discard
     * every template a cluster admin has already customized. firstOrCreate on the one
     * new slug adds what is missing and touches nothing else — and makes the migration
     * re-runnable.
     *
     * Fresh-DB safety: Tenant::all() is empty on a brand-new database, so the loop
     * no-ops and TenantCreated seeding covers new clusters (the slug is now part of
     * NotificationTemplateSeeder::defaults()).
     *
     * Plain model writes, no schema change, so the migration ->comment() column rule
     * does not apply (mirrors
     * Modules/Notification/database/migrations/2026_07_19_000001_enable_amenity_reservation_emails_on_existing_settings.php).
     */
    public function up(): void
    {
        $slug = 'notification_template.charge_created_batch';
        $template = NotificationTemplateSeeder::defaults()[$slug];

        Tenant::all()->each(function (Tenant $tenant) use ($slug, $template): void {
            $tenant->run(function () use ($slug, $template): void {
                Setting::firstOrCreate(
                    ['slug' => $slug],
                    [
                        'name' => $slug,
                        'value' => [
                            'title' => $template['title'],
                            'body' => $template['body'],
                            'is_active' => true,
                        ],
                        'type' => Setting::TYPE_JSON,
                    ]
                );
            });
        });
    }

    /**
     * Soft-deletes the backfilled rows (Setting uses SoftDeletes, so a customized body
     * is recoverable). Rolling back leaves the consolidated path on its hardcoded
     * fallback copy, which carries the same wording.
     */
    public function down(): void
    {
        Tenant::all()->each(function (Tenant $tenant): void {
            $tenant->run(function (): void {
                Setting::where('slug', 'notification_template.charge_created_batch')->delete();
            });
        });
    }
};
