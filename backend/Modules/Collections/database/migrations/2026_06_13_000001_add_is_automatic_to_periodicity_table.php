<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Collections\Models\Periodicity;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('periodicity', function (Blueprint $table) {
            $table->boolean('is_automatic')
                ->default(false)
                ->after('is_active')
                ->comment('Whether the daily cron auto-generates charges for concepts with this periodicity. Single source of truth for backend filter + frontend badge. true=cron-generated, false=manual-only (Eventual).');
        });

        // Backfill existing rows per USER DECISION:
        // ALL recurring periodicities are automatic: MONTHLY, BIMONTHLY, YEARLY.
        // EVENTUAL remains false (manual-only by definition).
        // WEEKLY/BIWEEKLY do not exist yet — seeder creates them with is_automatic=true.
        // Using Eloquent (NOT DB:: — laravel-backend-architecture rule 2); central catalog has no TenantScope.
        Periodicity::whereIn('id', [
            Periodicity::MONTHLY,
            Periodicity::BIMONTHLY,
            Periodicity::YEARLY,
        ])->update(['is_automatic' => true]);

        Periodicity::where('id', Periodicity::EVENTUAL)
            ->update(['is_automatic' => false]);
    }

    public function down(): void
    {
        Schema::table('periodicity', function (Blueprint $table) {
            $table->dropColumn('is_automatic');
        });
    }
};
