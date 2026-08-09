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
        Schema::create('access_entry_type', function (Blueprint $table): void {
            $table->comment('Catalog of access entry types (pedestrian, vehicle, etc.)');
            BaseModel::columns($table, false);
            $table->string('name')->comment('Display name of the entry type (e.g. Pedestrian, Vehicle, Delivery).');
            $table->integer('order')->default(0)->comment('Sort order for display in dropdowns.');
            $table->boolean('is_active')->default(true)->comment('Whether this entry type is available for selection.');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('access_entry_type');
    }
};
