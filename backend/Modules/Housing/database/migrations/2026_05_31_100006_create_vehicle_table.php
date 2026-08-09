<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use QCH\Core\Models\BaseModel;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle', function (Blueprint $table) {
            $table->comment('Vehicle registered to a residential unit within the cluster');
            BaseModel::columns($table, true);
            $table->foreignUuid('unit_id')->constrained('unit')->cascadeOnDelete()->comment('FK to unit — unit the vehicle is registered to');
            $table->string('plate')->comment('License plate number');
            $table->string('make')->nullable()->comment('Vehicle manufacturer (e.g. Toyota, Honda)');
            $table->string('model')->nullable()->comment('Vehicle model name (e.g. Corolla, Civic)');
            $table->string('color')->nullable()->comment('Vehicle color');
            $table->smallInteger('year')->nullable()->comment('Model year of the vehicle');
        });

        // A plain UNIQUE(tenant_id, plate) keeps reserving the plate of a
        // soft-deleted vehicle, so re-registering that plate fails forever.
        // Scope the uniqueness to live rows only (mirrors unit_identity_unique).
        DB::statement(
            'CREATE UNIQUE INDEX vehicle_plate_unique ON "vehicle" '
            .'(tenant_id, plate) '
            .'WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS vehicle_plate_unique');
        Schema::dropIfExists('vehicle');
    }
};
