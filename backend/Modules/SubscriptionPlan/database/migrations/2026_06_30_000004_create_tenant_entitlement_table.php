<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use QCH\Core\Models\BaseModel;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_entitlement', function (Blueprint $table) {
            $table->comment('Central projection of what each tenant is entitled to — ONE row PER GRANTED billable_module, carrying the plan (billing_package) it was granted under and the source. The EntitlementService reads these rows to recompute system_tenant/module_tenant. Central (no tenant scope of its own) like billing_package/subscription_plan');
            BaseModel::columns($table, false);

            $table->uuid('tenant_id')->comment('FK to tenant — the organization this entitlement belongs to');
            $table->uuid('billable_module_id')->comment('FK to billable_module — the sellable module granted to the tenant (the unit of entitlement)');
            $table->uuid('billing_package_id')->comment('FK to billing_package — the PLAN under which this module was granted (basico..premium)');
            $table->string('source')->comment('Origin of the entitlement: stripe (paid subscription) | super_admin_free (manual grant) | trial | legacy_plan (backfilled from subscription_plan_id)');
            $table->string('interval')->nullable()->comment('Billing interval when source is stripe: month | year (null for non-billed grants)');
            $table->integer('quantity')->default(1)->comment('Billed quantity for this entitlement (e.g. number of units) — mirrors the Stripe subscription item quantity; informational only (price is fixed per plan×module)');
            $table->string('stripe_subscription_id')->nullable()->comment('Stripe subscription ID backing this entitlement (null for non-stripe sources)');
            $table->string('stripe_item_id')->nullable()->comment('Stripe subscription item ID for this module line (null for non-stripe sources)');
            $table->timestamp('expires_at')->nullable()->comment('When the entitlement stops granting access (null = active indefinitely); the active scope filters on this');
            $table->json('metadata')->nullable()->comment('Free-form JSON for source-specific context (e.g. backfill notes, original plan id)');

            $table->foreign('tenant_id')->references('id')->on('tenant')->cascadeOnDelete();
            $table->foreign('billable_module_id')->references('id')->on('billable_module')->cascadeOnDelete();
            $table->foreign('billing_package_id')->references('id')->on('billing_package')->cascadeOnDelete();
            $table->unique(['tenant_id', 'billable_module_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_entitlement');
    }
};
