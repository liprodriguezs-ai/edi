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
        Schema::create('unit_feature', function (Blueprint $table) {
            $table->comment('Catalog of unit characteristics and amenities per tenant');
            BaseModel::columns($table, true);
            $table->string('name')->comment('Feature or amenity label (e.g. Pool, Garden, Parking)');
            $table->boolean('is_active')->default(true)->comment('Whether this feature is available for assignment');
            $table->unique(['tenant_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('unit_feature');
    }
};
