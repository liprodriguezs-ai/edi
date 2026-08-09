<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Access\Models\AccessEntryType;
use Modules\Access\Models\AccessLog;
use Modules\Access\Models\AccessVerification;
use Modules\Access\Models\BlacklistEntry;
use Modules\Access\Models\BlacklistType;
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
 * Integration tests for Module 10 — Control de Accesos.
 *
 * Covers:
 *  - Catalog seeded per tenant
 *  - generateQr: unique token + expiry set correctly per QrType
 *  - scanQr (single_entry): flips visitor_status to used + creates access_log
 *  - access_log immutability: no general update path; only checkout + correction mutate
 *  - Blacklist match flags correctly
 *  - Manual entry creates access_log
 *  - cancelQr: marks pending visitor as cancelled
 *  - Tenant isolation
 */
final class AccessModuleTest extends TestCase
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
            $unitType = UnitType::findOrFail(Catalog::id(UnitType::class, 'Casa'));
            $unitStatus = UnitStatus::findOrFail(Catalog::id(UnitStatus::class, 'Activa'));
            $unit = Unit::create([
                'unit_type_id' => $unitType->id,
                'number' => (string) mt_rand(1, 999),
                'unit_status_id' => $unitStatus->id,
            ]);
        });

        return $unit;
    }

    // =========================================================================
    // Catalog seeded per tenant
    // =========================================================================

    public function test_access_catalogs_are_seeded_per_tenant(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $this->assertSame(5, VisitorMotive::count(), 'Expected 5 visitor motives seeded');
            // 4 from CatalogSeeder + 3 approval-flow statuses (pending_approval /
            // approved / rejected) inserted by the module migration.
            $this->assertSame(7, VisitorStatus::count(), 'Expected 7 visitor statuses seeded');
            $this->assertSame(3, QrType::count(), 'Expected 3 QR types seeded');
            $this->assertSame(2, AccessEntryType::count(), 'Expected 2 entry types seeded');
            $this->assertSame(3, AccessVerification::count(), 'Expected 3 verifications seeded');
            $this->assertSame(2, BlacklistType::count(), 'Expected 2 blacklist types seeded');
        });
    }

    // =========================================================================
    // generateQr
    // =========================================================================

    public function test_generate_qr_creates_visitor_with_unique_token(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);

        $tenant->run(function () use ($unit): void {
            $service = app(AccessService::class);
            $motive = VisitorMotive::findOrFail(Catalog::id(VisitorMotive::class, 'Social / familiar'));
            $qrType = QrType::findOrFail(QrType::SINGLE_ENTRY);

            $visitor = $service->generateQr([
                'unit_id' => $unit->id,
                'name' => 'Juan Pérez',
                'visitor_motive_id' => $motive->id,
                'qr_type_id' => $qrType->id,
            ]);

            $this->assertNotEmpty($visitor->qr_token);
            $this->assertSame(9, strlen($visitor->qr_token), 'Token format XXXX-XXXX = 9 chars');
            $this->assertNotNull($visitor->qr_expires_at, 'single_entry should have expiry = end of day');
            $this->assertDatabaseHas('visitor', ['id' => $visitor->id, 'name' => 'Juan Pérez']);
        });
    }

    public function test_generate_qr_tokens_are_unique_within_tenant(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);

        $tokens = [];

        $tenant->run(function () use ($unit, &$tokens): void {
            $service = app(AccessService::class);
            $motive = VisitorMotive::findOrFail(Catalog::id(VisitorMotive::class, 'Social / familiar'));
            $qrType = QrType::findOrFail(QrType::SINGLE_ENTRY);

            for ($i = 0; $i < 5; $i++) {
                $visitor = $service->generateQr([
                    'unit_id' => $unit->id,
                    'name' => "Visitante {$i}",
                    'visitor_motive_id' => $motive->id,
                    'qr_type_id' => $qrType->id,
                ]);
                $tokens[] = $visitor->qr_token;
            }
        });

        $this->assertSame(count($tokens), count(array_unique($tokens)), 'All QR tokens must be unique');
    }

    // =========================================================================
    // scanQr: single_entry flips to used + creates access_log
    // =========================================================================

    public function test_scan_qr_single_entry_flips_to_used_and_creates_access_log(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);

        $tenant->run(function () use ($unit): void {
            $service = app(AccessService::class);
            $motive = VisitorMotive::findOrFail(Catalog::id(VisitorMotive::class, 'Social / familiar'));
            $qrType = QrType::findOrFail(QrType::SINGLE_ENTRY);

            $visitor = $service->generateQr([
                'unit_id' => $unit->id,
                'name' => 'Carlos González',
                'visitor_motive_id' => $motive->id,
                'qr_type_id' => $qrType->id,
            ]);

            $result = $service->scanQr($visitor->qr_token);

            $this->assertTrue($result['valid'], 'QR should be valid on first scan');
            $this->assertNotNull($result['access_log'], 'Access log must be created on scan');
            $this->assertFalse($result['blacklist_match'], 'No blacklist match expected');

            // Visitor status must flip to "used"
            $visitor->refresh();
            $usedStatus = VisitorStatus::find(VisitorStatus::USED);
            $this->assertSame($usedStatus->id, $visitor->visitor_status_id, 'Single-entry QR must flip to used after scan');

            // Access log must record entry
            $log = AccessLog::find($result['access_log']->id);
            $this->assertNotNull($log);
            $this->assertNotNull($log->entered_at);
            $this->assertNull($log->exited_at);
        });
    }

    public function test_scan_qr_used_returns_invalid(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);

        $tenant->run(function () use ($unit): void {
            $service = app(AccessService::class);
            $motive = VisitorMotive::findOrFail(Catalog::id(VisitorMotive::class, 'Social / familiar'));
            $qrType = QrType::findOrFail(QrType::SINGLE_ENTRY);

            $visitor = $service->generateQr([
                'unit_id' => $unit->id,
                'name' => 'Ana Martínez',
                'visitor_motive_id' => $motive->id,
                'qr_type_id' => $qrType->id,
            ]);

            $service->scanQr($visitor->qr_token); // First scan — valid
            $result = $service->scanQr($visitor->qr_token); // Second scan — should fail

            $this->assertFalse($result['valid'], 'Second scan on single-entry QR must be invalid');
            $this->assertStringContainsString('usado', $result['reason'] ?? '');
        });
    }

    // =========================================================================
    // scanQr: EVENT QR is valid ONLY on its event day (vs TIME_BASED open window)
    // =========================================================================

    public function test_scan_qr_event_rejected_when_not_event_day(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);

        $tenant->run(function () use ($unit): void {
            $service = app(AccessService::class);
            $motive = VisitorMotive::findOrFail(Catalog::id(VisitorMotive::class, 'Social / familiar'));

            // Event scheduled for TOMORROW → scanning today must be rejected.
            $visitor = $service->generateQr([
                'unit_id' => $unit->id,
                'name' => 'Invitado de Evento',
                'visitor_motive_id' => $motive->id,
                'qr_type_id' => QrType::EVENT,
                'qr_expires_at' => now()->addDay()->setTime(20, 0),
            ]);

            $result = $service->scanQr($visitor->qr_token);

            $this->assertFalse($result['valid'], 'EVENT QR scanned before its event day must be invalid');
            $this->assertStringContainsString('evento', $result['reason'] ?? '');

            // It must NOT be consumed nor flipped to expired — it is simply "not today".
            $visitor->refresh();
            $this->assertSame(VisitorStatus::PENDING, $visitor->visitor_status_id, 'A wrong-day EVENT scan must not change the visitor status');
        });
    }

    public function test_scan_qr_event_allows_multiple_entries_on_event_day(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);

        $tenant->run(function () use ($unit): void {
            $service = app(AccessService::class);
            $motive = VisitorMotive::findOrFail(Catalog::id(VisitorMotive::class, 'Social / familiar'));

            // Event today, cutoff at end of day → same calendar day, not past.
            $visitor = $service->generateQr([
                'unit_id' => $unit->id,
                'name' => 'Invitada de Evento',
                'visitor_motive_id' => $motive->id,
                'qr_type_id' => QrType::EVENT,
                'qr_expires_at' => now()->endOfDay(),
            ]);

            $first = $service->scanQr($visitor->qr_token);
            $second = $service->scanQr($visitor->qr_token);

            $this->assertTrue($first['valid'], 'EVENT QR on its event day must be valid');
            $this->assertTrue($second['valid'], 'EVENT QR allows MULTIPLE entries on the event day (not consumed)');

            $visitor->refresh();
            $this->assertSame(VisitorStatus::PENDING, $visitor->visitor_status_id, 'EVENT QR is never consumed — stays PENDING for re-entry');
            $this->assertSame(2, AccessLog::where('visitor_id', $visitor->id)->count(), 'Each EVENT scan creates its own access_log');
        });
    }

    public function test_scan_nonexistent_qr_returns_invalid(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $service = app(AccessService::class);
            $result = $service->scanQr('FAKE-TOKEN');
            $this->assertFalse($result['valid']);
        });
    }

    // =========================================================================
    // access_log immutability
    // =========================================================================

    public function test_access_log_has_no_general_update_path_only_checkout_and_correction(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);

        $tenant->run(function () use ($unit): void {
            $service = app(AccessService::class);
            $motive = VisitorMotive::findOrFail(Catalog::id(VisitorMotive::class, 'Social / familiar'));
            $qrType = QrType::findOrFail(QrType::SINGLE_ENTRY);

            $visitor = $service->generateQr([
                'unit_id' => $unit->id,
                'name' => 'María López',
                'visitor_motive_id' => $motive->id,
                'qr_type_id' => $qrType->id,
            ]);

            $result = $service->scanQr($visitor->qr_token);
            $log = $result['access_log'];

            // Checkout is the ONLY way to set exited_at
            $this->assertNull($log->exited_at, 'Exited at should be null before checkout');
            $checked = $service->checkout($log);
            $this->assertNotNull($checked->exited_at, 'Exited at should be set after checkout');

            // Correction appends note (does not replace full record)
            $corrected = $service->addCorrection($checked, 'El visitante entró por puerta lateral');
            $this->assertStringContainsString('El visitante entró por puerta lateral', $corrected->correction);

            // A second correction appends, not replaces
            $corrected2 = $service->addCorrection($corrected, 'Corrección adicional');
            $this->assertStringContainsString('El visitante entró por puerta lateral', $corrected2->correction);
            $this->assertStringContainsString('Corrección adicional', $corrected2->correction);
        });
    }

    // =========================================================================
    // Blacklist
    // =========================================================================

    public function test_blacklist_match_flagged_on_qr_scan(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);

        $tenant->run(function () use ($unit): void {
            $service = app(AccessService::class);
            $blacklistType = BlacklistType::findOrFail(BlacklistType::VISITOR);

            BlacklistEntry::create([
                'blacklist_type_id' => $blacklistType->id,
                'value' => 'Carlos Peligroso',
                'is_active' => true,
            ]);

            $motive = VisitorMotive::findOrFail(Catalog::id(VisitorMotive::class, 'Social / familiar'));
            $qrType = QrType::findOrFail(QrType::SINGLE_ENTRY);

            $visitor = $service->generateQr([
                'unit_id' => $unit->id,
                'name' => 'Carlos Peligroso',
                'visitor_motive_id' => $motive->id,
                'qr_type_id' => $qrType->id,
            ]);

            $result = $service->scanQr($visitor->qr_token);

            // §14.5 r7 is ENFORCED (access-04): a blacklist match DENIES the entry —
            // it is recorded as a denied row in the immutable log and the QR is NOT
            // consumed (stays pending) so the guard decision is never a fait accompli.
            $this->assertFalse($result['valid'], 'Blacklist match must deny the entry');
            $this->assertTrue($result['blacklist_match'], 'Blacklist match should be flagged');
            $this->assertNotNull($result['access_log'], 'Denied attempt must be recorded in the log');
            $this->assertNotNull($result['access_log']->denied_at);
            $this->assertNull($result['access_log']->entered_at, 'A denied attempt never stamps an entry');

            $visitor->refresh();
            $this->assertSame(VisitorStatus::PENDING, $visitor->visitor_status_id, 'Denied scan must not consume the QR');
        });
    }

    // =========================================================================
    // Manual entry
    // =========================================================================

    public function test_manual_entry_creates_access_log(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);

        $tenant->run(function () use ($unit): void {
            $service = app(AccessService::class);
            $verification = AccessVerification::findOrFail(AccessVerification::PHONE_CALL);

            $log = $service->manualEntry([
                'unit_id' => $unit->id,
                'visitor_name' => 'Repartidor Amazon',
                'access_verification_id' => $verification->id,
                'authorized_by' => 'Rosa Huerta',
            ]);

            $this->assertNotNull($log->id);
            $this->assertNull($log->visitor_id, 'Manual entry has no prior visitor QR');
            $this->assertNotNull($log->entered_at);

            $entryType = AccessEntryType::find(AccessEntryType::MANUAL);
            $this->assertSame($entryType->id, $log->access_entry_type_id, 'Entry type must be manual');
        });
    }

    // =========================================================================
    // Cancel QR
    // =========================================================================

    public function test_cancel_pending_qr_sets_cancelled_status(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);

        $tenant->run(function () use ($unit): void {
            $service = app(AccessService::class);
            $motive = VisitorMotive::findOrFail(Catalog::id(VisitorMotive::class, 'Social / familiar'));
            $qrType = QrType::findOrFail(QrType::SINGLE_ENTRY);

            $visitor = $service->generateQr([
                'unit_id' => $unit->id,
                'name' => 'Visita cancelada',
                'visitor_motive_id' => $motive->id,
                'qr_type_id' => $qrType->id,
            ]);

            $cancelled = $service->cancelQr($visitor);

            $cancelledStatus = VisitorStatus::findOrFail(VisitorStatus::CANCELLED);
            $this->assertSame($cancelledStatus->id, $cancelled->visitor_status_id, 'Cancelled QR must have cancelled status');

            // Now scanning it should return invalid
            $result = $service->scanQr($visitor->qr_token);
            $this->assertFalse($result['valid'], 'Cancelled QR must not be scannable');
        });
    }

    // =========================================================================
    // Tenant isolation
    // =========================================================================

    public function test_visitor_from_tenant_a_not_visible_in_tenant_b(): void
    {
        $tenantA = $this->makeTenant('Cluster A');
        $tenantB = $this->makeTenant('Cluster B');

        $this->seedTenant($tenantA);
        $this->seedTenant($tenantB);

        $unitA = $this->makeUnit($tenantA);

        $tenantA->run(function () use ($unitA): void {
            $motive = VisitorMotive::findOrFail(Catalog::id(VisitorMotive::class, 'Social / familiar'));
            $qrType = QrType::findOrFail(QrType::SINGLE_ENTRY);
            $pending = VisitorStatus::findOrFail(VisitorStatus::PENDING);

            Visitor::create([
                'unit_id' => $unitA->id,
                'name' => 'Visita Tenant A',
                'visitor_motive_id' => $motive->id,
                'qr_token' => 'AAAA-BBBB',
                'qr_type_id' => $qrType->id,
                'visitor_status_id' => $pending->id,
            ]);
        });

        $tenantB->run(function (): void {
            $this->assertSame(0, Visitor::count(), 'Visitor from Tenant A must not be visible in Tenant B');
        });
    }

    public function test_same_qr_token_allowed_in_different_tenants(): void
    {
        $tenantA = $this->makeTenant('Cluster A');
        $tenantB = $this->makeTenant('Cluster B');

        $this->seedTenant($tenantA);
        $this->seedTenant($tenantB);

        $unitA = $this->makeUnit($tenantA);
        $unitB = $this->makeUnit($tenantB);

        $createVisitor = function (Unit $unit): void {
            $motive = VisitorMotive::findOrFail(Catalog::id(VisitorMotive::class, 'Social / familiar'));
            $qrType = QrType::findOrFail(QrType::SINGLE_ENTRY);
            $pending = VisitorStatus::findOrFail(VisitorStatus::PENDING);
            Visitor::create([
                'unit_id' => $unit->id,
                'name' => 'Shared Token Visitor',
                'visitor_motive_id' => $motive->id,
                'qr_token' => 'SAME-TOKN',
                'qr_type_id' => $qrType->id,
                'visitor_status_id' => $pending->id,
            ]);
        };

        $tenantA->run(fn () => $createVisitor($unitA));

        // Must not throw — tenant isolation means same token is allowed in B
        $threw = false;
        try {
            $tenantB->run(fn () => $createVisitor($unitB));
        } catch (\Throwable) {
            $threw = true;
        }

        $this->assertFalse($threw, 'Same QR token allowed across tenants since unique is (tenant_id, qr_token)');
    }
}
