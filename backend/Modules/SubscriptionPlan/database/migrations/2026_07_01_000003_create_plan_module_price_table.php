<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use QCH\Core\Models\BaseModel;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_module_price', function (Blueprint $table) {
            $table->comment('Billing v2 pricing matrix: fixed price of a billable_module within a plan (billing_package) for a given interval; one row per (plan, module, interval)');
            BaseModel::columns($table, false);

            $table->uuid('billing_package_id')->comment('FK to billing_package (the plan)');
            $table->uuid('billable_module_id')->comment('FK to billable_module (the sellable feature)');
            $table->string('interval')->comment('Billing interval this price applies to: month | year');
            $table->bigInteger('unit_amount')->comment('precio fijo del módulo en el plan (minor units/cents)');
            $table->string('currency', 3)->default('MXN')->comment('ISO 4217 currency code (uppercase)');
            $table->string('stripe_price_id')->nullable()->comment('Stripe price ID linked to this plan/module/interval (later slice checkout)');
            $table->string('stripe_product_id')->nullable()->comment('Stripe product ID linked to this billable module (later slice checkout)');
            $table->boolean('is_free')->default(false)->comment('Whether this module is included at no cost in the plan (price pending / free)');
            $table->boolean('is_active')->default(true)->comment('Whether this price row is published and quotable');
            $table->unsignedSmallInteger('trial_days')->nullable()->comment("override; fallback config('billing.trial_days')");

            $table->foreign('billing_package_id')->references('id')->on('billing_package')->cascadeOnDelete();
            $table->foreign('billable_module_id')->references('id')->on('billable_module')->cascadeOnDelete();
            $table->unique(['billing_package_id', 'billable_module_id', 'interval']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_module_price');
    }
};
