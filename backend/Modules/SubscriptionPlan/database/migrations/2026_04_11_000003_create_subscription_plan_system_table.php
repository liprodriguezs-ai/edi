<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_plan_system', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('subscription_plan_id')->comment('FK to subscription_plan');
            $table->uuid('system_id')->comment('FK to system');
            $table->timestamps();

            $table->foreign('subscription_plan_id')->references('id')->on('subscription_plan')->cascadeOnDelete();
            $table->foreign('system_id')->references('id')->on('system')->cascadeOnDelete();
            $table->unique(['subscription_plan_id', 'system_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_plan_system');
    }
};
