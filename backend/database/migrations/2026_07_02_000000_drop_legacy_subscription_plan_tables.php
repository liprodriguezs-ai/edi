<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use QCH\Core\Models\BaseModel;

/**
 * Billing v2 cleanup: retire the legacy `subscription_plan` model entirely.
 *
 * Access is now granted by tenant_entitlement + billing_package (PlanPricingService
 * / EntitlementService); nothing reads subscription_plan anymore. This drops the FK
 * column tenant.subscription_plan_id, the subscription_plan_system pivot, and the
 * subscription_plan table itself. Ordered so the referencing objects go first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant', function (Blueprint $table) {
            $table->dropConstrainedForeignId('subscription_plan_id');
        });

        Schema::dropIfExists('subscription_plan_system');
        Schema::dropIfExists('subscription_plan');
    }

    public function down(): void
    {
        Schema::create('subscription_plan', function (Blueprint $table) {
            $table->comment('Legacy subscription plan catalog (retired in billing v2)');
            BaseModel::columns($table);

            $table->string('name')->comment('Display name of the subscription plan');
            $table->text('description')->nullable()->comment('Optional marketing description shown to prospective customers');
            $table->unsignedBigInteger('price')->comment('Minor units (cents); e.g. 150000 = $1,500.00 MXN');
            $table->string('currency', 3)->default('mxn')->comment('ISO 4217 currency code (lowercase)');
            $table->string('interval')->default('month')->comment('Billing interval: month | year');
            $table->unsignedSmallInteger('interval_count')->default(1)->comment('Number of intervals between billings');
            $table->unsignedSmallInteger('trial_days')->default(0)->comment('Free-trial period in days before the first charge');
            $table->unsignedInteger('unit_limit')->nullable()->comment('Max active units the plan allows (null = unlimited)');
            $table->json('features')->nullable()->comment('JSON array of feature strings shown on the pricing page');
            $table->unsignedSmallInteger('sort_order')->default(0)->comment('Display order on the pricing page (ascending)');
            $table->boolean('is_popular')->default(false)->comment('Highlights this plan as the recommended / popular option');
            $table->boolean('is_public')->default(true)->comment('Whether the plan is shown in the public catalog');
            $table->boolean('is_stripe')->default(true)->comment('Whether the plan is billed through Stripe');
            $table->string('stripe_product_id')->nullable()->unique()->comment('Stripe product ID linked to this plan');
            $table->string('stripe_price_id')->nullable()->unique()->comment('Stripe price ID used for checkout and subscription creation');
        });

        Schema::create('subscription_plan_system', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('subscription_plan_id')->comment('FK to subscription_plan');
            $table->uuid('system_id')->comment('FK to system');
            $table->timestamps();

            $table->foreign('subscription_plan_id')->references('id')->on('subscription_plan')->cascadeOnDelete();
            $table->foreign('system_id')->references('id')->on('system')->cascadeOnDelete();
            $table->unique(['subscription_plan_id', 'system_id']);
        });

        Schema::table('tenant', function (Blueprint $table) {
            $table->foreignUuid('subscription_plan_id')
                ->nullable()
                ->constrained('subscription_plan')
                ->comment('FK to subscription_plan — active subscription plan for this tenant.');
        });
    }
};
