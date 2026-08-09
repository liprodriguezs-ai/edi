<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use QCH\Core\Models\BaseModel;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billable_module', function (Blueprint $table) {
            $table->comment('Central catalog of sellable modules/features (billing v2). A billable_module groups one or more platform `module` rows and is priced per plan in `plan_module_price`; shared across all tenants');
            BaseModel::columns($table, false);

            $table->string('key')->unique()->comment('Stable machine key referenced by code/seeders (e.g. base, package, amenities)');
            $table->string('name')->comment('Display name shown in billing UI (Spanish label)');
            $table->string('description')->nullable()->comment('Optional description of what the billable module provides');
            $table->unsignedSmallInteger('sort_order')->default(0)->comment('Display order in billing UI (ascending)');
            $table->boolean('is_active')->default(true)->comment('Whether this billable module is available to be priced/sold');
            $table->boolean('is_base')->default(false)->comment('Whether this is the required base module every plan must include (exactly one true)');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billable_module');
    }
};
