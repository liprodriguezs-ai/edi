<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Models\Role as AppRole;
use App\Models\User;
use App\Notifications\Channels\LogChannel;
use App\Notifications\Contracts\NotificationChannelInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Access\Models\AccessLog;
use Modules\Access\Models\AccessVerification;
use Modules\Access\Models\BlacklistEntry;
use Modules\Access\Models\BlacklistType;
use Modules\Access\Models\Visitor;
use Modules\Access\Models\VisitorMotive;
use Modules\Access\Models\VisitorStatus;
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
 * §14.4.4–14.4.5 / §14.6 Flows B-C — remote approval (access-02, server-side
 * minimum):
 *
 *   1. POST access/entry-request (gate, create.access_log): registers the visit
 *      without prior QR → Visitor in pending_approval + URGENT push (category
 *      'access') to the unit's residents.
 *   2. POST visitor/{id}/approve | /reject — ONLY an active resident of THAT unit.
 *      Approve stamps the entry (app_remote verification + authorizer) and alerts
 *      the gate; reject records the denial in the immutable log.
 *   3. The gate polls the visitor status (pending_approval → approved/rejected).
 *      The 60s timeout (§14.4.4) is handled GATE-side, falling back to
 *      manual-entry with phone verification.
 */
final class AccessApprovalFlowTest extends TestCase
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
            'name' => 'Flow '.Str::random(6),
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
     * Gate registers the entry request and returns the created visitor id.
     */
    private function requestEntry(Tenant $tenant, Unit $unit, User $guard, string $visitorName): string
    {
        $response = $this->tenantRequest($this->token($guard, ['create.access_log']), $tenant)
            ->postJson('/api/access/entry-request', [
                'unit_id' => $unit->id,
                'name' => $visitorName,
                'visitor_motive_id' => Catalog::id(VisitorMotive::class, 'Social / familiar'),
                'plate' => 'XXX-123-Y',
                'id_document_type' => 'ine',
                'id_document_number' => 'GOMP901231',
            ])
            ->assertStatus(201);

        return $response->json('data.id');
    }

    /**
     * @return object{recipients: array<int, mixed>, payloads: array<int, array<string, mixed>>}
     */
    private function spyLogChannel(): object
    {
        $spy = new class implements NotificationChannelInterface
        {
            /** @var array<int, mixed> */
            public array $recipients = [];

            /** @var array<int, array<string, mixed>> */
            public array $payloads = [];

            public function send(array $recipients, string $title, string $body, array $data = []): void
            {
                $this->recipients = array_merge($this->recipients, $recipients);
                $this->payloads[] = ['title' => $title, 'data' => $data];
            }
        };

        $this->app->instance(LogChannel::class, $spy);

        return $spy;
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
    // Entry request
    // =========================================================================

    public function test_entry_request_creates_pending_approval_visitor_and_pushes_urgent_to_resident(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);

        $resident = $this->makeUser($tenant);
        $this->linkToUnit($tenant, $unit, $resident);
        $guard = $this->makeUser($tenant, 'security');

        $spy = $this->spyLogChannel();

        $visitorId = $this->requestEntry($tenant, $unit, $guard, 'Pedro Hernández');

        $tenant->run(function () use ($visitorId): void {
            $visitor = Visitor::findOrFail($visitorId);
            $this->assertSame(VisitorStatus::PENDING_APPROVAL, $visitor->visitor_status_id);
            $this->assertSame('ine', $visitor->id_document_type);
            $this->assertSame('XXX-123-Y', $visitor->plate);
            $this->assertSame(0, AccessLog::count(), 'No entry may be stamped before the resident decides');
        });

        $this->assertUserNotified($tenant, $resident, 'Visita en caseta');

        // §17.4: category 'access' + urgent (bypasses quiet hours — the visit waits
        // at the gate).
        $payload = collect($spy->payloads)->firstWhere('title', 'Visita en caseta');
        $this->assertNotNull($payload);
        $this->assertSame('access', $payload['data']['category']);
        $this->assertTrue($payload['data']['urgent']);
        $this->assertContains($resident->id, $spy->recipients);
    }

    public function test_entry_request_for_blacklisted_visitor_is_denied_without_creating_visitor(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);

        $guard = $this->makeUser($tenant, 'security');

        $tenant->run(function (): void {
            BlacklistEntry::create([
                'blacklist_type_id' => BlacklistType::VISITOR,
                'value' => 'Carlos Peligroso',
                'is_active' => true,
            ]);
        });

        $this->tenantRequest($this->token($guard, ['create.access_log']), $tenant)
            ->postJson('/api/access/entry-request', [
                'unit_id' => $unit->id,
                'name' => 'Carlos Peligroso',
                'visitor_motive_id' => Catalog::id(VisitorMotive::class, 'Social / familiar'),
            ])
            ->assertStatus(422);

        $tenant->run(function (): void {
            $this->assertSame(0, Visitor::count(), 'No pending visitor for a blacklisted person');
            $denied = AccessLog::whereNotNull('denied_at')->first();
            $this->assertNotNull($denied);
            $this->assertNull($denied->entered_at);
        });

        $this->assertUserNotified($tenant, $guard, 'Alerta de lista negra');
    }

    // =========================================================================
    // Approve
    // =========================================================================

    public function test_resident_of_the_unit_approves_entry_is_stamped_app_remote_and_gate_alerted(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);

        $resident = $this->makeUser($tenant);
        $this->linkToUnit($tenant, $unit, $resident);
        $guard = $this->makeUser($tenant, 'security');

        $visitorId = $this->requestEntry($tenant, $unit, $guard, 'Pedro Hernández');

        $this->tenantRequest($this->token($resident, []), $tenant)
            ->postJson("/api/visitor/{$visitorId}/approve")
            ->assertStatus(200);

        $tenant->run(function () use ($visitorId, $resident): void {
            $visitor = Visitor::findOrFail($visitorId);
            $this->assertSame(VisitorStatus::APPROVED, $visitor->visitor_status_id);

            $log = AccessLog::where('visitor_id', $visitorId)->firstOrFail();
            $this->assertSame(AccessVerification::APP_REMOTE, $log->access_verification_id);
            $this->assertNotNull($log->entered_at);
            $this->assertNull($log->denied_at);
            $this->assertSame($resident->name, $log->authorized_by);
        });

        $this->assertUserNotified($tenant, $guard, 'Entrada autorizada');

        // A second decision on the same request is rejected (single use).
        $this->tenantRequest($this->token($resident, []), $tenant)
            ->postJson("/api/visitor/{$visitorId}/approve")
            ->assertStatus(422);
    }

    public function test_user_outside_the_unit_cannot_approve_or_reject(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);
        $otherUnit = $this->makeUnit($tenant);

        $resident = $this->makeUser($tenant);
        $this->linkToUnit($tenant, $unit, $resident);

        $neighbor = $this->makeUser($tenant);
        $this->linkToUnit($tenant, $otherUnit, $neighbor);

        $guard = $this->makeUser($tenant, 'security');
        $visitorId = $this->requestEntry($tenant, $unit, $guard, 'Pedro Hernández');

        // A neighbor of ANOTHER unit cannot decide.
        $this->tenantRequest($this->token($neighbor, []), $tenant)
            ->postJson("/api/visitor/{$visitorId}/approve")
            ->assertStatus(403);

        $this->tenantRequest($this->token($neighbor, []), $tenant)
            ->postJson("/api/visitor/{$visitorId}/reject")
            ->assertStatus(403);

        // Not even the guard who registered the request.
        $this->tenantRequest($this->token($guard, ['create.access_log']), $tenant)
            ->postJson("/api/visitor/{$visitorId}/approve")
            ->assertStatus(403);

        $tenant->run(function () use ($visitorId): void {
            $visitor = Visitor::findOrFail($visitorId);
            $this->assertSame(VisitorStatus::PENDING_APPROVAL, $visitor->visitor_status_id, 'Status untouched after unauthorized attempts');
            $this->assertSame(0, AccessLog::count());
        });
    }

    // =========================================================================
    // Reject
    // =========================================================================

    public function test_resident_rejects_entry_denial_is_logged_and_gate_alerted(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);

        $resident = $this->makeUser($tenant);
        $this->linkToUnit($tenant, $unit, $resident);
        $guard = $this->makeUser($tenant, 'security');

        $visitorId = $this->requestEntry($tenant, $unit, $guard, 'Pedro Hernández');

        $this->tenantRequest($this->token($resident, []), $tenant)
            ->postJson("/api/visitor/{$visitorId}/reject")
            ->assertStatus(200);

        $tenant->run(function () use ($visitorId): void {
            $visitor = Visitor::findOrFail($visitorId);
            $this->assertSame(VisitorStatus::REJECTED, $visitor->visitor_status_id);

            $log = AccessLog::where('visitor_id', $visitorId)->firstOrFail();
            $this->assertNotNull($log->denied_at);
            $this->assertNull($log->entered_at, 'A rejected request never stamps an entry');
            $this->assertSame('Rechazado por el residente', $log->denied_reason);
        });

        $this->assertUserNotified($tenant, $guard, 'Entrada rechazada');

        // The decision is final: approve after reject → 422.
        $this->tenantRequest($this->token($resident, []), $tenant)
            ->postJson("/api/visitor/{$visitorId}/approve")
            ->assertStatus(422);
    }

    // =========================================================================
    // Gate cannot bypass the pending approval by scanning the internal token
    // =========================================================================

    public function test_pending_approval_token_cannot_be_scanned_in(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);

        $resident = $this->makeUser($tenant);
        $this->linkToUnit($tenant, $unit, $resident);
        $guard = $this->makeUser($tenant, 'security');

        $visitorId = $this->requestEntry($tenant, $unit, $guard, 'Pedro Hernández');

        $qrToken = null;
        $tenant->run(function () use ($visitorId, &$qrToken): void {
            $qrToken = Visitor::findOrFail($visitorId)->qr_token;
        });

        $this->tenantRequest($this->token($guard, ['create.access_log']), $tenant)
            ->postJson('/api/access/scan-qr', ['token' => $qrToken])
            ->assertStatus(422)
            ->assertJsonPath('valid', false);

        $tenant->run(function (): void {
            $this->assertSame(0, AccessLog::count(), 'No entry without the resident decision');
        });
    }
}
