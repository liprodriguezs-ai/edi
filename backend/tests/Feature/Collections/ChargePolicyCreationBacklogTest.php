<?php

declare(strict_types=1);

namespace Tests\Feature\Collections;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Modules\Collections\Models\AmountMode;
use Modules\Collections\Models\Charge;
use Modules\Collections\Models\ChargeBatch;
use Modules\Collections\Models\ChargeConcept;
use Modules\Collections\Models\ChargeConceptCategory;
use Modules\Collections\Models\ChargePolicy;
use Modules\Collections\Models\Periodicity;
use Modules\Collections\Services\ChargePolicyService;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitRelationship;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Modules\Housing\Models\UnitUser;
use Modules\Notification\Models\NotificationUser;
use Modules\Tenant\Models\Tenant;
use Tests\Support\Catalog;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Creating a PERIODIC policy with a RETROACTIVE valid_from generates its WHOLE backlog
 * right there — not a single lonely period the admin then has to chase by running the
 * daily cron by hand (which walks every tenant and every policy, generating unrelated
 * periods as a side effect).
 *
 * The chain is the SAME one the cron uses (PeriodChainService), so these tests pin the
 * four properties that make it safe:
 *  - completeness + contiguity: every period from valid_from up to today, no gaps
 *  - bounded volume: a period ceiling AND a charge budget, with the remainder left to the
 *    cron, which continues the chain without a gap
 *  - ISOLATION: only the policy being created generates — never another policy, never
 *    another tenant
 *  - ONE consolidated notification per unit, never one per period
 */
