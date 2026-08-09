<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use QCH\Core\Models\BaseModel;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Central catalog of usage caps per plan (or global) and subscription scope.
     * Two partial unique indexes enforce uniqueness at the database level (D4):
     * Postgres treats NULLs as distinct in a plain unique index, so a naive
     * unique() constraint would allow duplicate global rows.
     */
    public function up(): void
    {
        Schema::create('plan_quota', function (Blueprint $table) {
            $table->comment('Central catalog of usage caps per plan (or global) and subscription scope, -1 = unlimited.');
            BaseModel::columns($table, false);
            $table->foreignUuid('billing_package_id')->nullable()->references('id')->on('billing_package')
                ->comment('FK to billing_package; NULL = applies to all plans (global row).');
            $table->string('scope')->comment("Subscription scope this row applies to: 'trial' or 'active'.");
            $table->string('resource_key')->comment("Quota'd resource: 'unit', 'amenity_reservation', or 'visitor'.");
            $table->integer('limit_value')->comment('-1 = unlimited; otherwise the max lifetime/live count allowed.');
        });

        // Two partial unique indexes (schema builder cannot express partial
        // indexes): a global row (billing_package_id IS NULL) is unique per
        // (scope, resource_key); a plan-specific row is unique per
        // (billing_package_id, scope, resource_key). Both scoped to alive rows.
        DB::statement(
            'CREATE UNIQUE INDEX plan_quota_global_unique ON plan_quota (scope, resource_key) '
            .'WHERE billing_package_id IS NULL AND deleted_at IS NULL'
        );
        DB::statement(
            'CREATE UNIQUE INDEX plan_quota_package_unique ON plan_quota (billing_package_id, scope, resource_key) '
            .'WHERE billing_package_id IS NOT NULL AND deleted_at IS NULL'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS plan_quota_package_unique');
        DB::statement('DROP INDEX IF EXISTS plan_quota_global_unique');
        Schema::dropIfExists('plan_quota');
    }
};
