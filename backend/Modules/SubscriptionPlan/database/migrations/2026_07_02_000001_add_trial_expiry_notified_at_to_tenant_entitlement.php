<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dedup marker for the billing:expire-manual-entitlements daily cron. Expired
 * source=trial rows are kept by design (never deleted), so without a marker the
 * sweep re-selected the same rows every run and re-sent "tu prueba de {módulo}
 * venció" to the cluster admins forever. trial_expiry_notified_at is stamped once
 * the row has been processed, and the cron's notify select filters on it
 * (whereNull), so each expired trial notifies AT MOST ONCE.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_entitlement', function (Blueprint $table) {
            $table->timestamp('trial_expiry_notified_at')->nullable()->after('expires_at')
                ->comment('When the trial-expiry notification was processed for this entitlement; NULL = not yet notified. Prevents the daily cron from re-notifying an expired trial every run.');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_entitlement', function (Blueprint $table) {
            $table->dropColumn('trial_expiry_notified_at');
        });
    }
};
