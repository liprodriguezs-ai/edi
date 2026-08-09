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
        Schema::create('access_verification', function (Blueprint $table): void {
            $table->comment('Catalog of access verification methods');
            BaseModel::columns($table, false);
            $table->string('name')->comment('Display name of the verification method (e.g. QR code, ID document, Facial).');
            $table->integer('order')->default(0)->comment('Sort order for display in dropdowns.');
            $table->boolean('is_active')->default(true)->comment('Whether this verification method is available for selection.');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('access_verification');
    }
};
