<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use QCH\Core\Models\BaseModel;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('chat_message_report')) {
            return;
        }

        Schema::create('chat_message_report', function (Blueprint $table): void {
            $table->comment('Reports submitted by users for inappropriate chat messages.');
            BaseModel::columns($table, true);
            $table->uuid('chat_message_id')->comment('FK to chat_message being reported.');
            $table->uuid('reporter_user_id')->comment('FK to user who submitted the report.');
            $table->string('reason')->comment('Reason provided by the reporter for flagging the message.');
            $table->string('status')->default('pending')->comment('Review lifecycle status: pending or reviewed.');

            $table->foreign('chat_message_id')->references('id')->on('chat_message');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_message_report');
    }
};
