<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * chat_message is the highest-volume table of the product and every channel
 * open paginates "where tenant_id=? and chat_channel_id=? order by created_at
 * desc". Postgres does NOT index FK columns automatically, so without this
 * composite index the hottest query degrades linearly per tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_message', function (Blueprint $table): void {
            $table->index(['chat_channel_id', 'created_at']);
            $table->index('reply_to_id');
        });
    }

    public function down(): void
    {
        Schema::table('chat_message', function (Blueprint $table): void {
            $table->dropIndex(['chat_channel_id', 'created_at']);
            $table->dropIndex(['reply_to_id']);
        });
    }
};
