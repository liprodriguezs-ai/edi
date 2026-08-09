<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use QCH\Core\Models\BaseModel;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('charge_concept_category', function (Blueprint $table) {
            // Admin-editable catalog that groups charge concepts (e.g. maintenance,
            // water, security). Created without tenant_id here; the
            // make_editable_catalogs_tenant_scoped migration converts it to
            // per-cluster tenant-scoped (tenant_id + key + unique(tenant_id, name)),
            // alongside the other 11 self-administrable catalogs.
            $table->comment('Admin-editable catalog grouping charge concepts; converted to tenant-scoped per cluster by make_editable_catalogs_tenant_scoped.');
            BaseModel::columns($table, false);
            $table->string('name')->comment('Category name shown in concept creation UI.');
            $table->integer('order')->default(0)->comment('Display sort order.');
            $table->boolean('is_active')->default(true)->comment('Whether this category is selectable.');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('charge_concept_category');
    }
};
