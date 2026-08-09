<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant', function (Blueprint $table) {
            $table->foreignUuid('tenant_payment_type_id')
                ->nullable()
                ->constrained('tenant_payment_type')
                ->comment('FK to tenant_payment_type — billing cycle chosen by this tenant.');

            $table->foreignUuid('subscription_plan_id')
                ->nullable()
                ->constrained('subscription_plan')
                ->comment('FK to subscription_plan — active subscription plan for this tenant.');
        });
    }

    public function down(): void
    {
        Schema::table('tenant', function (Blueprint $table) {
            $table->dropForeign(['subscription_plan_id']);
            $table->dropColumn('subscription_plan_id');
            $table->dropForeign(['tenant_payment_type_id']);
            $table->dropColumn('tenant_payment_type_id');
        });
    }
};
