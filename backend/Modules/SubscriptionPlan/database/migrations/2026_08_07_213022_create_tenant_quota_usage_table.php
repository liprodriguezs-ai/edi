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
     * Per-tenant lifetime consumption counter for accumulating quota resources
     * (amenity_reservation, visitor). unit is NOT tracked here — see
     * UnitLimitService::activeUnitCount().
     */
    public function up(): void
    {
        Schema::create('tenant_quota_usage', function (Blueprint $table) {
            $table->comment('Per-tenant lifetime consumption counter for accumulating quota resources (amenity_reservation, visitor). unit is NOT tracked here — see UnitLimitService::activeUnitCount().');
            BaseModel::columns($table, true);
            $table->string('resource_key')->comment("Quota'd resource this counter tracks: 'amenity_reservation' or 'visitor'.");
            $table->bigInteger('used_count')->default(0)->comment('Lifetime count, never decremented on delete (locked product decision).');
        });

        // One ALIVE counter per tenant/resource (mirrors receipt_sequence_tenant_year_unique).
        DB::statement(
            'CREATE UNIQUE INDEX tenant_quota_usage_tenant_resource_unique ON tenant_quota_usage (tenant_id, resource_key) '
            .'WHERE deleted_at IS NULL'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS tenant_quota_usage_tenant_resource_unique');
        Schema::dropIfExists('tenant_quota_usage');
    }
};
