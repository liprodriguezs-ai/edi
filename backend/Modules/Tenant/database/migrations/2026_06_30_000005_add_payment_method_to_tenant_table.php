<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant', function (Blueprint $table) {
            $table->string('payment_method')->default('card')->after('subscription_plan_id')->comment('How the organization pays its EDI subscription: card (Stripe automatic) | transfer | cash (manual, recorded by super-admin). Replaces the legacy is_stripe flag of subscription_plan.');
        });

        // Backfill existing tenants to the default automatic-card method (the
        // column default already covers this, but we make the backfill explicit).
        DB::table('tenant')->update(['payment_method' => 'card']);
    }

    public function down(): void
    {
        Schema::table('tenant', function (Blueprint $table) {
            $table->dropColumn('payment_method');
        });
    }
};
