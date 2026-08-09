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
        Schema::create('project_contribution_status', function (Blueprint $table) {
            BaseModel::columns($table, false);
            $table->string('name')->comment('Display name of the contribution status (e.g. pending, ideal, generous, declined).');
            $table->string('color')->nullable()->comment('Hex or Tailwind color token used in the UI badge.');
            $table->integer('order')->default(0)->comment('Display order for sorting in dropdowns.');
            $table->boolean('is_active')->default(true)->comment('Whether this status is available for selection.');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_contribution_status');
    }
};
