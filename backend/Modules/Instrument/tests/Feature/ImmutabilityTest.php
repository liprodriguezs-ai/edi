<?php

declare(strict_types=1);

namespace Modules\Instrument\Tests\Feature;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Instrument\Exceptions\ImmutableInstrumentVersionException;
use Modules\Instrument\Models\AgeGroup;
use Modules\Instrument\Models\Instrument;
use Modules\Instrument\Models\InstrumentVersion;
use Modules\Instrument\Models\ScreeningItem;
use Tests\TestCase;

/**
 * REQ-INS-002 (design D5) immutability matrix (task 2.7): update/delete/
 * restore on published content throws; draft content is writable;
 * is_default may still change after publish, but the partial unique index
 * enforces at most one default per instrument.
 */
final class ImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_draft_content_is_editable(): void
    {
        $version = InstrumentVersion::factory()->create();
        $group = AgeGroup::factory()->create(['instrument_version_id' => $version->id]);

        $group->name = 'GRUPO 1 (editado)';
        $group->save();

        $this->assertDatabaseHas('age_group', ['id' => $group->id, 'name' => 'GRUPO 1 (editado)']);
    }

    public function test_updating_a_published_items_question_throws(): void
    {
        $version = InstrumentVersion::factory()->create();
        $group = AgeGroup::factory()->create(['instrument_version_id' => $version->id]);
        $item = ScreeningItem::factory()->create([
            'instrument_version_id' => $version->id,
            'age_group_id' => $group->id,
            'question' => 'original',
        ]);

        $this->publish($version);

        $this->expectException(ImmutableInstrumentVersionException::class);
        $item->question = 'tampered';
        $item->save();
    }

    public function test_deleting_a_published_age_group_throws(): void
    {
        $version = InstrumentVersion::factory()->create();
        $group = AgeGroup::factory()->create(['instrument_version_id' => $version->id]);

        $this->publish($version);

        $this->expectException(ImmutableInstrumentVersionException::class);
        $group->delete();
    }

    public function test_creating_content_under_an_already_published_version_throws(): void
    {
        $version = InstrumentVersion::factory()->published()->create();

        $this->expectException(ImmutableInstrumentVersionException::class);
        AgeGroup::factory()->create(['instrument_version_id' => $version->id]);
    }

    public function test_restoring_a_soft_deleted_row_of_a_published_version_throws(): void
    {
        $version = InstrumentVersion::factory()->create();
        $group = AgeGroup::factory()->create(['instrument_version_id' => $version->id]);
        $group->delete(); // allowed while draft

        $this->publish($version);

        $trashed = AgeGroup::withTrashed()->findOrFail($group->id);

        $this->expectException(ImmutableInstrumentVersionException::class);
        $trashed->restore();
    }

    public function test_is_default_may_change_after_publish(): void
    {
        $version = InstrumentVersion::factory()->published()->create(['is_default' => false]);

        $version->is_default = true;
        $version->save();

        $this->assertDatabaseHas('instrument_version', ['id' => $version->id, 'is_default' => true]);
    }

    public function test_changing_another_field_after_publish_throws(): void
    {
        $version = InstrumentVersion::factory()->published()->create();

        $this->expectException(ImmutableInstrumentVersionException::class);
        $version->name = 'renamed';
        $version->save();
    }

    public function test_two_defaults_for_the_same_instrument_are_rejected(): void
    {
        $a = InstrumentVersion::factory()->published()->create(['instrument_id' => Instrument::EDI, 'is_default' => true]);
        $b = InstrumentVersion::factory()->published()->create(['instrument_id' => Instrument::EDI, 'is_default' => false]);

        $this->expectException(UniqueConstraintViolationException::class);
        $b->is_default = true;
        $b->save();

        // $a remains the only default -- untouched by the failed attempt.
        $this->assertTrue($a->fresh()->is_default);
    }

    private function publish(InstrumentVersion $version): void
    {
        $version->published_at = now();
        $version->content_hash = str_repeat('a', 64);
        $version->save();
    }
}
