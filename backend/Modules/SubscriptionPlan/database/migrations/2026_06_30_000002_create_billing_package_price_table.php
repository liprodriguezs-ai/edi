<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use QCH\Core\Models\BaseModel;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_package_price', function (Blueprint $table) {
            $table->comment('Per-interval pricing for a billing package (one row per month/year), including optional usage tiers and Stripe references');
            BaseModel::columns($table, false);

            $table->uuid('billing_package_id')->comment('FK to billing_package');
            $table->string('interval')->comment('Billing interval this price applies to: month | year');
            $table->bigInteger('unit_amount')->comment('Base price in minor units (cents); per_unit packages multiply this by units, fixed packages charge it flat, banded packages use it only as a fallback');
            $table->string('currency', 3)->default('MXN')->comment('ISO 4217 currency code (uppercase)');
            $table->string('tier_mode')->nullable()->comment('Tiered pricing strategy when tiers is set: flat | graduated | volume | banded (null = no tiers)');
            $table->json('tiers')->nullable()->comment('JSON array of pricing tiers [{up_to:int|null, unit_amount:int, flat_amount?:int, label?:string, proposed?:bool}]; up_to=null means the unbounded top tier. banded mode uses flat_amount (fixed tariff per band) + unit_amount as per-unit overage above the previous band ceiling');
            $table->string('stripe_price_id')->nullable()->comment('Stripe price ID linked to this interval (Phase 2 checkout)');
            $table->string('stripe_product_id')->nullable()->comment('Stripe product ID linked to this package (Phase 2 checkout)');
            $table->boolean('is_free')->default(false)->comment('Whether this interval is offered at no cost');
            $table->boolean('is_active')->default(true)->comment('Whether this price is published and quotable');

            $table->foreign('billing_package_id')->references('id')->on('billing_package')->cascadeOnDelete();
            $table->unique(['billing_package_id', 'interval']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_package_price');
    }
};
