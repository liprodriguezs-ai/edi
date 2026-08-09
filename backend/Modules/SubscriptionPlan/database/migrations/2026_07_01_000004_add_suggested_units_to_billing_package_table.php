<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive (billing v2, S1): the discrete matrix plans (basico..premium) carry a
 * suggested cluster-size range so the future quoter can pick the plan that fits a
 * unit count. Nullable — existing packages (base/package/amenities) keep null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_package', function (Blueprint $table) {
            $table->unsignedSmallInteger('suggested_unit_min')->nullable()->after('is_public')->comment('Billing v2: lower bound (inclusive) of the cluster-size band this plan targets; null when not a matrix plan');
            $table->unsignedSmallInteger('suggested_unit_max')->nullable()->after('suggested_unit_min')->comment('Billing v2: upper bound (inclusive) of the cluster-size band this plan targets; null when not a matrix plan');
        });
    }

    public function down(): void
    {
        Schema::table('billing_package', function (Blueprint $table) {
            $table->dropColumn(['suggested_unit_min', 'suggested_unit_max']);
        });
    }
};
