<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\Role;
use App\Models\User;
use App\Notifications\Channels\LogChannel;
use App\Notifications\Concerns\NotifiesResidents;
use App\Notifications\Contracts\NotificationChannelInterface;
use App\Notifications\Services\NotificationDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Tenant\Models\Tenant;
use Spatie\Permission\PermissionRegistrar;
use Stancl\Tenancy\Events\TenantCreated;
use Tests\TestCase;

/**
 * Tenant-scoped role fan-out (cross-tenant leak pattern: access-01 / providers-02 /
 * treasury-01). Spatie roles are GLOBAL in this project, so resolving recipients by
 * role alone notifies the same role across ALL clusters. The canonical contract:
 *
 *   NotificationDispatcher::tenantRoleUserIds($tenant, $roles)  → membership ∩ role
 *   NotificationDispatcher::toTenantRole($tenant, $roles, ...)  → resolve + dispatch
 *   NotifiesResidents::userIdsWithRole($roles)                  → same, current tenant
 *
 * A guard/treasurer of ANOTHER tenant with the same global role must NEVER be a
 * recipient.
 */
final class NotificationFanOutTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['security', 'treasurer', 'president'] as $slug) {
            Role::firstOrCreate(['name' => $slug, 'guard_name' => 'api']);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    private function makeTenant(string $name): Tenant
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

    private function makeMember(Tenant $tenant, ?string $roleSlug = null): User
    {
        $user = User::withoutGlobalScopes()->create([
            'id' => (string) Str::uuid(),
            'name' => 'FanOut '.Str::random(5),
            'email' => Str::random(10).'@test.com',
            'password' => Hash::make('secret1234'),
            'auth_active' => true,
        ]);

        $user->tenants()->attach($tenant->id);

        if ($roleSlug !== null) {
            $user->assignRole($roleSlug);
        }

        return $user;
    }

    /**
     * @return object{recipients: array<int, mixed>}
     */
    private function spyLogChannel(): object
    {
        $spy = new class implements NotificationChannelInterface
        {
            /** @var array<int, mixed> */
            public array $recipients = [];

            public function send(array $recipients, string $title, string $body, array $data = []): void
            {
                $this->recipients = array_merge($this->recipients, $recipients);
            }
        };

        $this->app->instance(LogChannel::class, $spy);

        return $spy;
    }

    /**
     * @return object harness exposing NotifiesResidents::userIdsWithRole
     */
    private function roleResolverHarness(): object
    {
        return new class
        {
            use NotifiesResidents;

            /**
             * @param  string|array<int, string>  $roles
             * @return array<int, string>
             */
            public function resolve(string|array $roles): array
            {
                return $this->userIdsWithRole($roles);
            }
        };
    }

    public function test_tenant_role_user_ids_only_returns_members_of_the_given_tenant(): void
    {
        $tenantA = $this->makeTenant('Cluster A');
        $tenantB = $this->makeTenant('Cluster B');

        $guardA = $this->makeMember($tenantA, 'security');
        $guardB = $this->makeMember($tenantB, 'security');
        $this->makeMember($tenantA); // member without the role — must not match

        $ids = app(NotificationDispatcher::class)->tenantRoleUserIds($tenantA, 'security');

        $this->assertSame([$guardA->id], $ids);
        $this->assertNotContains($guardB->id, $ids, 'Same global role in ANOTHER tenant must not resolve');
    }

    public function test_tenant_role_user_ids_accepts_tenant_id_string_and_multiple_roles(): void
    {
        $tenantA = $this->makeTenant('Cluster A');
        $tenantB = $this->makeTenant('Cluster B');

        $treasurerA = $this->makeMember($tenantA, 'treasurer');
        $presidentA = $this->makeMember($tenantA, 'president');
        $this->makeMember($tenantB, 'treasurer');

        $ids = app(NotificationDispatcher::class)
            ->tenantRoleUserIds((string) $tenantA->id, ['treasurer', 'president']);

        $this->assertEqualsCanonicalizing([$treasurerA->id, $presidentA->id], $ids);
    }

    public function test_tenant_role_user_ids_with_unknown_role_returns_empty(): void
    {
        $tenantA = $this->makeTenant('Cluster A');
        $this->makeMember($tenantA, 'security');

        $this->assertSame(
            [],
            app(NotificationDispatcher::class)->tenantRoleUserIds($tenantA, 'nonexistent_role'),
        );
    }

    public function test_to_tenant_role_dispatches_only_to_tenant_members(): void
    {
        $spy = $this->spyLogChannel();

        $tenantA = $this->makeTenant('Cluster A');
        $tenantB = $this->makeTenant('Cluster B');

        $guardA = $this->makeMember($tenantA, 'security');
        $guardB = $this->makeMember($tenantB, 'security');

        $delivered = app(NotificationDispatcher::class)->toTenantRole(
            $tenantA,
            'security',
            'QR cancelado',
            'Se canceló un código de acceso.',
            ['category' => 'access'],
            ['log'],
        );

        $this->assertSame([$guardA->id], $delivered);
        $this->assertContains($guardA->id, $spy->recipients);
        $this->assertNotContains($guardB->id, $spy->recipients, 'Cross-tenant guard must not receive the dispatch');
    }

    public function test_to_tenant_role_without_recipients_dispatches_nothing(): void
    {
        $spy = $this->spyLogChannel();
        $tenantA = $this->makeTenant('Cluster A');

        $delivered = app(NotificationDispatcher::class)->toTenantRole(
            $tenantA, 'security', 'Título', 'Cuerpo', [], ['log'],
        );

        $this->assertSame([], $delivered);
        $this->assertSame([], $spy->recipients);
    }

    // =========================================================================
    // NotifiesResidents::userIdsWithRole — the shared helper every emitter uses
    // =========================================================================

    public function test_user_ids_with_role_is_scoped_to_the_current_tenant(): void
    {
        $tenantA = $this->makeTenant('Cluster A');
        $tenantB = $this->makeTenant('Cluster B');

        $guardA = $this->makeMember($tenantA, 'security');
        $guardB = $this->makeMember($tenantB, 'security');

        $ids = null;
        $tenantA->run(function () use (&$ids): void {
            $ids = $this->roleResolverHarness()->resolve('security');
        });

        $this->assertSame([$guardA->id], $ids);
        $this->assertNotContains($guardB->id, $ids);
    }

    public function test_user_ids_with_role_without_tenant_context_resolves_nobody(): void
    {
        $tenantA = $this->makeTenant('Cluster A');
        $this->makeMember($tenantA, 'security');

        $this->assertSame(
            [],
            $this->roleResolverHarness()->resolve('security'),
            'Without a tenant context the helper must never fan out platform-wide',
        );
    }
}
