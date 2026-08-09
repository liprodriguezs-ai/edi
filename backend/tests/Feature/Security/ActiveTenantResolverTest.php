<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Services\ActiveTenantResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Plan 061: ActiveTenantResolver::resolveForRegularUser now fetches the user's
 * tenant memberships ONCE as full models (`$user->tenants()->get()->keyBy(...)`)
 * and selects from that in-memory collection, instead of plucking ids and then
 * re-querying `Tenant::find()` for the matched candidate.
 *
 * This suite locks in behavioral parity with the pre-change two-query version:
 * the exact priority order (X-Tenant-Id if member -> last_active if member ->
 * first) and, above all, the non-negotiable security invariant that an
 * X-Tenant-Id for a tenant the user does NOT belong to is never honored.
 */
final class ActiveTenantResolverTest extends TestCase
{
    use RefreshDatabase;
    use WithTenantSetup;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    private ActiveTenantResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new ActiveTenantResolver;
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    private function requestWithTenantHeader(?string $tenantId): Request
    {
        $request = Request::create('/api/whatever', 'GET');

        if ($tenantId !== null) {
            $request->headers->set('X-Tenant-Id', $tenantId);
        }

        return $request;
    }

    public function test_x_tenant_id_header_is_honored_when_user_is_a_member(): void
    {
        $tenantA = $this->makeTenant('Cluster A');
        $tenantB = $this->makeTenant('Cluster B');
        $user = $this->makeUser($tenantA);
        $user->tenants()->attach($tenantB->id);

        $resolved = $this->resolver->resolve($this->requestWithTenantHeader($tenantB->id), $user);

        $this->assertNotNull($resolved);
        $this->assertSame($tenantB->id, $resolved->id);
    }

    /**
     * The non-negotiable invariant (plan 061 STOP condition / maintenance note):
     * X-Tenant-Id for a tenant the user is NOT a member of must never be
     * honored, even though the in-memory rewrite could easily regress into
     * "does this id exist as a Tenant" instead of "is it in MY memberships".
     */
    public function test_x_tenant_id_header_of_non_member_tenant_is_not_honored(): void
    {
        $tenantA = $this->makeTenant('Cluster A');
        $foreignTenant = $this->makeTenant('Foreign Cluster');
        $user = $this->makeUser($tenantA);
        // $user is intentionally NOT attached to $foreignTenant.

        $resolved = $this->resolver->resolve($this->requestWithTenantHeader($foreignTenant->id), $user);

        $this->assertNotNull($resolved);
        $this->assertSame($tenantA->id, $resolved->id, 'Non-member X-Tenant-Id must fall through, not be honored.');
    }

    public function test_header_member_tenant_wins_over_last_active(): void
    {
        $tenantA = $this->makeTenant('Cluster A');
        $tenantB = $this->makeTenant('Cluster B');
        $tenantC = $this->makeTenant('Cluster C');
        $user = $this->makeUser($tenantA);
        $user->tenants()->attach([$tenantB->id, $tenantC->id]);
        $user->withoutEvents(function () use ($user, $tenantB): void {
            $user->updateQuietly(['last_active_tenant_id' => $tenantB->id]);
        });

        $resolved = $this->resolver->resolve($this->requestWithTenantHeader($tenantC->id), $user);

        $this->assertNotNull($resolved);
        $this->assertSame($tenantC->id, $resolved->id, 'X-Tenant-Id must take priority over last_active_tenant_id.');
    }

    public function test_last_active_tenant_is_honored_when_member_and_no_header(): void
    {
        $tenantA = $this->makeTenant('Cluster A');
        $tenantB = $this->makeTenant('Cluster B');
        $user = $this->makeUser($tenantA);
        $user->tenants()->attach($tenantB->id);
        $user->withoutEvents(function () use ($user, $tenantB): void {
            $user->updateQuietly(['last_active_tenant_id' => $tenantB->id]);
        });

        $resolved = $this->resolver->resolve($this->requestWithTenantHeader(null), $user);

        $this->assertNotNull($resolved);
        $this->assertSame($tenantB->id, $resolved->id);
    }

    public function test_last_active_of_non_member_falls_through_to_first_membership(): void
    {
        $tenantA = $this->makeTenant('Cluster A');
        $foreignTenant = $this->makeTenant('Foreign Cluster');
        $user = $this->makeUser($tenantA);
        // last_active points at a tenant the user is NOT (or no longer) a member of.
        $user->withoutEvents(function () use ($user, $foreignTenant): void {
            $user->updateQuietly(['last_active_tenant_id' => $foreignTenant->id]);
        });

        $resolved = $this->resolver->resolve($this->requestWithTenantHeader(null), $user);

        $this->assertNotNull($resolved);
        $this->assertSame($tenantA->id, $resolved->id);
    }

    public function test_user_with_no_tenant_membership_resolves_to_null(): void
    {
        $tenant = $this->makeTenant('Unrelated Cluster');
        $user = $this->makeUser($tenant);
        $user->tenants()->detach($tenant->id);

        $resolved = $this->resolver->resolve($this->requestWithTenantHeader(null), $user);

        $this->assertNull($resolved);
    }

    /**
     * The actual win from plan 061: resolving now issues a single membership
     * query (the `tenants()->get()` join) instead of a pluck query PLUS a
     * separate `Tenant::find()` for the matched candidate.
     */
    public function test_resolving_by_x_tenant_id_issues_a_single_membership_query(): void
    {
        $tenantA = $this->makeTenant('Cluster A');
        $tenantB = $this->makeTenant('Cluster B');
        $user = $this->makeUser($tenantA);
        $user->tenants()->attach($tenantB->id);

        DB::enableQueryLog();
        DB::flushQueryLog();

        $resolved = $this->resolver->resolve($this->requestWithTenantHeader($tenantB->id), $user);

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame($tenantB->id, $resolved?->id);

        $tenantQueries = array_filter(
            $queries,
            static fn (array $entry): bool => str_contains(strtolower((string) $entry['query']), 'from "tenant"'),
        );

        $this->assertCount(
            1,
            $tenantQueries,
            'resolveForRegularUser must issue exactly one membership query (the pluck+find double-query was collapsed).',
        );
    }
}
