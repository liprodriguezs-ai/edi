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
        Schema::table('user', function (Blueprint $table) {
            $table->string('stripe_id')->nullable()->index()->comment('Stripe Customer ID (cus_xxxx)');
            $table->string('pm_type')->nullable()->comment('Default payment method type (e.g., "card", "sepa_debit")');
            $table->string('pm_last_four', 4)->nullable()->comment('Last four digits of the default payment method');
            $table->timestamp('trial_ends_at')->nullable()->comment('Timestamp when the generic trial period ends');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user', function (Blueprint $table) {
            $table->dropIndex([
                'stripe_id',
            ]);

            $table->dropColumn([
                'stripe_id',
                'pm_type',
                'pm_last_four',
                'trial_ends_at',
            ]);
        });
    }
};
