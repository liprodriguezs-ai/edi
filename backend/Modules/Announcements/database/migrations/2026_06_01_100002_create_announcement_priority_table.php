<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use QCH\Core\Models\BaseModel;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('announcement_priority')) {
            return;
        }

        Schema::create('announcement_priority', function (Blueprint $table): void {
            $table->comment('Catalog of announcement priority levels (e.g. low, normal, urgent).');
            BaseModel::columns($table, false);
            $table->string('name')->comment('Human-readable priority label.');
            $table->string('color')->nullable()->comment('Hex color used to render this priority badge in the UI.');
            $table->integer('order')->default(0)->comment('Display sort order for this priority.');
            $table->boolean('is_active')->default(true)->comment('Whether this priority is selectable for new announcements.');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcement_priority');
    }
};
