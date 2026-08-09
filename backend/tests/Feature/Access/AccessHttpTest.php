<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Models\Role as AppRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
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
use Modules\Housing\Models\UnitRelationship;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Modules\Housing\Models\UnitUser;
use Modules\Notification\Models\Notification;
use Modules\Notification\Models\NotificationUser;
use Modules\Tenant\Models\Tenant;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Catalog;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * HTTP coverage for Module 10 — Control de Accesos (access-13).
 *
 * Covers the enforced blacklist (by visitor AND by plate — denial recorded in the
 * immutable log + urgent alert to admin/security), the single-use QR under a second
 * scan, access_log immutability via API, visitor ownership scoping (§14.2 "Ver MIS
 * QR"), manual capture fields (§14.4.4), and the gate/resident read endpoints
 * (access/now §14.4.1 + access/my-log §14.3.3).
 */
final class AccessHttpTest extends TestCase
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

    // =========================================================================
    // Helpers
    // =========================================================================

    private function makeUser(Tenant $tenant, ?string $role = null): User
    {
        $user = User::create([
            'name' => 'Access '.Str::random(6),
            'email' => Str::random(10).'@test.com',
            'password' => Hash::make('secret1234'),
            'auth_active' => true,
        ]);

        $user->tenants()->attach($tenant->id);

        if ($role !== null) {
            AppRole::firstOrCreate(['name' => $role, 'guard_name' => 'api']);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            $user->assignRole($role);
        }

        return $user;
    }

    /**
     * @param  array<string>  $abilities
     */
    private function token(User $user, array $abilities): string
    {
        return $user->createToken('test', $abilities)->plainTextToken;
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

    private function linkToUnit(Tenant $tenant, Unit $unit, User $user): void
    {
        $tenant->run(function () use ($unit, $user): void {
            UnitUser::create([
                'unit_id' => $unit->id,
                'user_id' => $user->id,
                'unit_relationship_id' => UnitRelationship::OWNER,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeVisitor(Tenant $tenant, Unit $unit, array $overrides = []): Visitor
    {
        $visitor = null;
        $tenant->run(function () use ($unit, $overrides, &$visitor): void {
            $visitor = app(AccessService::class)->generateQr(array_merge([
                'unit_id' => $unit->id,
                'name' => 'Visitante '.Str::random(5),
                'visitor_motive_id' => Catalog::id(VisitorMotive::class, 'Social / familiar'),
                'qr_type_id' => QrType::SINGLE_ENTRY,
            ], $overrides));
        });

        return $visitor;
    }

    private function blacklist(Tenant $tenant, string $typeId, string $value): void
    {
        $tenant->run(function () use ($typeId, $value): void {
            BlacklistEntry::create([
                'blacklist_type_id' => $typeId,
                'value' => $value,
                'is_active' => true,
            ]);
        });
    }

    private function assertUserNotified(Tenant $tenant, User $user, string $title): void
    {
        $tenant->run(function () use ($user, $title): void {
            $notificationIds = Notification::where('title', $title)->pluck('id');

            $this->assertTrue(
                NotificationUser::whereIn('notification_id', $notificationIds)
                    ->where('user_id', $user->id)
                    ->exists(),
                "Expected an in-app '{$title}' notification for user {$user->name}",
            );
        });
    }

    // =========================================================================
    // Blacklist enforcement (access-04)
    // =========================================================================

    public function test_scan_qr_of_blacklisted_visitor_is_denied_logged_and_alerts_staff(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);

        $security = $this->makeUser($tenant, 'security');
        $admin = $this->makeUser($tenant, 'administrator');

        $this->blacklist($tenant, BlacklistType::VISITOR, 'Carlos Peligroso');
        $visitor = $this->makeVisitor($tenant, $unit, ['name' => 'Carlos Peligroso']);

        $this->tenantRequest($this->token($security, ['create.access_log']), $tenant)
            ->postJson('/api/access/scan-qr', ['token' => $visitor->qr_token])
            ->assertStatus(422)
            ->assertJsonPath('valid', false)
            ->assertJsonPath('blacklist_match', true);

        $tenant->run(function () use ($visitor): void {
            $denied = AccessLog::where('visitor_id', $visitor->id)->first();
            $this->assertNotNull($denied, 'Denied attempt must be recorded in the immutable log');
            $this->assertNotNull($denied->denied_at);
            $this->assertNull($denied->entered_at, 'A denied attempt never stamps an entry');
            $this->assertSame('Coincidencia en lista negra', $denied->denied_reason);

            // The QR must NOT be consumed by a denied scan.
            $this->assertSame(VisitorStatus::PENDING, $visitor->fresh()->visitor_status_id);
        });

        $this->assertUserNotified($tenant, $security, 'Alerta de lista negra');
        $this->assertUserNotified($tenant, $admin, 'Alerta de lista negra');
    }

    public function test_manual_entry_with_blacklisted_plate_is_denied_logged_and_alerts_staff(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);

        $security = $this->makeUser($tenant, 'security');

        $this->blacklist($tenant, BlacklistType::VEHICLE, 'JJJ-666');

        $this->tenantRequest($this->token($security, ['create.access_log']), $tenant)
            ->postJson('/api/access/manual-entry', [
                'unit_id' => $unit->id,
                'visitor_name' => 'Visitante Cualquiera',
                'access_verification_id' => AccessVerification::PHONE_CALL,
                'plate' => 'jjj-666',
            ])
            ->assertStatus(422);

        $tenant->run(function () use ($unit): void {
            $denied = AccessLog::where('unit_id', $unit->id)->whereNotNull('denied_at')->first();
            $this->assertNotNull($denied, 'Plate blacklist match must record a denied row');
            $this->assertNull($denied->entered_at);
        });

        $this->assertUserNotified($tenant, $security, 'Alerta de lista negra');
    }

    // =========================================================================
    // Single-use QR under a second scan (access-08)
    // =========================================================================

    public function test_second_scan_of_single_entry_qr_is_rejected(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);

        $security = $this->makeUser($tenant, 'security');
        $token = $this->token($security, ['create.access_log']);
        $visitor = $this->makeVisitor($tenant, $unit);

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/access/scan-qr', ['token' => $visitor->qr_token])
            ->assertStatus(200)
            ->assertJsonPath('valid', true);

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/access/scan-qr', ['token' => $visitor->qr_token])
            ->assertStatus(422)
            ->assertJsonPath('valid', false)
            ->assertJsonPath('reason', 'QR ya fue usado');

        $tenant->run(function () use ($visitor): void {
            $this->assertSame(1, AccessLog::where('visitor_id', $visitor->id)->count(), 'Only ONE entry may exist for a single-entry QR');
        });
    }

    // =========================================================================
    // access_log immutability via API (access-09)
    // =========================================================================

    public function test_access_log_cannot_be_created_updated_or_deleted_via_api(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);

        $admin = $this->makeUser($tenant, 'administrator');
        $token = $this->token($admin, ['*']);

        $logId = null;
        $tenant->run(function () use ($unit, &$logId): void {
            $logId = app(AccessService::class)->manualEntry([
                'unit_id' => $unit->id,
                'visitor_name' => 'Repartidor',
                'access_verification_id' => AccessVerification::PHONE_CALL,
            ])->id;
        });

        // Even a wildcard token cannot forge, rewrite, or erase the bitácora.
        $this->tenantRequest($token, $tenant)
            ->postJson('/api/access_log', [
                'unit_id' => $unit->id,
                'visitor_name' => 'Forjado',
                'access_entry_type_id' => AccessVerification::PHONE_CALL,
                'access_verification_id' => AccessVerification::PHONE_CALL,
                'entered_at' => now()->subDays(3)->toDateTimeString(),
            ])
            ->assertStatus(405);

        $this->tenantRequest($token, $tenant)
            ->putJson("/api/access_log/{$logId}", ['visitor_name' => 'Alterado'])
            ->assertStatus(405);

        $this->tenantRequest($token, $tenant)
            ->patchJson("/api/access_log/{$logId}", ['exited_at' => now()->toDateTimeString()])
            ->assertStatus(405);

        $this->tenantRequest($token, $tenant)
            ->deleteJson("/api/access_log/{$logId}")
            ->assertStatus(405);

        // No restore route either (soft-delete is not reachable through the API).
        $this->tenantRequest($token, $tenant)
            ->postJson("/api/access_log/{$logId}/restore")
            ->assertStatus(404);

        $tenant->run(function () use ($logId): void {
            $log = AccessLog::findOrFail($logId);
            $this->assertSame('Repartidor', $log->visitor_name);
            $this->assertNull($log->exited_at);
            $this->assertSame(1, AccessLog::count(), 'No forged row may exist');
        });
    }

    // =========================================================================
    // Visitor ownership scoping (access-03)
    // =========================================================================

    public function test_resident_only_sees_own_visitors_and_foreign_qr_is_404(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $unitA = $this->makeUnit($tenant);
        $unitB = $this->makeUnit($tenant);

        $residentA = $this->makeUser($tenant);
        $residentB = $this->makeUser($tenant);
        $this->linkToUnit($tenant, $unitA, $residentA);
        $this->linkToUnit($tenant, $unitB, $residentB);

        $visitorA = $this->makeVisitor($tenant, $unitA, ['created_by_user_id' => $residentA->id]);
        $visitorB = $this->makeVisitor($tenant, $unitB, ['created_by_user_id' => $residentB->id]);

        $tokenA = $this->token($residentA, ['read.visitor', 'create.visitor']);

        // Index only returns the caller's own visitors.
        $response = $this->tenantRequest($tokenA, $tenant)->getJson('/api/visitor')->assertStatus(200);
        $ids = array_column($response->json('data'), 'id');
        $this->assertSame([$visitorA->id], $ids);

        // Another resident's visitor (and its qr_token credential) is unreachable.
        $this->tenantRequest($tokenA, $tenant)->getJson("/api/visitor/{$visitorB->id}")->assertStatus(404);

        // Search cannot fish for foreign tokens either.
        $search = $this->tenantRequest($tokenA, $tenant)
            ->postJson('/api/visitor/search', ['search' => ['value' => $visitorB->qr_token]])
            ->assertStatus(200);
        $this->assertSame([], $search->json('data'));

        // Security (full-log reader) sees the whole tenant.
        $security = $this->makeUser($tenant, 'security');
        $secResponse = $this->tenantRequest($this->token($security, ['read.visitor', 'read.access_log']), $tenant)
            ->getJson('/api/visitor')
            ->assertStatus(200);
        $this->assertCount(2, $secResponse->json('data'));
    }

    // =========================================================================
    // Visitor store validation (access-12 / access-16)
    // =========================================================================

    public function test_visitor_store_validates_required_fields_tenant_unit_and_event_window(): void
    {
        $tenantA = $this->makeTenant('Validation A');
        $tenantB = $this->makeTenant('Validation B');
        $this->seedTenant($tenantA);
        $this->seedTenant($tenantB);

        $unitA = $this->makeUnit($tenantA);
        $unitB = $this->makeUnit($tenantB);
        $foreignUnitSameTenant = $this->makeUnit($tenantA);

        $resident = $this->makeUser($tenantA);
        $this->linkToUnit($tenantA, $unitA, $resident);
        $token = $this->token($resident, ['read.visitor', 'create.visitor']);

        // Missing name → 422, never a 500 NOT NULL violation.
        $this->tenantRequest($token, $tenantA)->postJson('/api/visitor', [
            'unit_id' => $unitA->id,
            'visitor_motive_id' => Catalog::id(VisitorMotive::class, 'Social / familiar'),
            'qr_type_id' => QrType::SINGLE_ENTRY,
        ])->assertStatus(422)->assertJsonValidationErrors(['name']);

        // Unit of ANOTHER tenant → 422 (single shared DB: FK alone would accept it).
        $this->tenantRequest($token, $tenantA)->postJson('/api/visitor', [
            'unit_id' => $unitB->id,
            'name' => 'Visita Cross',
            'visitor_motive_id' => Catalog::id(VisitorMotive::class, 'Social / familiar'),
            'qr_type_id' => QrType::SINGLE_ENTRY,
        ])->assertStatus(422)->assertJsonValidationErrors(['unit_id']);

        // §14.2: a resident may only generate QRs for units they belong to.
        $this->tenantRequest($token, $tenantA)->postJson('/api/visitor', [
            'unit_id' => $foreignUnitSameTenant->id,
            'name' => 'Visita Ajena',
            'visitor_motive_id' => Catalog::id(VisitorMotive::class, 'Social / familiar'),
            'qr_type_id' => QrType::SINGLE_ENTRY,
        ])->assertStatus(422)->assertJsonValidationErrors(['unit_id']);

        // §14.5 r2: an EVENT QR without expiry would be an eternal multi-use QR.
        $this->tenantRequest($token, $tenantA)->postJson('/api/visitor', [
            'unit_id' => $unitA->id,
            'name' => 'Visita Evento',
            'visitor_motive_id' => Catalog::id(VisitorMotive::class, 'Social / familiar'),
            'qr_type_id' => QrType::EVENT,
        ])->assertStatus(422)->assertJsonValidationErrors(['qr_expires_at']);

        $tenantA->run(function (): void {
            $this->assertSame(0, Visitor::count(), 'No invalid visitor may be created');
        });
    }

    // =========================================================================
    // Manual entry capture (access-07)
    // =========================================================================

    public function test_manual_entry_captures_identification_plate_and_motive(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);

        $security = $this->makeUser($tenant, 'security');

        $this->tenantRequest($this->token($security, ['create.access_log']), $tenant)
            ->postJson('/api/access/manual-entry', [
                'unit_id' => $unit->id,
                'visitor_name' => 'Pedro Hernández',
                'access_verification_id' => AccessVerification::PHONE_CALL,
                'authorized_by' => 'Rosa Huerta',
                'id_document_type' => 'ine',
                'id_document_number' => 'HDZP800101',
                'plate' => 'XXX-123-Y',
                'visitor_motive_id' => Catalog::id(VisitorMotive::class, 'Social / familiar'),
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.id_document_type', 'ine')
            ->assertJsonPath('data.plate', 'XXX-123-Y');

        $this->assertDatabaseHas('access_log', [
            'unit_id' => $unit->id,
            'visitor_name' => 'Pedro Hernández',
            'id_document_type' => 'ine',
            'id_document_number' => 'HDZP800101',
            'plate' => 'XXX-123-Y',
            'visitor_motive_id' => Catalog::id(VisitorMotive::class, 'Social / familiar'),
        ]);

        // Invalid identification type is rejected.
        $this->tenantRequest($this->token($security, ['create.access_log']), $tenant)
            ->postJson('/api/access/manual-entry', [
                'unit_id' => $unit->id,
                'visitor_name' => 'Otro',
                'access_verification_id' => AccessVerification::PHONE_CALL,
                'id_document_type' => 'cedula-marciana',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['id_document_type']);
    }

    // =========================================================================
    // Gate: visitors currently inside (access-10)
    // =========================================================================

    public function test_now_endpoint_lists_only_visitors_inside(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);

        $security = $this->makeUser($tenant, 'security');
        $secToken = $this->token($security, ['create.access_log', 'update.access_log', 'read.access_log']);

        $insideId = null;
        $exitedId = null;
        $tenant->run(function () use ($unit, &$insideId, &$exitedId): void {
            $service = app(AccessService::class);

            $insideId = $service->manualEntry([
                'unit_id' => $unit->id,
                'visitor_name' => 'Sigue Adentro',
                'access_verification_id' => AccessVerification::PHONE_CALL,
            ])->id;

            $exited = $service->manualEntry([
                'unit_id' => $unit->id,
                'visitor_name' => 'Ya Salió',
                'access_verification_id' => AccessVerification::PHONE_CALL,
            ]);
            $service->checkout($exited);

            // A denied attempt must never appear as "inside".
            BlacklistEntry::create([
                'blacklist_type_id' => BlacklistType::VISITOR,
                'value' => 'Bloqueado Total',
                'is_active' => true,
            ]);
            $service->manualEntry([
                'unit_id' => $unit->id,
                'visitor_name' => 'Bloqueado Total',
                'access_verification_id' => AccessVerification::PHONE_CALL,
            ]);
        });

        $response = $this->tenantRequest($secToken, $tenant)->getJson('/api/access/now')->assertStatus(200);

        $ids = array_column($response->json('data'), 'id');
        $this->assertSame([$insideId], $ids, 'Only the visitor without exit (and not denied) is inside');
        $this->assertNotContains($exitedId, $ids);

        // Without read.access_log the gate list is forbidden.
        $resident = $this->makeUser($tenant);
        $this->tenantRequest($this->token($resident, ['read.visitor']), $tenant)
            ->getJson('/api/access/now')
            ->assertStatus(403);
    }

    // =========================================================================
    // Resident: own bitácora (access-11)
    // =========================================================================

    public function test_my_log_returns_only_logs_of_the_callers_units(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $unitMine = $this->makeUnit($tenant);
        $unitOther = $this->makeUnit($tenant);

        $resident = $this->makeUser($tenant);
        $this->linkToUnit($tenant, $unitMine, $resident);

        $mineLogId = null;
        $tenant->run(function () use ($unitMine, $unitOther, &$mineLogId): void {
            $service = app(AccessService::class);

            $mineLogId = $service->manualEntry([
                'unit_id' => $unitMine->id,
                'visitor_name' => 'Visita Mía',
                'access_verification_id' => AccessVerification::PHONE_CALL,
            ])->id;

            $service->manualEntry([
                'unit_id' => $unitOther->id,
                'visitor_name' => 'Visita Ajena',
                'access_verification_id' => AccessVerification::PHONE_CALL,
            ]);
        });

        // Self-scoped: no broad read.access_log ability required (§14.2 Resi ✅).
        $response = $this->tenantRequest($this->token($resident, []), $tenant)
            ->getJson('/api/access/my-log')
            ->assertStatus(200);

        $ids = array_column($response->json('data'), 'id');
        $this->assertSame([$mineLogId], $ids, 'Only the logs of MY units are visible');
    }
}
