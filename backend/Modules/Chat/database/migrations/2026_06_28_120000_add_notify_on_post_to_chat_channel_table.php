<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_channel', function (Blueprint $table): void {
            $table->boolean('notify_on_post')
                ->default(true)
                ->after('is_archived')
                ->comment('Whether posting a message in this channel dispatches notifications (honored by the notification engine in Fase 3).');
        });
    }

    public function down(): void
    {
        Schema::table('chat_channel', function (Blueprint $table): void {
            $table->dropColumn('notify_on_post');
        });
    }
};
