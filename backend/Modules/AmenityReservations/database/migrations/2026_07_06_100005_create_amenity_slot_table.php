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
        Schema::create('amenity_slot', function (Blueprint $table) {
            $table->comment('Fixed reservable time slot for an amenity booked in by_slot mode (e.g. Tuesdays 18:00-19:00).');
            BaseModel::columns($table, true);

            $table->uuid('amenity_id')->comment('FK to amenity.');
            $table->foreign('amenity_id')->references('id')->on('amenity')->cascadeOnDelete();

            $table->unsignedTinyInteger('weekday')->comment('Weekday this slot occurs on (0=Sunday..6=Saturday).');
            $table->time('start_time')->comment('Slot start time (tenant local time).');
            $table->time('end_time')->comment('Slot end time (tenant local time).');
            $table->integer('capacity_override')->nullable()->comment('Overrides amenity.capacity for this specific slot; null falls back to the amenity default.');

            $table->index(['amenity_id', 'weekday']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('amenity_slot');
    }
};
