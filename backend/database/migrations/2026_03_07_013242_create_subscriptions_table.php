
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
        Schema::create('subscriptions', function (Blueprint $table) {
            if (env('DB_CONNECTION') === 'pgsql') {
                $table->uuid('id')->primary()->unique()->default(DB::raw('(uuid_generate_v4())'));
            } else {
                $table->uuid('id')->primary()->unique()->default(DB::raw('(UUID())'));
            }
            $table->foreignUuid('user_id')->constrained('user')->comment('FK to user (subscriber)');
            $table->string('type')->comment('Subscription type/name (e.g., "default")');
            $table->string('stripe_id')->unique()->comment('Stripe Subscription ID (sub_xxxx)');
            $table->string('stripe_status')->comment('Stripe subscription status (active, canceled, trialing, etc.)');
            $table->string('stripe_price')->nullable()->comment('Stripe Price ID of the subscribed plan');
            $table->integer('quantity')->nullable()->comment('Quantity for per-seat billing');
            $table->timestamp('trial_ends_at')->nullable()->comment('Timestamp when the subscription trial ends');
            $table->timestamp('ends_at')->nullable()->comment('Timestamp when the subscription ends (grace period end)');
            $table->timestamps();

            $table->index(['user_id', 'stripe_status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
