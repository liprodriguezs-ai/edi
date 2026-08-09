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
        Schema::create('subscription_plan', function (Blueprint $table) {
            BaseModel::columns($table);

            $table->string('name')->comment('Display name of the subscription plan');
            $table->text('description')->nullable()->comment('Optional marketing description shown to prospective customers');
            $table->unsignedBigInteger('price')->comment('Minor units (cents); e.g. 150000 = $1,500.00 MXN');
            $table->string('currency', 3)->default('mxn')->comment('ISO 4217 currency code (lowercase)');
            $table->string('interval')->default('month')->comment('Billing interval: month | year');
            $table->unsignedSmallInteger('interval_count')->default(1)->comment('Number of intervals between billings (e.g. 3 = every 3 months)');
            $table->unsignedSmallInteger('trial_days')->default(0)->comment('Free-trial period in days before the first charge');
            $table->json('features')->nullable()->comment('JSON array of feature strings shown on the pricing page');
            $table->unsignedSmallInteger('sort_order')->default(0)->comment('Display order on the pricing page (ascending)');
            $table->boolean('is_popular')->default(false)->comment('Highlights this plan as the recommended / popular option');

            $table->string('stripe_product_id')->nullable()->unique()->comment('Stripe product ID linked to this plan');
            $table->string('stripe_price_id')->nullable()->unique()->comment('Stripe price ID used for checkout and subscription creation');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_plan');
    }
};
