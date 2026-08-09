<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use QCH\Core\Models\BaseModel;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_module', function (Blueprint $table) {
            $table->comment('Billing v2: interval-independent link of a billable_module offered within a plan (billing_package); owns the per-(plan,module) flags formerly duplicated per interval on plan_module_price');
            BaseModel::columns($table, false); // CENTRAL — no BelongsToTenant

            $table->uuid('billing_package_id')->comment('FK to billing_package (the plan)');
            $table->uuid('billable_module_id')->comment('FK to billable_module (the sellable feature)');
            $table->boolean('is_free')->default(false)->comment('Whether this module is included at no cost in the plan (price pending / free)');
            $table->unsignedSmallInteger('trial_days')->nullable()->comment("Per-(plan,module) trial override; fallback config('billing.trial_days')");

            $table->foreign('billing_package_id')->references('id')->on('billing_package')->cascadeOnDelete();
            $table->foreign('billable_module_id')->references('id')->on('billable_module')->cascadeOnDelete();
            $table->unique(['billing_package_id', 'billable_module_id']);
            $table->index('billable_module_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_module');
    }
};
