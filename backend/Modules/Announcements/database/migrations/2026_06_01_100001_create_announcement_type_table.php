<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use QCH\Core\Models\BaseModel;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('announcement_type')) {
            return;
        }

        Schema::create('announcement_type', function (Blueprint $table): void {
            $table->comment('Catalog of announcement types (e.g. notice, maintenance, emergency).');
            BaseModel::columns($table, false);
            $table->string('name')->comment('Human-readable type name.');
            $table->string('icon')->nullable()->comment('Optional icon identifier displayed alongside this type.');
            $table->integer('order')->default(0)->comment('Display sort order for this type.');
            $table->boolean('is_active')->default(true)->comment('Whether this type is selectable for new announcements.');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcement_type');
    }
};
