<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use QCH\Core\Models\BaseModel;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('tenant_payment_type')) {
            Schema::create('tenant_payment_type', function (Blueprint $table) {
                $table->comment('Central catalog of billing/payment type options available to tenant subscriptions.');
                BaseModel::columns($table, false);
                $table->string('name')->comment('Display name of the payment type (e.g. Monthly, Annual).');
                $table->string('description')->nullable()->comment('Optional description explaining the payment type terms.');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_payment_type');
    }
};
