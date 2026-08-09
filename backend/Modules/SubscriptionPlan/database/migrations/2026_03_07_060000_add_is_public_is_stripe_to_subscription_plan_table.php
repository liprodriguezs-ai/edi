<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_plan', function (Blueprint $table) {
            $table->boolean('is_public')->default(false)->after('is_popular')->comment('Whether the plan is visible on the public pricing page');
            $table->boolean('is_stripe')->default(false)->after('is_public')->comment('Whether this plan is managed and billed through Stripe');
        });
    }

    public function down(): void
    {
        Schema::table('subscription_plan', function (Blueprint $table) {
            $table->dropColumn(['is_public', 'is_stripe']);
        });
    }
};
