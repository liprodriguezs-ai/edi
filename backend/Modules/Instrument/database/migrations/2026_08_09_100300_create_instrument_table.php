<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use QCH\Core\Models\BaseModel;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * CENTRAL registry anchor (D1): a screening instrument whose content is
     * versioned; EDI is the first (and only, for now). Fixed UUID constant,
     * handcrafted -- not bulk content.
     */
    public function up(): void
    {
        Schema::create('instrument', function (Blueprint $table) {
            $table->comment('Screening instrument (test) whose content is versioned; EDI is the first');
            BaseModel::columns($table, false);
            $table->string('key')->unique()->comment("Machine-readable slug ('edi').");
            $table->string('name')->comment('Human-readable name shown in the UI.');
            $table->text('description')->nullable()->comment('Optional free-text description.');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('instrument');
    }
};
