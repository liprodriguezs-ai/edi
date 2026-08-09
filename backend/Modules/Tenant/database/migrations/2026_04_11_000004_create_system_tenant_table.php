<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_tenant', function (Blueprint $table) {
            $table->comment('Pivot assigning which systems (functional modules) are enabled for each tenant.');
            $table->uuid('id')->primary();
            $table->uuid('system_id')->comment('FK to system — the system being assigned to this tenant.');
            $table->uuid('tenant_id')->comment('FK to tenant — the organization receiving access to this system.');
            $table->boolean('is_addon')->default(false)->comment('Whether this system is an optional add-on rather than a base plan inclusion.');
            $table->timestamps();

            $table->foreign('system_id')->references('id')->on('system')->cascadeOnDelete();
            $table->foreign('tenant_id')->references('id')->on('tenant')->cascadeOnDelete();
            $table->unique(['system_id', 'tenant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_tenant');
    }
};
