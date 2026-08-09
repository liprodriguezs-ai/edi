<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Harden notification_user (§17.2 — notifications-14).
 *
 * The original table had user_id nullable WITHOUT a foreign key and no unique on
 * (notification_id, user_id): dedup relied solely on firstOrCreate, which is not
 * atomic under concurrency (two queued jobs could double a center row and the
 * unread badge). This migration:
 *
 * 1. Deduplicates LIVE rows per (notification_id, user_id), keeping the oldest row
 *    and propagating is_read=true if any duplicate was already read.
 * 2. Removes orphan rows whose user_id no longer exists (FK pre-condition).
 * 3. Adds FK user_id -> user (nullOnDelete — users are soft-deleted in practice,
 *    the FK guards against future hard deletes).
 * 4. Adds a PARTIAL unique index on (notification_id, user_id) WHERE deleted_at IS
 *    NULL — partial so a member who deleted a center row can receive the same
 *    notification row again without colliding with the soft-deleted one.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->deduplicateLiveRows();

        DB::table('notification_user')
            ->whereNotNull('user_id')
            ->whereNotIn('user_id', DB::table('user')->select('id'))
            ->delete();

        Schema::table('notification_user', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('user')->nullOnDelete();
        });

        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS "notification_user_notification_id_user_id_unique" '
            .'ON "notification_user" ("notification_id", "user_id") WHERE "deleted_at" IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS "notification_user_notification_id_user_id_unique"');

        Schema::table('notification_user', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });
    }

    /**
     * Keep ONE live row per (notification_id, user_id): the oldest one. If any
     * duplicate was already read, the surviving row keeps is_read = true so the
     * member's badge does not resurrect. Duplicates are junk produced by a race —
     * they are removed physically on purpose.
     */
    private function deduplicateLiveRows(): void
    {
        $duplicateGroups = DB::table('notification_user')
            ->select('notification_id', 'user_id')
            ->whereNull('deleted_at')
            ->whereNotNull('user_id')
            ->groupBy('notification_id', 'user_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicateGroups as $group) {
            $rows = DB::table('notification_user')
                ->where('notification_id', $group->notification_id)
                ->where('user_id', $group->user_id)
                ->whereNull('deleted_at')
                ->orderBy('created_at')
                ->orderBy('id')
                ->get(['id', 'is_read']);

            $keep = $rows->first();
            $discardIds = $rows->slice(1)->pluck('id');

            if (! $keep->is_read && $rows->contains(fn (object $row): bool => (bool) $row->is_read)) {
                DB::table('notification_user')->where('id', $keep->id)->update(['is_read' => true]);
            }

            DB::table('notification_user')->whereIn('id', $discardIds)->delete();
        }
    }
};
