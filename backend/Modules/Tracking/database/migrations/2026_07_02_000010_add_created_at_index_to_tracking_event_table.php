<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * tracking_event had no index on created_at — the only existing index was the
 * unique(event_id, channel) pair. The daily `model:prune` sweep (routes/console.php,
 * TrackingEvent::prunable()) filters on `created_at < cutoff`, which without this
 * index would force a full table scan as the log grows from public, anonymous
 * /api/track traffic.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracking_event', function (Blueprint $table): void {
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('tracking_event', function (Blueprint $table): void {
            $table->dropIndex(['created_at']);
        });
    }
};
