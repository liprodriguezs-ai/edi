<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use QCH\Core\Models\BaseModel;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_payment', function (Blueprint $table) {
            $table->comment('Central ledger of subscription payments for a tenant (manual transfer/cash recorded by super-admin, or stripe invoices); each row extends/anchors the tenant entitlement period it paid for. Central (no tenant scope of its own) like tenant_entitlement/billing_package.');
            BaseModel::columns($table, false);

            $table->uuid('tenant_id')->comment('FK to tenant — the organization this payment belongs to');
            $table->uuid('billable_module_id')->nullable()->comment('FK to billable_module — the module this payment paid for (null if the module was later removed)');
            $table->uuid('billing_package_id')->nullable()->comment('FK to billing_package — the PLAN this payment paid under (null if the plan was later removed)');
            $table->uuid('tenant_entitlement_id')->nullable()->comment('FK to tenant_entitlement — the entitlement row this payment extended/anchored (null if later removed)');
            $table->unsignedBigInteger('amount')->comment('Amount paid in minor units (cents)');
            $table->string('currency', 3)->default('MXN')->comment('ISO 4217 currency code (uppercase)');
            $table->string('method')->comment('Payment method: card (Stripe) | transfer | cash (manual)');
            $table->timestamp('paid_at')->comment('When the payment was made/received');
            $table->timestamp('period_start')->nullable()->comment('Start of the entitlement period this payment covers (old expires_at or now at record time)');
            $table->timestamp('period_end')->nullable()->comment('End of the entitlement period this payment covers (the new expires_at after applying the payment)');
            $table->uuid('recorded_by_user_id')->nullable()->comment('FK to user — the super-admin who recorded a manual payment (null for automatic stripe payments)');
            $table->string('stripe_invoice_id')->nullable()->unique()->comment('Stripe invoice ID backing this payment (null for manual payments); unique to dedupe webhook replays');
            $table->text('notes')->nullable()->comment('Optional free-text note for a manual payment (reference, observations)');

            $table->foreign('tenant_id')->references('id')->on('tenant')->cascadeOnDelete();
            $table->foreign('billable_module_id')->references('id')->on('billable_module')->nullOnDelete();
            $table->foreign('billing_package_id')->references('id')->on('billing_package')->nullOnDelete();
            $table->foreign('tenant_entitlement_id')->references('id')->on('tenant_entitlement')->nullOnDelete();
            $table->foreign('recorded_by_user_id')->references('id')->on('user')->nullOnDelete();
            $table->index(['tenant_id', 'paid_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_payment');
    }
};
