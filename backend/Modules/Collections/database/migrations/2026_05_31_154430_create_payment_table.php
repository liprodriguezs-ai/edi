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
        Schema::create('payment', function (Blueprint $table) {
            $table->comment('Payment registered against one or more unit charges via FIFO application.');
            BaseModel::columns($table, true);
            $table->uuid('unit_id')->comment('FK to unit');
            $table->uuid('payer_user_id')->nullable()->comment('Central user. Null = auto-resolved billing owner.');
            $table->date('paid_at')->comment('Date the payment was received.');
            $table->bigInteger('total_amount')->comment('Minor units (cents).');
            $table->uuid('payment_method_id')->comment('FK to payment_method');
            $table->uuid('bank_account_id')->comment('FK to bank_account where funds were deposited.');
            $table->string('reference')->nullable()->comment('Bank operation number, cheque folio, etc.');
            $table->string('receipt_number')->nullable()->comment('Auto-generated: R-YYYY-NNNNNN. Unique per tenant.');
            $table->string('physical_receipt_number')->nullable()->comment('Manual receipt book number if applicable.');
            $table->uuid('payment_status_id')->comment('FK to payment_status');
            $table->text('cancellation_reason')->nullable()->comment('Reason provided when the payment was cancelled.');
            $table->text('notes')->nullable()->comment('Free-text administrative notes about this payment.');

            $table->foreign('payment_method_id')->references('id')->on('payment_method');
            $table->foreign('bank_account_id')->references('id')->on('bank_account');
            $table->foreign('payment_status_id')->references('id')->on('payment_status');
            // A payment always belongs to a real unit; restrict hard-deletes of a unit
            // that still has payments (units are soft-deleted in practice, so this is a backstop).
            $table->foreign('unit_id')->references('id')->on('unit')->restrictOnDelete();

            $table->index('unit_id');
            $table->index('payment_status_id');
            $table->index('bank_account_id');
            $table->index('payer_user_id');
            // Income-statement / account reports scan payments by tenant over a date range.
            $table->index(['tenant_id', 'paid_at']);
        });

        // Unique receipt_number per tenant (soft-delete not applied: cancelled payments keep their receipt number).
        DB::statement(
            'CREATE UNIQUE INDEX payment_tenant_receipt_unique ON "payment" '
            .'(tenant_id, receipt_number)'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS payment_tenant_receipt_unique');
        Schema::dropIfExists('payment');
    }
};
