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
        Schema::create('board_role', function (Blueprint $table) {
            $table->comment('Catalog of directorate roles (President, Treasurer, Secretary, Vocal, Oversight Committee).');
            BaseModel::columns($table, false);
            $table->string('name')->comment('Human-readable role name.');
            $table->string('spatie_role_slug')->comment('Spatie role slug granted to the user when this board role is assigned.');
            $table->integer('order')->default(0)->comment('Display sort order for this role.');
            $table->boolean('is_active')->default(true)->comment('Whether this role is assignable to board members.');
            $table->unique('name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('board_role');
    }
};
