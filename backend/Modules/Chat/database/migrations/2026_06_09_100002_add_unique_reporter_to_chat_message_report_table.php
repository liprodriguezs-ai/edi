<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One report per user per message (§13.5): prevents duplicate-report loops that
 * push-spam the board. The service rejects duplicates with a 422 before this
 * constraint fires — the index is the data-integrity backstop.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_message_report', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'chat_message_id', 'reporter_user_id']);
        });
    }

    public function down(): void
    {
        Schema::table('chat_message_report', function (Blueprint $table): void {
            $table->dropUnique(['tenant_id', 'chat_message_id', 'reporter_user_id']);
        });
    }
};
