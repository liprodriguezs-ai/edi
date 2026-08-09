<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use QCH\Core\Models\BaseModel;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('chat_channel')) {
            return;
        }

        Schema::create('chat_channel', function (Blueprint $table): void {
            $table->comment('Community chat channel; system channels are protected from deletion.');
            BaseModel::columns($table, true);
            $table->string('name')->comment('Display name of the channel.');
            $table->uuid('chat_channel_type_id')->comment('FK to chat_channel_type.');
            $table->string('icon')->nullable()->comment('Optional icon identifier for the channel.');
            $table->text('description')->nullable()->comment('Optional description of the channel purpose.');
            // Target scoping (same pattern as TargetResolver)
            $table->string('target_type')->nullable()->comment('Audience scope: all, by_type, by_tower, etc.');
            $table->jsonb('target_selector')->nullable()->comment('JSON payload that parameterises target_type filtering.');
            // Channel behaviour
            $table->string('mode')->default('everyone_writes')->comment('Write permission mode: everyone_writes or residents_read_only.');
            $table->boolean('is_moderated')->default(false)->comment('Whether posts require moderation approval before becoming visible.');
            $table->boolean('is_system')->default(false)->comment('System channels cannot be deleted by administrators.');
            $table->boolean('is_archived')->default(false)->comment('Archived channels are read-only and hidden from the channel list.');

            $table->foreign('chat_channel_type_id')->references('id')->on('chat_channel_type');

            // Unique channel name per tenant (only for non-deleted rows via partial index)
            $table->unique(['tenant_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_channel');
    }
};
