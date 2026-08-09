<?php

declare(strict_types=1);

namespace Tests\Feature\Tracking;

use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Tracking\Models\TrackingEvent;
use Tests\TestCase;

/**
 * Coverage for TrackingEvent::prunable(): the daily `model:prune` sweep
 * (routes/console.php) must hard-delete rows older than the config-driven
 * retention window and leave recent rows untouched.
 *
 * BaseModel enables SoftDeletes on every model, but MassPrunable::pruneAll()
 * detects that (Model::isSoftDeletable()) and calls forceDelete() instead of a
 * soft delete — assertDatabaseMissing (a raw row check, not scoped by
 * SoftDeletes) is what actually proves the row is gone, not just flagged.
 */
final class TrackingEventPruningTest extends TestCase
{
    use RefreshDatabase;

    private function makeEventAt(DateTimeInterface $createdAt): TrackingEvent
    {
        $event = TrackingEvent::create([
            'event_name' => 'Lead',
            'event_id' => (string) Str::uuid(),
            'channel' => 'log',
            'status' => TrackingEvent::STATUS_SENT,
        ]);

        // created_at is not fillable and BaseModel::columns() sets useCurrent();
        // backdate it directly. Model::updateTimestamps() only auto-sets
        // created_at on INSERT (!$this->exists), so this survives the save().
        $event->created_at = $createdAt;
        $event->saveQuietly();

        return $event;
    }

    public function test_prune_hard_deletes_rows_older_than_configured_retention(): void
    {
        config(['tracking.retention_days' => 90]);

        $old = $this->makeEventAt(now()->subDays(91));
        $recent = $this->makeEventAt(now()->subDays(10));

        $deleted = (new TrackingEvent)->pruneAll();

        $this->assertSame(1, $deleted);
        $this->assertDatabaseMissing('tracking_event', ['id' => $old->id]);
        $this->assertDatabaseHas('tracking_event', ['id' => $recent->id]);
    }

    public function test_prune_leaves_rows_within_the_retention_boundary_alone(): void
    {
        config(['tracking.retention_days' => 30]);

        $withinRetention = $this->makeEventAt(now()->subDays(29));

        $deleted = (new TrackingEvent)->pruneAll();

        $this->assertSame(0, $deleted);
        $this->assertDatabaseHas('tracking_event', ['id' => $withinRetention->id]);
    }
}
