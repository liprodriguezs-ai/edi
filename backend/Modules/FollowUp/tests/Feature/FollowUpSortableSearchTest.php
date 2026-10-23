<?php

declare(strict_types=1);

namespace Modules\FollowUp\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * The follow-up list sorts by due_date ascending on first load -- an overdue
 * follow-up is the whole point of the screen. Orion's sortableBy() defaults to an
 * EMPTY list, so a controller that never declares it rejects every sort with
 * "The due_date field is not whitelisted", and the screen renders zero rows with
 * no error shown to the user.
 *
 * This only became reachable once /search stopped answering 405: the same request
 * used to fail earlier, for a different reason, and hid this one behind it.
 */
final class FollowUpSortableSearchTest extends TestCase
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

    public function test_follow_ups_can_be_sorted_by_due_date(): void
    {
        [$tenantA, $tokenA] = $this->twoTenants();

        $this->tenantRequest($tokenA, $tenantA)
            ->postJson('/api/follow_up/search', [
                'sort' => [['field' => 'due_date', 'direction' => 'asc']],
            ])
            ->assertOk();
    }
}
