<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_channel', function (Blueprint $table): void {
            $table->string('write_target_type')
                ->nullable()
                ->after('target_selector')
                ->comment('WHO MAY WRITE: target_type (same TargetResolver vocabulary as the read target) + board; replaces the binary mode.');
            $table->jsonb('write_target_selector')
                ->nullable()
                ->after('write_target_type')
                ->comment('JSON selector that parameterises write_target_type (tower_ids/floors/unit_ids/etc.).');
        });

        // Backfill the write target from the (now removed) binary mode: read-only
        // channels (only the board posts) → board; everyone_writes → all.
        DB::table('chat_channel')->where('mode', 'residents_read_only')->update(['write_target_type' => 'board']);
        DB::table('chat_channel')->where('mode', '!=', 'residents_read_only')->update(['write_target_type' => 'all']);

        // The binary mode is fully replaced by the dual read/write target.
        Schema::table('chat_channel', function (Blueprint $table): void {
            $table->dropColumn('mode');
        });
    }

    public function down(): void
    {
        Schema::table('chat_channel', function (Blueprint $table): void {
            $table->string('mode')->default('everyone_writes')->comment('Write permission mode: everyone_writes or residents_read_only.');
        });

        // Best-effort reverse mapping: board-only write → read-only, everything else → everyone_writes.
        DB::table('chat_channel')->where('write_target_type', 'board')->update(['mode' => 'residents_read_only']);
        DB::table('chat_channel')->where('write_target_type', '!=', 'board')->update(['mode' => 'everyone_writes']);

        Schema::table('chat_channel', function (Blueprint $table): void {
            $table->dropColumn(['write_target_type', 'write_target_selector']);
        });
    }
};
