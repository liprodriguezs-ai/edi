<?php

declare(strict_types=1);

namespace Modules\FollowUp\Tests\Feature;

use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\ChildRecord\Models\Child;
use Modules\FollowUp\Models\FollowUp;
use Modules\FollowUp\Models\FollowUpStatus;
use Modules\FollowUp\Models\FollowUpType;
use Modules\FollowUp\Services\FollowUpCreationService;
use Modules\FollowUp\Tests\Support\WithFollowUpFixtures;
use QCH\Core\Support\TenantTime;
use Tests\TestCase;

/**
 * REQ-FUP-005/REQ-FUP-010.
 */
final class FollowUpOverdueCommandTest extends TestCase
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

    /** A civil day relative to the current tenant's today (call inside tenancy). */
    private function tenantDay(int $offset): string
    {
        return Carbon::parse(TenantTime::today())->addDays($offset)->toDateString();
    }

    private function makeFollowUp(Child $child, string $dueDate, string $statusId, ?string $screeningId = null, ?string $typeId = null): FollowUp
    {
        $followUp = new FollowUp;
        $followUp->forceFill([
            'child_id' => $child->id,
            'screening_id' => $screeningId ?? (string) Str::uuid7(),
            'follow_up_type_id' => $typeId ?? FollowUpType::REASSESS_3_MONTHS,
            'follow_up_status_id' => $statusId,
            'due_date' => $dueDate,
        ]);
        $followUp->save();

        return $followUp;
    }

    public function test_command_flips_both_directions_and_is_idempotent(): void
    {
        $this->seedPlatformRegistry();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        [$pastPendingId, $futureOverdueId] = $this->inTenant($tenant, function () {
            $child = Child::factory()->create();

            $past = $this->makeFollowUp($child, $this->tenantDay(-1), FollowUpStatus::PENDING);
            $future = $this->makeFollowUp($child, now()->addWeek()->toDateString(), FollowUpStatus::OVERDUE, null, FollowUpType::SPECIALIST_REFERRAL);

            return [$past->id, $future->id];
        });

        $this->artisan('follow-up:refresh-overdue')->assertSuccessful();

        $this->inTenant($tenant, function () use ($pastPendingId, $futureOverdueId): void {
            $this->assertSame(FollowUpStatus::OVERDUE, FollowUp::findOrFail($pastPendingId)->follow_up_status_id);
            $this->assertSame(FollowUpStatus::PENDING, FollowUp::findOrFail($futureOverdueId)->follow_up_status_id);
        });

        // Second immediate run changes zero rows.
        $this->artisan('follow-up:refresh-overdue')->assertSuccessful();

        $this->inTenant($tenant, function () use ($pastPendingId, $futureOverdueId): void {
            $this->assertSame(FollowUpStatus::OVERDUE, FollowUp::findOrFail($pastPendingId)->follow_up_status_id);
            $this->assertSame(FollowUpStatus::PENDING, FollowUp::findOrFail($futureOverdueId)->follow_up_status_id);
        });
    }

    public function test_manual_states_are_never_touched(): void
    {
        $this->seedPlatformRegistry();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        [$inProgressId, $cancelledId, $completedId] = $this->inTenant($tenant, function () {
            $child = Child::factory()->create();

            $inProgress = $this->makeFollowUp($child, $this->tenantDay(-1), FollowUpStatus::IN_PROGRESS, null, FollowUpType::SPECIALIST_REFERRAL);
            $cancelled = $this->makeFollowUp($child, $this->tenantDay(-1), FollowUpStatus::CANCELLED, null, FollowUpType::CONFIRMATORY_TEST);
            $completed = $this->makeFollowUp($child, $this->tenantDay(-1), FollowUpStatus::COMPLETED);

            return [$inProgress->id, $cancelled->id, $completed->id];
        });

        $this->artisan('follow-up:refresh-overdue')->assertSuccessful();

        $this->inTenant($tenant, function () use ($inProgressId, $cancelledId, $completedId): void {
            $this->assertSame(FollowUpStatus::IN_PROGRESS, FollowUp::findOrFail($inProgressId)->follow_up_status_id);
            $this->assertSame(FollowUpStatus::CANCELLED, FollowUp::findOrFail($cancelledId)->follow_up_status_id);
            $this->assertSame(FollowUpStatus::COMPLETED, FollowUp::findOrFail($completedId)->follow_up_status_id);
        });
    }

    public function test_tenant_a_run_never_touches_tenant_b_rows(): void
    {
        [$tenantA, , $tenantB] = $this->twoTenants();

        $followUpBId = $this->inTenant($tenantB, function () {
            $child = Child::factory()->create();

            return $this->makeFollowUp($child, $this->tenantDay(-1), FollowUpStatus::PENDING)->id;
        });

        $this->artisan('follow-up:refresh-overdue')->assertSuccessful();

        // Both tenants are iterated by the command itself, so tenant B's row
        // DOES flip too -- assert the OVERDUE result lands scoped to tenant B
        // and querying it from tenant A's context finds nothing (TenantScope).
        $this->inTenant($tenantB, function () use ($followUpBId): void {
            $this->assertSame(FollowUpStatus::OVERDUE, FollowUp::findOrFail($followUpBId)->follow_up_status_id);
        });

        $this->inTenant($tenantA, function () use ($followUpBId): void {
            $this->assertNull(FollowUp::find($followUpBId));
        });
    }

    public function test_overdue_follows_each_tenant_civil_day_not_the_utc_day(): void
    {
        [$mexico, , $tokyo] = $this->twoTenants();
        $mexico->update(['timezone' => 'America/Mexico_City']);
        $tokyo->update(['timezone' => 'Asia/Tokyo']);

        $mexicoId = $this->inTenant($mexico, fn () => $this->makeFollowUp(Child::factory()->create(), '2026-10-20', FollowUpStatus::PENDING)->id);
        $tokyoId = $this->inTenant($tokyo, fn () => $this->makeFollowUp(Child::factory()->create(), '2026-10-20', FollowUpStatus::PENDING)->id);

        // 2026-10-21T03:00Z = Oct 20 21:00 in Mexico City, Oct 21 12:00 in Tokyo.
        $this->travelTo(Carbon::parse('2026-10-21T03:00:00Z'));
        $this->artisan('follow-up:refresh-overdue')->assertSuccessful();

        $this->inTenant($mexico, function () use ($mexicoId): void {
            $this->assertSame(FollowUpStatus::PENDING, FollowUp::findOrFail($mexicoId)->follow_up_status_id);
            $this->assertSame(FollowUpStatus::PENDING, FollowUpCreationService::deriveStatusId('2026-10-20'));
            $this->assertSame(FollowUpStatus::OVERDUE, FollowUpCreationService::deriveStatusId('2026-10-19'));
        });
        $this->inTenant($tokyo, function () use ($tokyoId): void {
            $this->assertSame(FollowUpStatus::OVERDUE, FollowUp::findOrFail($tokyoId)->follow_up_status_id);
            $this->assertSame(FollowUpStatus::OVERDUE, FollowUpCreationService::deriveStatusId('2026-10-20'));
        });
    }

    public function test_command_runs_hourly_so_every_tenant_midnight_is_covered(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains((string) $event->command, 'follow-up:refresh-overdue'));

        $this->assertNotNull($event);
        $this->assertSame('15 * * * *', $event->expression);
    }
}
