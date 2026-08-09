<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use QCH\Core\Models\BaseModel;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_package', function (Blueprint $table) {
            $table->comment('Central public catalog of billing packages (base plan + add-ons) shown on the landing pricing page; shared across all tenants');
            BaseModel::columns($table, false);

            $table->string('key')->unique()->comment('Stable machine key referenced by code/landing (e.g. base, package)');
            $table->string('name')->comment('Display name shown on the pricing page (Spanish label)');
            $table->string('description')->nullable()->comment('Optional marketing description shown under the package name');
            $table->string('type')->comment('Package role: base (required core plan) | addon (optional extra)');
            $table->string('pricing_type')->comment('How the package is priced: per_unit (multiplied by units) | fixed (flat amount)');
            $table->unsignedSmallInteger('sort_order')->default(0)->comment('Display order on the pricing page (ascending)');
            $table->boolean('is_active')->default(true)->comment('Whether the package is published and quotable');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_package');
    }
};
