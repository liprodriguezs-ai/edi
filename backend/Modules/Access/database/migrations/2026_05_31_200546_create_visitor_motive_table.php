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
        Schema::create('visitor_motive', function (Blueprint $table): void {
            $table->comment('Catalog of visit motives (delivery, social, professional, etc.)');
            BaseModel::columns($table, false);
            $table->string('name')->comment('Display name of the visit motive (e.g. Delivery, Social visit).');
            $table->integer('order')->default(0)->comment('Sort order for display in dropdowns.');
            $table->boolean('is_active')->default(true)->comment('Whether this motive is available for selection.');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('visitor_motive');
    }
};
