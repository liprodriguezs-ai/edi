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
        Schema::create('municipality', function (Blueprint $table) {
            BaseModel::columns($table);
            $table->string('name')->comment('Official name of the municipality');
            $table->string('code')->comment('Government-assigned municipality code (e.g. INEGI key)');
            $table->string('state_code')->comment('State code the municipality belongs to');
            $table->uuid('state_id')->nullable()->comment('Optional FK to a state record if a state catalog exists');
            $table->foreignUuid('country_id')->references('id')->on('country')->comment('FK to country');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('municipality');
    }
};
