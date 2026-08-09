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
     */
    public function up(): void
    {
        Schema::create('credit_balance', function (Blueprint $table) {
            $table->comment('Running credit balance (saldo a favor) per unit; applied automatically to future charges.');
            BaseModel::columns($table, true);
            $table->uuid('unit_id')->comment('FK to unit');
            $table->bigInteger('amount')->default(0)->comment('Minor units (cents). Running balance; 0 means no credit.');

            $table->index('unit_id');
            // No onDelete: keep the credit row if the unit is soft-deleted.
            $table->foreign('unit_id')->references('id')->on('unit');
        });

        // One ALIVE row per unit per tenant: partial so a soft-deleted credit row can be
        // replaced without colliding (mirrors the module's other partial unique indexes).
        DB::statement(
            'CREATE UNIQUE INDEX credit_balance_tenant_unit_unique ON "credit_balance" '
            .'(tenant_id, unit_id) '
            .'WHERE deleted_at IS NULL'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS credit_balance_tenant_unit_unique');
        Schema::dropIfExists('credit_balance');
    }
};
