<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * is_free/trial_days are now owned by plan_module (see 000001 + the
     * 000002 backfill that runs before this file). plan_module_price keeps
     * its own (billing_package_id, billable_module_id, interval) unique
     * constraint and every other column unchanged.
     */
    public function up(): void
    {
        Schema::table('plan_module_price', function (Blueprint $table) {
            $table->dropColumn(['is_free', 'trial_days']);
        });
    }

    /**
     * Re-adds both columns with their original create-migration comment text
     * verbatim (rule 13 applies to alters too). Column VALUES are NOT
     * restored here — only db:seed (PlanModuleMatrixSeeder) or re-running the
     * 000002 backfill against a freshly-populated plan_module table can
     * repopulate them. Accepted, dev-only.
     */
    public function down(): void
    {
        Schema::table('plan_module_price', function (Blueprint $table) {
            $table->boolean('is_free')->default(false)->comment('Whether this module is included at no cost in the plan (price pending / free)');
            $table->unsignedSmallInteger('trial_days')->nullable()->comment("override; fallback config('billing.trial_days')");
        });
    }
};
