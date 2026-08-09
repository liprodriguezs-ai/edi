<?php

declare(strict_types=1);

namespace Tests\Unit\Casts;

use App\Casts\AppTimezoneCast;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * cashier-timestamp-timezone — AppTimezoneCast normalizes Stripe-derived
 * timestamps (Carbon 3's `createFromTimestamp()` returns UTC) to the app
 * timezone before they land in a `timestamp without time zone` column, and
 * is idempotent BY CONSTRUCTION: `setTimezone()` shifts only the displayed
 * wall clock, never the underlying instant, so re-saving an already
 * app-timezone value never shifts it a second time.
 *
 * Uses the Laravel-aware TestCase (not the plain PHPUnit one other
 * app/Support unit tests use) because the cast reads `config('app.timezone')`.
 */
final class AppTimezoneCastTest extends TestCase
{
    private AppTimezoneCast $cast;

    private Model $model;

    private string $originalDefaultTimezone;

    protected function setUp(): void
    {
        parent::setUp();

        // Forced explicitly (independent of .env.testing's APP_TIMEZONE=UTC) so
        // the shift assertions are meaningful regardless of the environment.
        // Both PHP's default timezone AND config('app.timezone') are set
        // together — in every real boot, Laravel's LoadConfiguration
        // bootstrapper calls date_default_timezone_set() from this same
        // config key exactly once, which is what makes a tz-naive string
        // parse as already-local (the idempotency the cast relies on).
        $this->originalDefaultTimezone = date_default_timezone_get();
        date_default_timezone_set('America/Mexico_City');
        Config::set('app.timezone', 'America/Mexico_City');

        $this->cast = new AppTimezoneCast;
        $this->model = new class extends Model {};
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->originalDefaultTimezone);

        parent::tearDown();
    }

    public function test_a_utc_carbon_write_shifts_to_app_timezone_wall_clock(): void
    {
        // Mirrors Carbon::createFromTimestamp()'s Carbon-3 UTC return value.
        $utc = Carbon::create(2026, 8, 7, 15, 56, 31, 'UTC');

        $stored = $this->cast->set($this->model, 'trial_ends_at', $utc, []);

        // America/Mexico_City is UTC-6 in August (no DST): 15:56:31 UTC == 09:56:31 local.
        $this->assertSame('2026-08-07 09:56:31', $stored);
    }

    public function test_an_already_local_value_round_trips_unchanged(): void
    {
        $local = '2026-08-07 09:56:31';

        $stored = $this->cast->set($this->model, 'trial_ends_at', $local, []);

        $this->assertSame($local, $stored);
    }

    public function test_resaving_an_already_cast_value_does_not_shift_a_second_time(): void
    {
        $utc = Carbon::create(2026, 8, 7, 15, 56, 31, 'UTC');

        $firstSave = $this->cast->set($this->model, 'trial_ends_at', $utc, []);
        $secondSave = $this->cast->set($this->model, 'trial_ends_at', $firstSave, []);

        $this->assertSame($firstSave, $secondSave);
    }

    public function test_null_passes_through_both_directions(): void
    {
        $this->assertNull($this->cast->set($this->model, 'trial_ends_at', null, []));
        $this->assertNull($this->cast->get($this->model, 'trial_ends_at', null, []));
    }

    public function test_get_reads_the_stored_wall_clock_in_app_timezone(): void
    {
        $read = $this->cast->get($this->model, 'trial_ends_at', '2026-08-07 09:56:31', []);

        $this->assertInstanceOf(Carbon::class, $read);
        $this->assertSame('America/Mexico_City', $read->timezoneName);
        $this->assertSame('2026-08-07 09:56:31', $read->format('Y-m-d H:i:s'));
    }
}
