<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use QCH\Core\Models\BaseModel;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('chat_channel_type')) {
            return;
        }

        Schema::create('chat_channel_type', function (Blueprint $table): void {
            $table->comment('Catalog of channel types (e.g. general, announcements, direct).');
            BaseModel::columns($table, false);
            $table->string('name')->comment('Human-readable type name.');
            $table->integer('order')->default(0)->comment('Display sort order for this type.');
            $table->boolean('is_active')->default(true)->comment('Whether this type is available for new channels.');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_channel_type');
    }
};
