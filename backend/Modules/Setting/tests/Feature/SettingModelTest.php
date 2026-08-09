<?php

declare(strict_types=1);

namespace Modules\Setting\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Modules\Setting\Models\Setting;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Round-trip fidelity tests for `Setting::putValue()`/`getValue()` and raw
 * Eloquent writes (Phase 1 of `setting-value-native-json`, tasks T1.1-T1.8).
 *
 * History note (why this file changed mid-change, per the design's own work
 * order): written FIRST against the CURRENT (pre-cutover) contract — cast
 * `'value' => 'string'`, manual type-branching in
 * `getResolvedValueAttribute()` — and confirmed green there. This file (like
 * every test file in the project) declares `strict_types=1`, so PHP does NOT
 * coerce a native `bool`/`int`/`array` across the OLD `putValue(string
 * $slug, string $value, string $type)` — it throws a hard `TypeError` at the
 * call site (confirmed by running this suite pre-Phase-2). So the
 * `*_via_put_value()` assertions were initially written with the SAME
 * pre-serialized convention real callers used at the time. Phase 2 (the
 * atom: cast + guard + `putValue(mixed)`) swept this file exactly like every
 * other of the ~40 legacy fixture sites (task 2.6) — the assertions below
 * now use NATIVE values, matching the model's final contract, and are
 * verified to also have passed unmodified pre-Phase-2 for every case where a
 * native value is syntactically valid under the old signature (boolean and
 * integer round-trip via raw `Setting::create()`, which takes an untyped
 * array — see below).
 *
 * `test_boolean_and_integer_round_trip_via_raw_eloquent_create()`:
 * `Setting::create(array $attributes)` takes an untyped array, so native
 * `bool`/`int` values pass through unchecked under BOTH the old `'string'`
 * cast (relying on PDO/Postgres driver-level bool-to-string conversion,
 * verified via tinker probe: raw stored `'0'`/`'1'`) and the new `'json'`
 * cast — that leg is byte-for-byte unmodified from Phase 1. Its array leg
 * needed sweeping like every `putValue` case: the old `'string'` cast
 * silently corrupts a raw native array into the literal string `"Array"` on
 * read (confirmed via tinker probe), so it could only round-trip
 * pre-serialized before Phase 2.
 */
final class SettingModelTest extends TestCase
{
    use RefreshDatabase;
    use WithTenantSetup;

    // ── T1.1 / T1.2 — Boolean false/true round-trip via putValue (native) ────

    public function test_boolean_false_round_trips_via_put_value(): void
    {
        $tenant = $this->makeTenant('Setting Bool False Organization');

        $tenant->run(function (): void {
            Setting::putValue('amenity_reservations.enabled', false, Setting::TYPE_BOOLEAN);

            $this->assertSame(false, Setting::getValue('amenity_reservations.enabled'));
        });
    }

    public function test_boolean_true_round_trips_via_put_value(): void
    {
        $tenant = $this->makeTenant('Setting Bool True Organization');

        $tenant->run(function (): void {
            Setting::putValue('amenity_reservations.enabled', true, Setting::TYPE_BOOLEAN);

            $this->assertSame(true, Setting::getValue('amenity_reservations.enabled'));
        });
    }

    // ── T1.3 — Integer round-trip via putValue (native) ──────────────────────

    public function test_integer_round_trips_via_put_value(): void
    {
        $tenant = $this->makeTenant('Setting Integer Organization');

        $tenant->run(function (): void {
            Setting::putValue('collections.charge_generation_day', 15, Setting::TYPE_INTEGER);

            $this->assertSame(15, Setting::getValue('collections.charge_generation_day'));
        });
    }

    // ── T1.4 — Bare Spanish accented string round-trip via putValue ──────────

    public function test_bare_accented_string_round_trips_via_put_value(): void
    {
        $tenant = $this->makeTenant('Setting String Organization');

        $tenant->run(function (): void {
            Setting::putValue('notification.label', 'Notificación de cargos único', Setting::TYPE_STRING);

            $this->assertSame(
                'Notificación de cargos único',
                Setting::getValue('notification.label'),
            );
        });
    }

    // ── T1.5 — Array round-trip via putValue (native) ─────────────────────────

    public function test_array_round_trips_via_put_value(): void
    {
        $tenant = $this->makeTenant('Setting Array Organization');

        $tenant->run(function (): void {
            Setting::putValue('collections.reminders', [3, 1, 0], Setting::TYPE_JSON);

            $this->assertSame([3, 1, 0], Setting::getValue('collections.reminders'));
        });
    }

    // ── T1.6 — Round-trip through raw Eloquent writes (bypassing putValue) ───

    public function test_boolean_and_integer_round_trip_via_raw_eloquent_create(): void
    {
        $tenant = $this->makeTenant('Setting Raw Create Organization');

        $tenant->run(function (): void {
            Setting::create([
                'name' => 'raw.bool',
                'slug' => 'raw.bool',
                'value' => false,
                'type' => Setting::TYPE_BOOLEAN,
            ]);
            $this->assertSame(false, Setting::getValue('raw.bool'));

            Setting::firstOrCreate(
                ['slug' => 'raw.int'],
                ['name' => 'raw.int', 'value' => 15, 'type' => Setting::TYPE_INTEGER],
            );
            $this->assertSame(15, Setting::getValue('raw.int'));
        });
    }

