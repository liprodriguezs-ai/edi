<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Modules\Access\Models\AccessEntryType;
use Modules\Access\Models\AccessLog;
use Modules\Access\Models\AccessVerification;
use Modules\Access\Models\QrType;
use Modules\Access\Models\Visitor;
use Modules\Access\Models\VisitorMotive;
use Modules\Access\Models\VisitorStatus;
use Modules\Access\Services\AccessService;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Modules\Tenant\Models\Tenant;
use Tests\Support\Catalog;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * §18.6 — QR expired-code pruning.
 */
final class PruneExpiredQrTest extends TestCase
{
    use RefreshDatabase;
    use WithTenantSetup;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    private function makeUnit(Tenant $tenant): Unit
    {
        $unit = null;
        $tenant->run(function () use (&$unit): void {
            $unit = Unit::create([
                'unit_type_id' => Catalog::id(UnitType::class, 'Casa'),
                'number' => (string) mt_rand(1, 99999),
                'unit_status_id' => Catalog::id(UnitStatus::class, 'Activa'),
            ]);
        });

        return $unit;
    }

    private function makeVisitor(Unit $unit, string $expiresAt): Visitor
    {
        return Visitor::create([
            'unit_id' => $unit->id,
            'name' => 'Visitante '.Str::random(4),
            'visitor_motive_id' => Catalog::id(VisitorMotive::class, 'Social / familiar'),
            'qr_token' => strtoupper(Str::random(4).'-'.Str::random(4)),
            'qr_type_id' => QrType::SINGLE_ENTRY,
            'qr_expires_at' => $expiresAt,
            'visitor_status_id' => VisitorStatus::EXPIRED,
        ]);
    }

    public function test_visitor_expired_31_days_ago_is_pruned(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);

        $tenant->run(function () use ($unit): void {
            $stale = $this->makeVisitor($unit, now()->subDays(31)->toDateTimeString());

            $pruned = app(AccessService::class)->pruneExpiredQr(30);

            $this->assertSame(1, $pruned);
            $this->assertSame(0, Visitor::where('id', $stale->id)->count());
            $this->assertSame(1, Visitor::withTrashed()->where('id', $stale->id)->count(), 'Pruning is a soft-delete');
        });
    }

    public function test_visitor_expired_29_days_ago_is_kept(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);

        $tenant->run(function () use ($unit): void {
            $recent = $this->makeVisitor($unit, now()->subDays(29)->toDateTimeString());

            $pruned = app(AccessService::class)->pruneExpiredQr(30);

            $this->assertSame(0, $pruned);
            $this->assertSame(1, Visitor::where('id', $recent->id)->count());
        });
    }

    public function test_visitor_with_access_log_is_never_pruned_and_log_untouched(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);

        $tenant->run(function () use ($unit): void {
            $visitor = $this->makeVisitor($unit, now()->subDays(60)->toDateTimeString());

            $log = AccessLog::create([
                'visitor_id' => $visitor->id,
                'unit_id' => $unit->id,
                'visitor_name' => $visitor->name,
                'access_entry_type_id' => AccessEntryType::QR,
                'access_verification_id' => AccessVerification::QR,
                'entered_at' => now()->subDays(60),
            ]);

            $pruned = app(AccessService::class)->pruneExpiredQr(30);

            $this->assertSame(0, $pruned, 'A visitor that ever entered must never be pruned');
            $this->assertSame(1, Visitor::where('id', $visitor->id)->count());
            // The immutable access_log is untouched.
            $this->assertSame(1, AccessLog::where('id', $log->id)->count());
        });
    }

    public function test_command_runs_per_tenant(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);

        $tenant->run(function () use ($unit): void {
            $this->makeVisitor($unit, now()->subDays(45)->toDateTimeString());
        });

        Artisan::call('access:prune-expired-qr');

        $tenant->run(function (): void {
            $this->assertSame(0, Visitor::count(), 'Stale visitor pruned by the scheduled command');
        });
    }
}
