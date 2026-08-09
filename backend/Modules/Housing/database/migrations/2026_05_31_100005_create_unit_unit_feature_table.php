<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use QCH\Core\Models\BaseModel;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unit_unit_feature', function (Blueprint $table) {
            $table->comment('Pivot assigning features/amenities to a unit');
            BaseModel::columns($table, true);
            $table->foreignUuid('unit_id')->constrained('unit')->cascadeOnDelete()->comment('FK to unit');
            $table->foreignUuid('unit_feature_id')->constrained('unit_feature')->cascadeOnDelete()->comment('FK to unit_feature');
            $table->unique(['tenant_id', 'unit_id', 'unit_feature_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unit_unit_feature');
    }
};
