<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Role as SpatieRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Notification\Models\Notification;
use Modules\Notification\Models\NotificationUser;
use Modules\Tenant\Models\Tenant;
use Modules\UserProfile\Models\UserProfile;
use QCH\Core\Models\Role;
use Stancl\Tenancy\Events\TenantCreated;
use Tests\TestCase;

/**
 * Right to be forgotten (§18.3.4 — global-06): POST /api/user/{id}/anonymize.
 *
 * Admin-only: wipes the central PII (placeholder name, null email/phone), purges
 * the CURRENT tenant's user_profile, revokes every token and disables auth —
 * while PRESERVING the user row (same UUID) so the ledger and history keep their
 * referential integrity. Residents get 403, the last account owner of a organization
 * can never be anonymized, and self-anonymization is rejected.
 */
final class AnonymizationTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    private function makeTenant(string $name = 'RTBF Organization'): Tenant
    {
        return Event::fakeFor(
            static fn (): Tenant => Tenant::create([
                'id' => (string) Str::uuid(),
                'name' => $name,
                'status' => Tenant::STATUS_ACTIVO,
            ]),
            [TenantCreated::class],
        );
    }

    private function makeUser(Tenant $tenant, string $role): User
    {
        SpatieRole::firstOrCreate(['name' => $role, 'guard_name' => 'api']);

        $user = User::withoutGlobalScopes()->create([
            'name' => 'Vecino '.Str::random(5),
            'last_name' => 'Pérez',
            'email' => Str::random(10).'@test.com',
            'phone' => (string) random_int(5500000000, 5599999999),
            'password' => Hash::make('secret1234'),
            'auth_active' => true,
        ]);

        $user->tenants()->attach($tenant->id);
        $user->assignRole($role);

        return $user;
    }

    private function makeProfile(Tenant $tenant, User $user): UserProfile
    {
        $profile = null;
        $tenant->run(function () use (&$profile, $user): void {
            $profile = UserProfile::create([
                'user_id' => $user->id,
                'rfc' => 'RERE800101AAA',
                'curp' => 'CUCU800101HDFRRN09',
                'address' => 'Privada Encinos 12',
                'emergency_contact_name' => 'Contacto X',
                'emergency_contact_phone' => '5512345678',
            ]);
        });

        return $profile;
    }

    private function makeCenterRow(Tenant $tenant, User $user): NotificationUser
    {
        $row = null;
        $tenant->run(function () use (&$row, $user): void {
            $notification = Notification::create([
                'title' => 'Pago confirmado',
                'message' => 'Se registró tu pago (folio R-2026-000777).',
                'status' => 'sent',
            ]);
            $row = NotificationUser::create([
                'notification_id' => $notification->id,
                'user_id' => $user->id,
                'is_read' => false,
            ]);
        });

        return $row;
    }

    private function request(string $token, Tenant $tenant): self
    {
        $this->app['auth']->forgetGuards();
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        return $this->withToken($token)->withHeader('X-Tenant-Id', $tenant->id);
    }

    private function adminToken(User $admin): string
    {
        return $admin->createToken('admin', ['update.user', 'read.user'])->plainTextToken;
    }

    public function test_admin_anonymizes_a_resident_wiping_pii_and_preserving_the_ledger(): void
    {
        $tenant = $this->makeTenant();
        $admin = $this->makeUser($tenant, Role::SLUG_ADMINISTRATOR);
        $resident = $this->makeUser($tenant, Role::SLUG_RESIDENT);

        $profile = $this->makeProfile($tenant, $resident);
        $centerRow = $this->makeCenterRow($tenant, $resident);
        $resident->createToken('mobile', ['*']);

        $this->request($this->adminToken($admin), $tenant)
            ->postJson("/api/user/{$resident->id}/anonymize")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $resident->id)
            ->assertJsonPath('data.name', 'Usuario eliminado');

        // Central PII wiped; the row itself survives (same UUID, not soft-deleted).
        $this->assertDatabaseHas('user', [
            'id' => $resident->id,
            'name' => 'Usuario eliminado',
            'last_name' => null,
            'email' => null,
            'phone' => null,
            'auth_active' => false,
            'deleted_at' => null,
        ]);

        // Current tenant's profile purged: PII nulled AND row soft-deleted.
        $this->assertDatabaseHas('user_profile', [
            'id' => $profile->id,
            'rfc' => null,
            'curp' => null,
            'address' => null,
        ]);
        $this->assertDatabaseMissing('user_profile', [
            'id' => $profile->id,
            'deleted_at' => null,
        ]);

        // Every session revoked.
        $this->assertSame(0, $resident->tokens()->count());

        // Ledger/history intact: rows that reference the user keep resolving.
        $this->assertDatabaseHas('notification_user', [
            'id' => $centerRow->id,
            'user_id' => $resident->id,
        ]);

        // The operation itself is audited, without PII payloads.
        $this->assertDatabaseHas('audits', [
            'event' => 'anonymized',
            'auditable_id' => $resident->id,
            'user_id' => $admin->id,
        ]);
    }

    public function test_resident_cannot_anonymize_a_neighbour(): void
    {
        $tenant = $this->makeTenant();
        $resident = $this->makeUser($tenant, Role::SLUG_RESIDENT);
        $neighbour = $this->makeUser($tenant, Role::SLUG_RESIDENT);

        // Same self-service abilities a resident token actually carries.
        $token = $resident->createToken('resident', ['read.user', 'update.user'])->plainTextToken;

        $this->request($token, $tenant)
            ->postJson("/api/user/{$neighbour->id}/anonymize")
            ->assertStatus(403);

        $this->assertDatabaseHas('user', [
            'id' => $neighbour->id,
            'email' => $neighbour->email,
        ]);
    }

    public function test_last_account_owner_of_the_tenant_cannot_be_anonymized(): void
    {
        $tenant = $this->makeTenant();
        $admin = $this->makeUser($tenant, Role::SLUG_ADMINISTRATOR);
        $owner = $this->makeUser($tenant, Role::SLUG_ACCOUNT_OWNER);

        $this->request($this->adminToken($admin), $tenant)
            ->postJson("/api/user/{$owner->id}/anonymize")
            ->assertStatus(422);

        $this->assertDatabaseHas('user', [
            'id' => $owner->id,
            'email' => $owner->email,
            'auth_active' => true,
        ]);
    }

    public function test_account_owner_can_be_anonymized_when_another_owner_remains(): void
    {
        $tenant = $this->makeTenant();
        $admin = $this->makeUser($tenant, Role::SLUG_ADMINISTRATOR);
        $leavingOwner = $this->makeUser($tenant, Role::SLUG_ACCOUNT_OWNER);
        $this->makeUser($tenant, Role::SLUG_ACCOUNT_OWNER);

        $this->request($this->adminToken($admin), $tenant)
            ->postJson("/api/user/{$leavingOwner->id}/anonymize")
            ->assertStatus(200);

        $this->assertDatabaseHas('user', [
            'id' => $leavingOwner->id,
            'name' => 'Usuario eliminado',
            'email' => null,
        ]);
    }

    public function test_self_anonymization_is_rejected(): void
    {
        $tenant = $this->makeTenant();
        $admin = $this->makeUser($tenant, Role::SLUG_ADMINISTRATOR);

        $this->request($this->adminToken($admin), $tenant)
            ->postJson("/api/user/{$admin->id}/anonymize")
            ->assertStatus(422);

        $this->assertDatabaseHas('user', [
            'id' => $admin->id,
            'email' => $admin->email,
        ]);
    }

    public function test_member_of_another_tenant_resolves_to_404(): void
    {
        $tenantA = $this->makeTenant('RTBF Organization A');
        $tenantB = $this->makeTenant('RTBF Organization B');
        $admin = $this->makeUser($tenantA, Role::SLUG_ADMINISTRATOR);
        $foreign = $this->makeUser($tenantB, Role::SLUG_RESIDENT);

        $this->request($this->adminToken($admin), $tenantA)
            ->postJson("/api/user/{$foreign->id}/anonymize")
            ->assertStatus(404);

        $this->assertDatabaseHas('user', [
            'id' => $foreign->id,
            'email' => $foreign->email,
        ]);
    }

    public function test_anonymize_requires_authentication(): void
    {
        $tenant = $this->makeTenant();
        $resident = $this->makeUser($tenant, Role::SLUG_RESIDENT);

        $this->postJson("/api/user/{$resident->id}/anonymize")->assertStatus(401);
    }
}
