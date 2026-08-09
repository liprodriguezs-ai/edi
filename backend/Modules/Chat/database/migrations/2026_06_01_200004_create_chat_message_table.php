<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use QCH\Core\Models\BaseModel;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('chat_message')) {
            return;
        }

        Schema::create('chat_message', function (Blueprint $table): void {
            $table->comment('Chat messages; messages are soft-hidden for moderation, never physically deleted.');
            BaseModel::columns($table, true);
            $table->uuid('chat_channel_id')->comment('FK to chat_channel.');
            $table->uuid('user_id')->comment('FK to user (central table; bypasses UserTenantScope).');
            $table->text('body')->comment('Message text content.');
            $table->uuid('reply_to_id')->nullable()->comment('FK to chat_message; references the parent message for threading.');
            $table->boolean('hidden')->default(false)->comment('When true the message is hidden via moderation but never physically deleted.');
            $table->timestamp('edited_at')->nullable()->comment('Timestamp when the message body was last edited.');

            $table->foreign('chat_channel_id')->references('id')->on('chat_channel');
        });

        // Self-referencing FK for threading — must be added after table exists
        Schema::table('chat_message', function (Blueprint $table): void {
            $table->foreign('reply_to_id')->references('id')->on('chat_message')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_message');
    }
};
