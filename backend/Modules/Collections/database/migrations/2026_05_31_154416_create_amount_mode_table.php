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
        Schema::create('amount_mode', function (Blueprint $table) {
            $table->comment('Global catalog defining how a charge concept calculates its amount.');
            BaseModel::columns($table, false);
            $table->string('name')->comment('Human-readable mode label (e.g. fixed_required, by_share).');
            $table->integer('order')->default(0)->comment('Display sort order.');
            $table->boolean('is_active')->default(true)->comment('Whether this mode is selectable.');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('amount_mode');
    }
};
