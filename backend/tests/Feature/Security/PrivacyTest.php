<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Role as SpatieRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Tenant\Models\Tenant;
use Modules\UserProfile\Models\UserProfile;
use QCH\Core\Models\Audit;
use QCH\Core\Models\Role;
use Stancl\Tenancy\Events\TenantCreated;
use Tests\TestCase;

/**
 * Intra-tenant privacy (§18.3 — findings global-03 / global-07 / sec-input-08).
 *
 * A resident must never read or edit a NEIGHBOUR's PII, mine RFC/CURP via
 * search, flip their own auth_active, or find sensitive payloads in audits.
 */
final class PrivacyTest extends TestCase
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

    private function makeTenant(string $name = 'Privacy Organization'): Tenant
    {
        return Event::fakeFor(
            static fn (): Tenant => Tenant::create([
                'id' => (string) Str::uuid7(),
                'name' => $name,
                'status' => Tenant::STATUS_ACTIVO,
            ]),
            [TenantCreated::class],
        );
    }

    private function makeUser(Tenant $tenant, string $role): User
    {
        SpatieRole::firstOrCreate(['name' => $role, 'guard_name' => 'api']);

        $user = User::create([
            'name' => 'Test '.Str::random(5),
            'email' => Str::random(10).'@test.com',
            'password' => Hash::make('secret1234'),
            'auth_active' => true,
        ]);

        $user->tenants()->attach($tenant->id);
        $user->assignRole($role);

        return $user;
    }

    private function makeProfile(Tenant $tenant, User $user, string $rfc): UserProfile
    {
        $profile = null;
        $tenant->run(function () use (&$profile, $user, $rfc): void {
            $profile = UserProfile::create([
                'user_id' => $user->id,
                'rfc' => $rfc,
                'curp' => 'CUCU800101HDFRRN09',
                'address' => 'Privada Encinos 12',
                'emergency_contact_name' => 'Contacto X',
                'emergency_contact_phone' => '5512345678',
            ]);
        });

        return $profile;
    }

    private function request(string $token, Tenant $tenant): self
    {
        $this->app['auth']->forgetGuards();
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        return $this->withToken($token)->withHeader('X-Tenant-Id', $tenant->id);
    }

    private function residentToken(User $user): string
    {
        return $user->createToken('resident', [
            'read.user_profile', 'update.user_profile', 'read.user', 'update.user',
        ])->plainTextToken;
    }

    // =========================================================================
    // global-03 — user_profile is self-service for residents
    // =========================================================================

    public function test_resident_index_returns_only_their_own_profile(): void
    {
        $tenant = $this->makeTenant();
        $resident = $this->makeUser($tenant, Role::SLUG_RESIDENT);
        $neighbour = $this->makeUser($tenant, Role::SLUG_RESIDENT);

        $own = $this->makeProfile($tenant, $resident, 'RERE800101AAA');
        $this->makeProfile($tenant, $neighbour, 'NENE800101BBB');

        $response = $this->request($this->residentToken($resident), $tenant)
            ->getJson('/api/user_profile')
            ->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertCount(1, $ids);
        $this->assertSame($own->id, $ids->first());
    }

    public function test_resident_cannot_show_or_update_a_neighbours_profile(): void
    {
        $tenant = $this->makeTenant();
        $resident = $this->makeUser($tenant, Role::SLUG_RESIDENT);
        $neighbour = $this->makeUser($tenant, Role::SLUG_RESIDENT);

        $this->makeProfile($tenant, $resident, 'RERE800101AAA');
        $foreign = $this->makeProfile($tenant, $neighbour, 'NENE800101BBB');

        $token = $this->residentToken($resident);

        $this->request($token, $tenant)
            ->getJson("/api/user_profile/{$foreign->id}")
            ->assertStatus(404);

        $this->request($token, $tenant)
            ->patchJson("/api/user_profile/{$foreign->id}", ['notes' => 'hack'])
            ->assertStatus(404);
    }

    public function test_administrator_still_sees_every_profile_in_the_tenant(): void
    {
        $tenant = $this->makeTenant();
        $admin = $this->makeUser($tenant, 'administrator');
        $neighbour = $this->makeUser($tenant, Role::SLUG_RESIDENT);

        $this->makeProfile($tenant, $admin, 'ADAD800101AAA');
        $this->makeProfile($tenant, $neighbour, 'NENE800101BBB');

        $token = $admin->createToken('admin', ['read.user_profile', 'update.user_profile'])->plainTextToken;

        $response = $this->request($token, $tenant)
            ->getJson('/api/user_profile')
            ->assertStatus(200);

        $this->assertCount(2, $response->json('data'));
    }

    public function test_resident_cannot_search_profiles_by_rfc(): void
    {
        $tenant = $this->makeTenant();
        $resident = $this->makeUser($tenant, Role::SLUG_RESIDENT);
        $neighbour = $this->makeUser($tenant, Role::SLUG_RESIDENT);

        $this->makeProfile($tenant, $resident, 'RERE800101AAA');
        $this->makeProfile($tenant, $neighbour, 'NENE800101BBB');

        // Even probing the neighbour's exact RFC, the self-service scope caps
        // results at the caller's own profile — zero PII mining.
        $response = $this->request($this->residentToken($resident), $tenant)
            ->postJson('/api/user_profile/search', [
                'search' => ['value' => 'NENE800101BBB'],
            ]);

        if ($response->status() === 200) {
            $this->assertSame([], collect($response->json('data'))->pluck('rfc')->filter(
                static fn (?string $rfc): bool => $rfc === 'NENE800101BBB'
            )->values()->all());
        } else {
            $response->assertStatus(422);
        }
    }

    // =========================================================================
    // sec-input-08 — auth_active is not mass-assignable via PUT /user
    // =========================================================================

    public function test_auth_active_cannot_be_mass_assigned_through_user_update(): void
    {
        $tenant = $this->makeTenant();
        $resident = $this->makeUser($tenant, Role::SLUG_RESIDENT);
        $target = $this->makeUser($tenant, Role::SLUG_RESIDENT);
        $target->update(['auth_active' => false]);

        $admin = $this->makeUser($tenant, 'administrator');
        $token = $admin->createToken('admin', ['read.user', 'update.user'])->plainTextToken;

        $this->request($token, $tenant)
            ->patchJson("/api/user/{$target->id}", [
                'name' => 'Renamed',
                'auth_active' => true,
                'email_verified_at' => now()->toDateTimeString(),
            ])
            ->assertStatus(200);

        $fresh = User::withoutGlobalScopes()->find($target->id);
        $this->assertSame('Renamed', $fresh->name);
        $this->assertFalse((bool) $fresh->auth_active, 'auth_active must not be mass-assignable');
        $this->assertNull($fresh->email_verified_at, 'email_verified_at must not be mass-assignable');
    }

    // =========================================================================
    // global-07 — PII never lands in the audit trail
    // =========================================================================

    public function test_profile_audits_redact_pii_values(): void
    {
        $tenant = $this->makeTenant();
        $resident = $this->makeUser($tenant, Role::SLUG_RESIDENT);
        $profile = $this->makeProfile($tenant, $resident, 'RERE800101AAA');

        // Contract: every sensitive column is excluded from auditing, so the
        // audited diff of a PII-only update is EMPTY (owen-it then skips the row).
        $excluded = $profile->getAuditExclude();
        foreach (['rfc', 'curp', 'address', 'emergency_contact_name', 'emergency_contact_phone', 'notes'] as $piiField) {
            $this->assertContains($piiField, $excluded, "user_profile.{$piiField} must be excluded from audits");
        }

        // User does not implement the Auditable contract (it is never audited),
        // so its credentials cannot leak through audits; the $auditExclude on the
        // model is defensive for the day auditing is enabled there.
        if (method_exists(new User, 'getAuditExclude')) {
            $userExcluded = (new User)->getAuditExclude();
            foreach (['password', 'remember_token', 'verification_code', 'token_sender'] as $secretField) {
                $this->assertContains($secretField, $userExcluded, "user.{$secretField} must be excluded from audits");
            }
        }

        // And any audit row that may exist for the profile carries no PII payload.
        $tenant->run(function () use ($profile): void {
            $profile->update(['rfc' => 'NUEV800101ZZZ', 'notes' => 'dato sensible']);
        });

        $audits = Audit::query()
            ->where('auditable_type', UserProfile::class)
            ->where('auditable_id', $profile->id)
            ->get();

        foreach ($audits as $audit) {
            $payload = json_encode([$audit->old_values, $audit->new_values]);
            $this->assertStringNotContainsString('RERE800101AAA', $payload);
            $this->assertStringNotContainsString('NUEV800101ZZZ', $payload);
            $this->assertStringNotContainsString('dato sensible', $payload);
        }
    }
}
