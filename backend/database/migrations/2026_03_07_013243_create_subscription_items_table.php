<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('subscription_items', function (Blueprint $table) {
            if (env('DB_CONNECTION') === 'pgsql') {
                $table->uuid('id')->primary()->unique()->default(DB::raw('(uuid_generate_v4())'));
            } else {
                $table->uuid('id')->primary()->unique()->default(DB::raw('(UUID())'));
            }
            $table->foreignUuid('subscription_id')->comment('FK to subscriptions');
            $table->string('stripe_id')->unique()->comment('Stripe Subscription Item ID (si_xxxx)');
            $table->string('stripe_product')->comment('Stripe Product ID this item belongs to');
            $table->string('stripe_price')->comment('Stripe Price ID for this subscription item');
            $table->integer('quantity')->nullable()->comment('Quantity for this subscription item');
            $table->timestamps();

            $table->index(['subscription_id', 'stripe_price']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_items');
    }
};
