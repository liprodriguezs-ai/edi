<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use QCH\Core\Models\BaseModel;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('chat_reaction')) {
            return;
        }

        Schema::create('chat_reaction', function (Blueprint $table): void {
            $table->comment('Emoji reactions to chat messages; one reaction per user+emoji per message.');
            BaseModel::columns($table, true);
            $table->uuid('chat_message_id')->comment('FK to chat_message.');
            $table->uuid('user_id')->comment('FK to user who reacted.');
            $table->string('emoji')->comment('Emoji character or shortcode representing the reaction.');

            $table->foreign('chat_message_id')->references('id')->on('chat_message');

            // One reaction per user+emoji per message per tenant
            $table->unique(['tenant_id', 'chat_message_id', 'user_id', 'emoji']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_reaction');
    }
};
