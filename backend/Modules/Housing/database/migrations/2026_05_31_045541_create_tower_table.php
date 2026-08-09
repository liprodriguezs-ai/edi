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
        Schema::create('tower', function (Blueprint $table) {
            $table->comment('Building tower within a residential cluster');
            BaseModel::columns($table, true);
            $table->string('name')->comment('Tower or building name');
            $table->integer('floors_count')->nullable()->comment('Total number of floors in the tower');
            $table->foreignUuid('section_id')->nullable()->constrained('section')->nullOnDelete()->comment('FK to section — optional zone grouping');
            $table->integer('order')->default(0)->comment('Display sort order');
            $table->unique(['tenant_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tower');
    }
};