    public function test_array_round_trips_via_raw_eloquent_create(): void
    {
        $tenant = $this->makeTenant('Setting Raw Array Organization');

        $tenant->run(function (): void {
            Setting::create([
                'name' => 'raw.array',
                'slug' => 'raw.array',
                'value' => [3, 1, 0],
                'type' => Setting::TYPE_JSON,
            ]);

            $this->assertSame([3, 1, 0], Setting::getValue('raw.array'));
        });
    }

    // ── T1.7 — Absent slug returns null ───────────────────────────────────────

    public function test_missing_slug_returns_null(): void
    {
        $tenant = $this->makeTenant('Setting Missing Slug Organization');

        $tenant->run(function (): void {
            $this->assertNull(Setting::getValue('no.such.slug'));
        });
    }

    // ── T1.8 — NULL boolean resolves to false ─────────────────────────────────

    public function test_null_boolean_value_resolves_to_false(): void
    {
        $tenant = $this->makeTenant('Setting Null Boolean Organization');

        $tenant->run(function (): void {
            Setting::create([
                'name' => 'raw.null.bool',
                'slug' => 'raw.null.bool',
                'value' => null,
                'type' => Setting::TYPE_BOOLEAN,
            ]);

            $this->assertSame(false, Setting::getValue('raw.null.bool'));
        });
    }

    // ── T2.7 — Guard-rejection tests (one per D2 row) ─────────────────────────
    // The write-time guard in Setting::booted()'s `saving` hook is what stops
    // a writer still passing a pre-serialized string from silently
    // double-encoding. Each case below is a "legacy writer" that MUST fail
    // loudly, never persist.

    public function test_legacy_boolean_writer_throws_and_persists_nothing(): void
    {
        $tenant = $this->makeTenant('Setting Guard Legacy Bool Organization');

        $tenant->run(function (): void {
            $this->expectException(InvalidArgumentException::class);

            try {
                Setting::putValue('guard.legacy.bool', 'true', Setting::TYPE_BOOLEAN);
            } finally {
                $this->assertNull(Setting::getValue('guard.legacy.bool'));
            }
        });
    }

    public function test_legacy_array_writer_throws_and_persists_nothing(): void
    {
        $tenant = $this->makeTenant('Setting Guard Legacy Array Organization');

        $tenant->run(function (): void {
            $this->expectException(InvalidArgumentException::class);

            try {
                Setting::putValue('guard.legacy.array', '[3,1,0]', Setting::TYPE_JSON);
            } finally {
                $this->assertNull(Setting::getValue('guard.legacy.array'));
            }
        });
    }

    public function test_integer_under_declared_string_type_throws(): void
    {
        $tenant = $this->makeTenant('Setting Guard Int Under String Organization');

        $tenant->run(function (): void {
            $this->expectException(InvalidArgumentException::class);

            Setting::putValue('guard.int.under.string', 5, Setting::TYPE_STRING);
        });
    }

    /**
     * The exact bug class caught live in the uncommitted billing WIP
     * (`tests/Feature/Billing/EntitlementServiceTest.php`, fixed as part of
     * this same change): a numeric STRING under a declared integer type.
     */
    public function test_string_under_declared_integer_type_throws(): void
    {
        $tenant = $this->makeTenant('Setting Guard String Under Integer Organization');

        $tenant->run(function (): void {
            $this->expectException(InvalidArgumentException::class);

            Setting::putValue('guard.string.under.integer', '5', Setting::TYPE_INTEGER);
        });
    }

    public function test_array_under_declared_boolean_type_throws(): void
    {
        $tenant = $this->makeTenant('Setting Guard Array Under Boolean Organization');

        $tenant->run(function (): void {
            $this->expectException(InvalidArgumentException::class);

            Setting::putValue('guard.array.under.boolean', ['a'], Setting::TYPE_BOOLEAN);
        });
    }

    /**
     * The D2 stated limitation, confirmed as a non-issue: a genuine string
     * under `type=string` must NEVER false-positive the guard, even one that
     * merely LOOKS like it could be pre-serialized JSON.
     */
    public function test_genuine_string_value_never_false_positives_the_guard(): void
    {
        $tenant = $this->makeTenant('Setting Guard No False Positive Organization');

        $tenant->run(function (): void {
            Setting::putValue('guard.genuine.string', 'true', Setting::TYPE_STRING);
            $this->assertSame('true', Setting::getValue('guard.genuine.string'));

            Setting::putValue('guard.genuine.string.array_like', '[3,1,0]', Setting::TYPE_STRING);
            $this->assertSame('[3,1,0]', Setting::getValue('guard.genuine.string.array_like'));
        });
    }
}
