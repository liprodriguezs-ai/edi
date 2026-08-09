<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_package', function (Blueprint $table) {
            $table->boolean('is_public')->default(true)->after('is_active')->comment('Whether the package is shown in the public pricing catalog; false = preferential package visible only to super-admin.');
        });

        Schema::table('billing_package_price', function (Blueprint $table) {
            $table->boolean('is_public')->default(true)->after('is_active')->comment('Whether the price is shown in the public pricing catalog; false = preferential price visible only to super-admin.');
        });

        // Backfill existing rows to public (column default already covers this,
        // but we make the backfill explicit).
        DB::table('billing_package')->update(['is_public' => true]);
        DB::table('billing_package_price')->update(['is_public' => true]);
    }

    public function down(): void
    {
        Schema::table('billing_package', function (Blueprint $table) {
            $table->dropColumn('is_public');
        });

        Schema::table('billing_package_price', function (Blueprint $table) {
            $table->dropColumn('is_public');
        });
    }
};
