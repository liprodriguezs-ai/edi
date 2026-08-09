<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_tenant', function (Blueprint $table) {
            $table->comment('Pivot controlling which individual modules are enabled per tenant.');
            if (env('DB_CONNECTION') === 'pgsql') {
                $table->uuid('id')->primary()->default(DB::raw('(gen_random_uuid())'));
            } else {
                $table->uuid('id')->primary()->default(DB::raw('(UUID())'));
            }

            $table->uuid('module_id')->comment('FK to module — the specific module being toggled for this tenant.');
            $table->uuid('tenant_id')->comment('FK to tenant — the cluster whose module access is being configured.');
            $table->boolean('enabled')->default(true)->comment('Whether this module is currently enabled for the tenant.');
            $table->timestamps();

            $table->foreign('module_id')->references('id')->on('module')->cascadeOnDelete();
            $table->foreign('tenant_id')->references('id')->on('tenant')->cascadeOnDelete();
            $table->unique(['module_id', 'tenant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_tenant');
    }
};
