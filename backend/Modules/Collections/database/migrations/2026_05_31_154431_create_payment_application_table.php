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
        Schema::create('payment_application', function (Blueprint $table) {
            $table->comment('FIFO ledger linking a payment to a charge with the partial amount applied.');
            BaseModel::columns($table, true);
            $table->uuid('payment_id')->comment('FK to payment');
            $table->uuid('charge_id')->comment('FK to charge');
            $table->bigInteger('applied_amount')->comment('Minor units (cents).');

            $table->foreign('payment_id')->references('id')->on('payment');
            $table->foreign('charge_id')->references('id')->on('charge');

            $table->index('payment_id');
            $table->index('charge_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_application');
    }
};
