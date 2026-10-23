<?php

namespace Modules\Instrument\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Instrument\Models\Instrument;
use Modules\Instrument\Models\InstrumentVersion;

/**
 * @extends Factory<InstrumentVersion>
 */
class InstrumentVersionFactory extends Factory
{
    protected $model = InstrumentVersion::class;

    /**
     * Draft state by default (published_at NULL) -- purpose-built fixtures
     * for the observer/verify tests are built as drafts, verified, and only
     * published explicitly when a test needs that state.
     */
    public function definition(): array
    {
        return [
            'instrument_id' => Instrument::EDI,
            'key' => 'test-'.$this->faker->unique()->slug(2),
            'name' => 'Test Version',
            'age_calculation_mode' => InstrumentVersion::AGE_MODE_CALENDAR,
            'is_default' => false,
            'published_at' => null,
            'content_hash' => null,
            'source_note' => null,
        ];
    }

    public function published(): self
    {
        return $this->state(fn (): array => ['published_at' => now(), 'content_hash' => str_repeat('a', 64)]);
    }
}
