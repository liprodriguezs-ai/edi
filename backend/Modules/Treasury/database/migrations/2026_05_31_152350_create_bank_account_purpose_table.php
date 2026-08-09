<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use QCH\Core\Models\BaseModel;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_account_purpose', function (Blueprint $table) {
            $table->comment('Catalog of bank account purposes (e.g. maintenance, reserves, operations).');
            BaseModel::columns($table, false);
            $table->string('name')->comment('Human-readable purpose name.');
            $table->integer('order')->default(0)->comment('Display sort order for this purpose.');
            $table->boolean('is_active')->default(true)->comment('Whether this purpose is selectable for new accounts.');
            $table->unique('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_account_purpose');
    }
};
