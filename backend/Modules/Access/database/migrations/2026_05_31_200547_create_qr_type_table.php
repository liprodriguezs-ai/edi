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
        Schema::create('qr_type', function (Blueprint $table): void {
            $table->comment('Catalog of visitor QR types');
            BaseModel::columns($table, false);
            $table->string('name')->comment('Display name of the QR code type (e.g. Single-use, Permanent).');
            $table->integer('order')->default(0)->comment('Sort order for display in dropdowns.');
            $table->boolean('is_active')->default(true)->comment('Whether this QR type is available for selection.');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('qr_type');
    }
};
