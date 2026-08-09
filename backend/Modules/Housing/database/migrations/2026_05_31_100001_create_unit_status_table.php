<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use QCH\Core\Models\BaseModel;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unit_status', function (Blueprint $table) {
            $table->comment('Catalog of unit occupancy/physical statuses (global, not tenant-scoped)');
            BaseModel::columns($table, false);
            $table->string('name')->comment('Status label (e.g. active, vacant, under_construction, inactive)');
            $table->string('color')->default('#6B7280')->comment('Hex color for UI display');
            $table->integer('order')->default(0)->comment('Display sort order');
            $table->boolean('is_active')->default(true)->comment('Whether this status is available for assignment');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unit_status');
    }
};
