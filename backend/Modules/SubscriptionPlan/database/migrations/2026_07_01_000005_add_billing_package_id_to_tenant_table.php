<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Billing v2 (S3): every tenant ALWAYS has a plan. tenant.billing_package_id
 * records the PLAN (billing_package: basico..premium) the organization is on — set to
 * the plan suggested by its unit count on onboarding/provision, and the plan its
 * entitlements are granted under. Nullable so legacy/central-only tenants (and the
 * pre-billing seed defaults) don't require it; new organizations set it explicitly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant', function (Blueprint $table) {
            $table->uuid('billing_package_id')->nullable()->after('subscription_plan_id')
                ->comment('FK to billing_package — the PLAN (basico..premium) this organization is on (nullable for legacy/central tenants)');

            $table->foreign('billing_package_id')->references('id')->on('billing_package')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tenant', function (Blueprint $table) {
            $table->dropForeign(['billing_package_id']);
            $table->dropColumn('billing_package_id');
        });
    }
};
