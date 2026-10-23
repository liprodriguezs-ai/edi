<?php

declare(strict_types=1);

namespace Modules\FollowUp\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\FollowUp\Models\FollowUp;
use Modules\FollowUp\Models\FollowUpType;
use Modules\FollowUp\Tests\Support\WithFollowUpFixtures;
use Modules\Screening\Events\ScreeningCompleted;
use Modules\Screening\Models\Screening;
use Tests\TestCase;

/**
 * REQ-FUP-003.
 */
final class FollowUpIdempotencyTest extends TestCase
{
    use RefreshDatabase;
    use WithFollowUpFixtures;

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    public function test_double_dispatch_is_a_no_op(): void
    {
        $this->seedPlatformRegistry();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $token = $this->administratorToken($tenant);
        $version = $this->defaultVersion();

        $fx = $this->inTenant($tenant, fn () => $this->makeChildVisit(570));
        $screeningId = $this->applyAndComplete($token, $tenant, $fx['visit'], $this->g10RedAnswers($version));

        $before = $this->inTenant($tenant, fn () => FollowUp::where('screening_id', $screeningId)->orderBy('follow_up_type_id')->get()->toArray());
        $this->assertCount(2, $before);

        $this->inTenant($tenant, function () use ($screeningId): void {
            $screening = Screening::findOrFail($screeningId);
            event(new ScreeningCompleted($screening));
        });

        $after = $this->inTenant($tenant, fn () => FollowUp::where('screening_id', $screeningId)->orderBy('follow_up_type_id')->get()->toArray());
        $this->assertCount(2, $after);
        $this->assertSame($before, $after);
    }

    public function test_manual_duplicate_returns_422(): void
    {
        $this->seedPlatformRegistry();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $token = $this->administratorToken($tenant);
        $version = $this->defaultVersion();

        $fx = $this->inTenant($tenant, fn () => $this->makeChildVisit(570));
        $screeningId = $this->applyAndComplete($token, $tenant, $fx['visit'], $this->g10RedAnswers($version));

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/follow_up', [
                'screening_id' => $screeningId,
                'follow_up_type_id' => FollowUpType::SPECIALIST_REFERRAL,
                'due_date' => now()->addDays(30)->toDateString(),
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'FOLLOW_UP_DUPLICATE');
    }
}
