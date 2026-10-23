<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use Carbon\Carbon;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use QCH\Core\Support\TenantTime;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Tenancy;

class TenantTimeTest extends TestCase
{
    private Container $previousContainer;

    private Container $container;

    protected function setUp(): void
    {
        $this->previousContainer = Container::getInstance();
        $this->container = new Container;
        Container::setInstance($this->container);
        $this->container->instance('config', new Repository(['app' => ['timezone' => 'UTC']]));
        $context = $this->createStub(Tenancy::class);
        $context->initialized = true;
        $this->container->instance(Tenancy::class, $context);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Container::setInstance($this->previousContainer);
    }

    private function useTenantZone(?string $zone): void
    {
        $this->container->instance(Tenant::class, (object) ['timezone' => $zone]);
    }

    public function test_zone_resolves_each_call_with_safe_fallback_and_no_global_mutation(): void
    {
        $original = date_default_timezone_get();
        foreach (['America/Mexico_City', 'UTC', 'Europe/Madrid', 'invalid', '', null] as $zone) {
            $this->useTenantZone($zone);
            $expected = in_array($zone, ['invalid', '', null], true) ? 'UTC' : $zone;
            self::assertSame($expected, TenantTime::zone());
        }
        self::assertSame($original, date_default_timezone_get());
    }

    public function test_uninitialized_tenancy_and_invalid_app_zone_fall_back_safely(): void
    {
        $this->useTenantZone('Europe/Madrid');
        $this->container->make(Tenancy::class)->initialized = false;
        self::assertSame('UTC', TenantTime::zone());

        $this->container->make('config')->set('app.timezone', 'invalid');
        self::assertSame('UTC', TenantTime::zone());
    }

    public function test_is_valid_zone_accepts_iana_identifiers_only(): void
    {
        self::assertTrue(TenantTime::isValidZone('America/Mexico_City'));
        self::assertTrue(TenantTime::isValidZone('UTC'));
        self::assertFalse(TenantTime::isValidZone('Mexico/Nowhere'));
        self::assertFalse(TenantTime::isValidZone(''));
    }

    public function test_today_follows_the_tenant_calendar_not_the_process_calendar(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-21T03:00:00Z'));
        $this->useTenantZone('America/Mexico_City');
        self::assertSame('2026-10-20', TenantTime::today());
        self::assertSame('2026-10-21', TenantTime::today('UTC'));
        self::assertSame('2026-10', TenantTime::currentMonth());

        Carbon::setTestNow(Carbon::parse('2026-11-01T05:59:59Z'));
        self::assertSame('2026-10', TenantTime::currentMonth());
        self::assertSame('2026-11', TenantTime::currentMonth('UTC'));
    }

    public function test_in_tenant_zone_converts_instants_and_copies_mutable_arguments(): void
    {
        $this->useTenantZone('America/Mexico_City');
        $value = Carbon::parse('2026-01-15T18:00:00Z');
        $original = $value->format('c');
        self::assertSame('2026-01-15 12:00', TenantTime::inTenantZone($value)->format('Y-m-d H:i'));
        self::assertSame('2026-01-15 12:00', TenantTime::inTenantZone('2026-01-15 18:00:00')->format('Y-m-d H:i'));
        self::assertSame($original, $value->format('c'));
    }

    #[DataProvider('zones')]
    public function test_wall_time_and_civil_day_boundaries(string $zone, string $start, string $end): void
    {
        $this->useTenantZone($zone);
        self::assertSame($start, TenantTime::wallTime('2026-03-29T00:00')->toISOString());
        [$from, $until] = TenantTime::dayBounds('2026-03-29');
        self::assertSame($start, $from->toISOString());
        self::assertSame($end, $until->toISOString());
        self::assertSame('2026-03-29', TenantTime::civilDate('2026-03-29'));
    }

    public static function zones(): array
    {
        return [
            ['America/Mexico_City', '2026-03-29T06:00:00.000000Z', '2026-03-30T06:00:00.000000Z'],
            ['UTC', '2026-03-29T00:00:00.000000Z', '2026-03-30T00:00:00.000000Z'],
            ['Europe/Madrid', '2026-03-28T23:00:00.000000Z', '2026-03-29T22:00:00.000000Z'],
        ];
    }

    public function test_month_bounds_are_utc_half_open_instants(): void
    {
        $this->useTenantZone('America/Mexico_City');
        [$from, $until] = TenantTime::monthBounds('2026-10');
        self::assertSame('2026-10-01T06:00:00.000000Z', $from->toISOString());
        self::assertSame('2026-11-01T06:00:00.000000Z', $until->toISOString());

        // Pre-2022 Mexico had DST: April started at UTC-6, May at UTC-5.
        [$from, $until] = TenantTime::monthBounds('2020-04');
        self::assertSame('2020-04-01T06:00:00.000000Z', $from->toISOString());
        self::assertSame('2020-05-01T05:00:00.000000Z', $until->toISOString());
    }

    #[DataProvider('invalidMonths')]
    public function test_month_bounds_reject_malformed_months(string $month): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TenantTime::monthBounds($month, 'UTC');
    }

    public static function invalidMonths(): array
    {
        return [['2026-13'], ['2026-1'], ['octubre'], ['2026-10-01']];
    }

    #[DataProvider('invalidWallTimes')]
    public function test_wall_time_refuses_malformed_gap_and_fold_inputs(string $input): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TenantTime::wallTime($input, 'Europe/Madrid');
    }

    public static function invalidWallTimes(): array
    {
        return array_map(fn (string $input): array => [$input], [
            '2026-03-29T02:30', '2026-10-25T02:30', '2026-02-30T12:00',
            'tomorrow', '2026-01-15T12:00Z', '2026-01-15T25:00',
        ]);
    }

    public function test_wall_time_refuses_mexico_legacy_dst_gap(): void
    {
        // 2020-04-05 02:30 never existed in Mexico City (clocks jumped 02:00 → 03:00).
        $this->expectException(\InvalidArgumentException::class);
        TenantTime::wallTime('2020-04-05T02:30', 'America/Mexico_City');
    }

    public function test_explicit_invalid_zone_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TenantTime::today('Mars/Olympus');
    }

    public function test_civil_date_refuses_rollover_without_converting_date_to_instant(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TenantTime::civilDate('2026-02-30');
    }
}
