<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 12 (§16.4 r3): a plan's maximum number of units.
 *
 * NULL means "unlimited" (the Enterprise tier). The downgrade guard in
 * TenantService::changePlan blocks switching to a plan whose unit_limit is
 * smaller than the tenant's current active unit count.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_plan', function (Blueprint $table): void {
            $table->unsignedInteger('unit_limit')->nullable()->after('trial_days')->comment('Maximum active units allowed under this plan; NULL means unlimited (Enterprise tier)');
        });
    }

    public function down(): void
    {
        Schema::table('subscription_plan', function (Blueprint $table): void {
            $table->dropColumn('unit_limit');
        });
    }
};
