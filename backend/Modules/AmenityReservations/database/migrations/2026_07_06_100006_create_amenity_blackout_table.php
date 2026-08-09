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
        Schema::create('amenity_blackout', function (Blueprint $table) {
            $table->comment('Date range during which an amenity cannot be reserved (maintenance, holidays, admin block), independent of out_of_service.');
            BaseModel::columns($table, true);

            $table->uuid('amenity_id')->comment('FK to amenity.');
            $table->foreign('amenity_id')->references('id')->on('amenity')->cascadeOnDelete();

            $table->date('start_date')->comment('First date (inclusive) the amenity is blacked out.');
            $table->date('end_date')->comment('Last date (inclusive) the amenity is blacked out.');
            $table->text('reason')->nullable()->comment('Free-text reason shown to admins for this blackout period.');

            $table->index(['amenity_id', 'start_date', 'end_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('amenity_blackout');
    }
};
