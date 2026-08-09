<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use QCH\Core\Models\BaseModel;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('chat_channel_member')) {
            return;
        }

        Schema::create('chat_channel_member', function (Blueprint $table): void {
            $table->comment('User membership in a chat channel with notification preferences.');
            BaseModel::columns($table, true);
            $table->uuid('chat_channel_id')->comment('FK to chat_channel.');
            $table->uuid('user_id')->comment('FK to user (central table; bypasses UserTenantScope).');
            $table->string('notif_pref')->default('all')->comment('Notification preference: all, mentions, or none.');
            $table->timestamp('last_read_at')->nullable()->comment('Timestamp of the last message the user read in this channel.');

            $table->foreign('chat_channel_id')->references('id')->on('chat_channel');

            $table->unique(['tenant_id', 'chat_channel_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_channel_member');
    }
};