final class ChargePolicyCreationBacklogTest extends TestCase
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

        Carbon::setTestNow();

        parent::tearDown();
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function makeUnit(Tenant $tenant): Unit
    {
        $unit = null;
        $tenant->run(function () use (&$unit): void {
            $unitType = UnitType::findOrFail(Catalog::id(UnitType::class, 'Casa'));
            $unitStatus = UnitStatus::findOrFail(Catalog::id(UnitStatus::class, 'Activa'));
            $unit = Unit::create([
                'unit_type_id' => $unitType->id,
                'number' => (string) mt_rand(1, 99999),
                'unit_status_id' => $unitStatus->id,
                'ownership_share' => 1.0,
            ]);
        });

        return $unit;
    }

    private function attachBillingOwner(Tenant $tenant, Unit $unit): User
    {
        $user = $this->makeUser($tenant);

        $tenant->run(function () use ($unit, $user): void {
            UnitUser::create([
                'unit_id' => $unit->id,
                'user_id' => $user->id,
                'unit_relationship_id' => UnitRelationship::OWNER,
                'is_billing_owner' => true,
                'is_legal_representative' => true,
            ]);
        });

        return $user;
    }

    /** Create a policy THROUGH THE SERVICE — the path POST /charge_policy takes. */
    private function createPolicy(Tenant $tenant, string $periodicityId, array $overrides = []): ChargePolicy
    {
        $policy = null;
        $tenant->run(function () use (&$policy, $periodicityId, $overrides): void {
            $category = ChargeConceptCategory::findOrFail(Catalog::id(ChargeConceptCategory::class, 'Recargo'));
            $concept = ChargeConcept::create([
                'code' => 'BACKLOG_'.strtoupper(substr(md5(uniqid('', true)), 0, 6)),
                'name' => 'Cuota de prueba',
                'charge_concept_category_id' => $category->id,
                'is_active' => true,
            ]);

            $policy = app(ChargePolicyService::class)->create(array_merge([
                'charge_concept_id' => $concept->id,
                'name' => 'Cuota de prueba',
                'amount_mode_id' => AmountMode::FIXED_REQUIRED,
                'fixed_amount' => 100_00,
                'periodicity_id' => $periodicityId,
                'is_active' => true,
                'grace_days' => 0,
                'target_type' => 'all',
            ], $overrides));
        });

        return $policy;
    }

    /**
     * The policy's ACTIVATED period batches (is_initial = false), chain order.
     *
     * @return Collection<int, ChargeBatch>
     */
    private function periodBatches(Tenant $tenant, ChargePolicy $policy)
    {
        $batches = null;
        $tenant->run(function () use (&$batches, $policy): void {
            $batches = ChargeBatch::where('charge_policy_id', $policy->id)
                ->where('is_initial', false)
                ->whereNull('deleted_at')
                ->orderBy('period_start')
                ->get();
        });

        return $batches;
    }

    /** Assert start(n+1) === end(n) + 1 day across the whole generated chain. */
    private function assertContiguous($batches): void
    {
        $previous = null;
        foreach ($batches as $batch) {
            if ($previous !== null) {
                $this->assertSame(
                    $previous->due_date->copy()->addDay()->toDateString(),
                    $batch->period_start->toDateString(),
                    'Periods must chain with no gap and no overlap',
                );
            }
            $previous = $batch;
        }
    }

    // =========================================================================
    // Completeness: a retroactive valid_from generates the whole backlog NOW
    // =========================================================================

    public function test_retroactive_valid_from_generates_every_missing_period_at_creation(): void
    {
        $tenant = $this->makeTenant('Backlog Monthly Tenant');
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);

        Carbon::setTestNow(Carbon::create(2026, 6, 20, 9, 0, 0));

        // Jan 10 .. Jun 20 → Jan, Feb, Mar, Apr, May, Jun = 6 anchored monthly periods.
        $policy = $this->createPolicy($tenant, Periodicity::MONTHLY, [
            'valid_from' => '2026-01-10',
            'valid_to' => null,
        ]);

        $batches = $this->periodBatches($tenant, $policy);

        $this->assertCount(6, $batches, 'Every period from valid_from up to today materializes at creation');
        $this->assertSame('2026-01-10', $batches->first()->period_start->toDateString(), 'The chain still starts exactly on valid_from');
        $this->assertSame('2026-06-10', $batches->last()->period_start->toDateString());
        $this->assertContiguous($batches);

        // Each period actually billed the unit — batches without charges would be a lie.
        $tenant->run(function () use ($batches, $unit): void {
            foreach ($batches as $batch) {
                $this->assertSame(
                    1,
                    Charge::where('charge_batch_id', $batch->id)->where('unit_id', $unit->id)->count(),
                );
            }
        });
    }

    public function test_retroactive_every_two_days_backlog_stops_at_today_not_beyond(): void
    {
        $tenant = $this->makeTenant('Backlog TwoDays Tenant');
        $this->seedTenant($tenant);
        $this->makeUnit($tenant);

        // The owner's literal scenario: a 2-day cadence starting on the 1st, created on the 9th.
        Carbon::setTestNow(Carbon::create(2026, 7, 9, 9, 0, 0));

        $policy = $this->createPolicy($tenant, Periodicity::EVERY_TWO_DAYS, [
            'valid_from' => '2026-07-01',
            'valid_to' => '2026-12-31',
        ]);

        $batches = $this->periodBatches($tenant, $policy);

        // [01,02] [03,04] [05,06] [07,08] [09,10] — the 9th has started, the 11th has not.
        $this->assertCount(5, $batches);
        $this->assertSame('2026-07-01', $batches->first()->period_start->toDateString());
        $this->assertSame('2026-07-09', $batches->last()->period_start->toDateString());
        $this->assertSame('2026-07-10', $batches->last()->due_date->toDateString());
        $this->assertContiguous($batches);
    }

    public function test_valid_from_today_still_generates_exactly_one_period(): void
    {
        $tenant = $this->makeTenant('Backlog Today Tenant');
        $this->seedTenant($tenant);
        $this->makeUnit($tenant);

        Carbon::setTestNow(Carbon::create(2026, 6, 20, 9, 0, 0));

        $policy = $this->createPolicy($tenant, Periodicity::MONTHLY, [
            'valid_from' => '2026-06-20',
            'valid_to' => null,
        ]);

        $this->assertCount(
            1,
            $this->periodBatches($tenant, $policy),
            'No backlog to catch up: behavior is unchanged',
        );
    }

    public function test_future_valid_from_still_creates_only_the_template(): void
    {
        $tenant = $this->makeTenant('Backlog Future Tenant');
        $this->seedTenant($tenant);
        $this->makeUnit($tenant);

        Carbon::setTestNow(Carbon::create(2026, 6, 20, 9, 0, 0));

        $policy = $this->createPolicy($tenant, Periodicity::MONTHLY, [
            'valid_from' => '2026-08-01',
            'valid_to' => null,
        ]);

        $this->assertCount(0, $this->periodBatches($tenant, $policy), 'A future valid_from must not bill anything yet');

        $tenant->run(function () use ($policy): void {
            $this->assertSame(
                1,
                ChargeBatch::where('charge_policy_id', $policy->id)->where('is_initial', true)->count(),
                'The template is still created',
            );
        });
    }

    public function test_eventual_policy_is_untouched_by_the_chain(): void
    {
        $tenant = $this->makeTenant('Backlog Eventual Tenant');
        $this->seedTenant($tenant);
        $this->makeUnit($tenant);

        Carbon::setTestNow(Carbon::create(2026, 6, 20, 9, 0, 0));

        $policy = $this->createPolicy($tenant, Periodicity::EVENTUAL, [
            'valid_from' => null,
            'issued_at' => '2026-01-05',
            'due_date' => '2026-01-31',
        ]);

        $batches = $this->periodBatches($tenant, $policy);

        // A one-shot charge dated back in January must stay ONE batch — an eventual policy
        // has no periods to catch up on.
        $this->assertCount(1, $batches);
        $this->assertSame('2026-01-31', $batches->first()->due_date->toDateString());

        $tenant->run(function () use ($policy): void {
            $this->assertSame(
                0,
                ChargeBatch::where('charge_policy_id', $policy->id)->where('is_initial', true)->count(),
                'Eventual policies never get a template',
            );
        });
    }

    // =========================================================================
    // Bounded volume — and the cron drains the rest without a gap
    // =========================================================================

    public function test_period_ceiling_caps_creation_and_the_cron_continues_the_chain(): void
    {
        config(['collections.catchup_max_periods' => 3]);

        $tenant = $this->makeTenant('Backlog Cap Tenant');
        $this->seedTenant($tenant);
        $this->makeUnit($tenant);

        Carbon::setTestNow(Carbon::create(2026, 6, 20, 9, 0, 0));

        // 6 periods are due (Jan..Jun); the ceiling allows 3 at creation.
        $policy = $this->createPolicy($tenant, Periodicity::MONTHLY, [
            'valid_from' => '2026-01-10',
            'valid_to' => null,
        ]);

        $atCreation = $this->periodBatches($tenant, $policy);
        $this->assertCount(3, $atCreation, 'Creation stops at the cap');
        $this->assertSame('2026-03-10', $atCreation->last()->period_start->toDateString());

        // The remainder is not lost: the cron seeds from MAX(due_date) + 1 and continues.
        $this->artisan('collections:generate-monthly')->assertSuccessful();

        $afterCron = $this->periodBatches($tenant, $policy);
        $this->assertCount(6, $afterCron, 'The cron drains the rest of the backlog');
        $this->assertContiguous($afterCron);
        $this->assertSame('2026-06-10', $afterCron->last()->period_start->toDateString());
    }

    public function test_charge_budget_shrinks_the_cap_for_a_large_cluster(): void
    {
        // 3 units and a budget of 5 charges → intdiv(5, 3) = 1 period, even though the
        // period ceiling (12) and the backlog (6 periods) would both allow more.
        config(['collections.creation_max_charges' => 5]);

        $tenant = $this->makeTenant('Backlog Budget Tenant');
        $this->seedTenant($tenant);
        $this->makeUnit($tenant);
        $this->makeUnit($tenant);
        $this->makeUnit($tenant);

        Carbon::setTestNow(Carbon::create(2026, 6, 20, 9, 0, 0));

        $policy = $this->createPolicy($tenant, Periodicity::MONTHLY, [
            'valid_from' => '2026-01-10',
            'valid_to' => null,
        ]);

        $batches = $this->periodBatches($tenant, $policy);

        $this->assertCount(1, $batches, 'periods × units is the real cost, so the budget bounds the depth');
        $this->assertSame('2026-01-10', $batches->first()->period_start->toDateString());
    }

    public function test_charge_budget_never_drops_below_the_first_period(): void
    {
        // An absurdly small budget must still leave the pre-existing guarantee intact:
        // a new active policy ALWAYS gets its first period.
        config(['collections.creation_max_charges' => 1]);

        $tenant = $this->makeTenant('Backlog Floor Tenant');
        $this->seedTenant($tenant);
        $this->makeUnit($tenant);
        $this->makeUnit($tenant);

        Carbon::setTestNow(Carbon::create(2026, 6, 20, 9, 0, 0));

        $policy = $this->createPolicy($tenant, Periodicity::MONTHLY, [
            'valid_from' => '2026-01-10',
            'valid_to' => null,
        ]);

        $this->assertCount(1, $this->periodBatches($tenant, $policy));
    }

    public function test_valid_to_stops_the_creation_chain(): void
    {
        $tenant = $this->makeTenant('Backlog ValidTo Tenant');
        $this->seedTenant($tenant);
        $this->makeUnit($tenant);

        Carbon::setTestNow(Carbon::create(2026, 6, 20, 9, 0, 0));

        $policy = $this->createPolicy($tenant, Periodicity::MONTHLY, [
            'valid_from' => '2026-01-10',
            'valid_to' => '2026-03-31',
        ]);

        $batches = $this->periodBatches($tenant, $policy);

        // Apr's generation date (2026-04-10) is past valid_to → the chain stops at March.
        $this->assertCount(3, $batches, 'The backlog never runs past valid_to');
        $this->assertSame('2026-03-10', $batches->last()->period_start->toDateString());
    }

    // =========================================================================
    // ISOLATION — the literal ask: only THIS policy generates
    // =========================================================================

    public function test_creating_a_policy_does_not_generate_periods_of_another_policy(): void
    {
        // A low ceiling leaves the neighbour policy with a KNOWN pending backlog: if
        // creation leaked into a global run (e.g. by invoking the cron), the neighbour
        // would advance. It must not move by a single period.
        config(['collections.catchup_max_periods' => 2]);

        $tenant = $this->makeTenant('Isolation Same Tenant');
        $this->seedTenant($tenant);
        $this->makeUnit($tenant);

        Carbon::setTestNow(Carbon::create(2026, 6, 20, 9, 0, 0));

        $neighbour = $this->createPolicy($tenant, Periodicity::MONTHLY, [
            'name' => 'Vecina',
            'valid_from' => '2026-01-10',
            'valid_to' => null,
        ]);

        $before = $this->periodBatches($tenant, $neighbour);
        $this->assertCount(2, $before, 'The neighbour is deliberately left with 4 periods pending');

        $created = $this->createPolicy($tenant, Periodicity::MONTHLY, [
            'name' => 'Nueva',
            'valid_from' => '2026-01-10',
            'valid_to' => null,
        ]);

        $this->assertCount(2, $this->periodBatches($tenant, $created), 'The new policy generates its own backlog');

        $after = $this->periodBatches($tenant, $neighbour);
        $this->assertCount(2, $after, 'Creating a policy must NEVER generate another policy\'s periods');
        $this->assertSame(
            $before->pluck('id')->all(),
            $after->pluck('id')->all(),
            'The neighbour\'s batches must be the very same rows',
        );
    }

    public function test_creating_a_policy_does_not_generate_periods_of_another_tenant(): void
    {
        config(['collections.catchup_max_periods' => 2]);

        $otherTenant = $this->makeTenant('Isolation Other Tenant');
        $this->seedTenant($otherTenant);
        $this->makeUnit($otherTenant);

        $tenant = $this->makeTenant('Isolation Main Tenant');
        $this->seedTenant($tenant);
        $this->makeUnit($tenant);

        Carbon::setTestNow(Carbon::create(2026, 6, 20, 9, 0, 0));

        $foreign = $this->createPolicy($otherTenant, Periodicity::MONTHLY, [
            'valid_from' => '2026-01-10',
            'valid_to' => null,
        ]);

        $before = $this->periodBatches($otherTenant, $foreign);
        $this->assertCount(2, $before);

        $this->createPolicy($tenant, Periodicity::MONTHLY, [
            'valid_from' => '2026-01-10',
            'valid_to' => null,
        ]);

        $after = $this->periodBatches($otherTenant, $foreign);
        $this->assertCount(2, $after, 'Creation is scoped to its own tenant — never a cross-tenant run');
        $this->assertSame($before->pluck('id')->all(), $after->pluck('id')->all());
    }

    // =========================================================================
    // ONE consolidated notification per unit, never one per period
    // =========================================================================

    public function test_multi_period_backlog_sends_exactly_one_notification_per_unit(): void
    {
        $tenant = $this->makeTenant('Backlog Notify Tenant');
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);
        $billingUser = $this->attachBillingOwner($tenant, $unit);

        Carbon::setTestNow(Carbon::create(2026, 6, 20, 9, 0, 0));

        $policy = $this->createPolicy($tenant, Periodicity::MONTHLY, [
            'valid_from' => '2026-01-10',
            'valid_to' => null,
        ]);

        $this->assertCount(6, $this->periodBatches($tenant, $policy));

        $tenant->run(function () use ($billingUser): void {
            $rows = NotificationUser::with('notification')->where('user_id', $billingUser->id)->get();

            $this->assertCount(
                1,
                $rows,
                'A 6-period backlog must send ONE notification per unit, not six',
            );

            $notification = $rows->first()->notification;

            // The plural template must render fully — the singular one declares a {due_date}
            // a multi-period run cannot fill, which would ship a literal token to residents.
            foreach (['title' => $notification->title, 'message' => $notification->message] as $field => $text) {
                $this->assertDoesNotMatchRegularExpression(
                    '/\{[a-z_]+\}/',
                    (string) $text,
                    "The rendered {$field} still contains an unsubstituted template token: {$text}",
                );
            }

            $this->assertStringContainsString('6', (string) $notification->message);
            $this->assertStringContainsString('cargos', (string) $notification->message);
        });
    }

    public function test_single_period_creation_keeps_the_singular_notification(): void
    {
        $tenant = $this->makeTenant('Backlog Singular Tenant');
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);
        $billingUser = $this->attachBillingOwner($tenant, $unit);

        Carbon::setTestNow(Carbon::create(2026, 6, 20, 9, 0, 0));

        $this->createPolicy($tenant, Periodicity::MONTHLY, [
            'valid_from' => '2026-06-20',
            'valid_to' => null,
        ]);

        $tenant->run(function () use ($billingUser): void {
            $rows = NotificationUser::with('notification')->where('user_id', $billingUser->id)->get();

            $this->assertCount(1, $rows);
            $this->assertStringStartsWith('Nuevo cargo:', (string) $rows->first()->notification->title);
        });
    }
}
