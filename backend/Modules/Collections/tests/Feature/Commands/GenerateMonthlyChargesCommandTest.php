<?php

declare(strict_types=1);

namespace Modules\Collections\Tests\Feature\Commands;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Collections\Models\AmountMode;
use Modules\Collections\Models\Charge;
use Modules\Collections\Models\ChargeBatch;
use Modules\Collections\Models\ChargeBatchStatus;
use Modules\Collections\Models\ChargeConcept;
use Modules\Collections\Models\ChargeConceptCategory;
use Modules\Collections\Models\ChargePolicy;
use Modules\Collections\Models\ChargeStatus;
use Modules\Collections\Models\Periodicity;
use Modules\Collections\Services\ChargePolicyService;
use Modules\Collections\Services\PeriodicityScheduleResolver;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitRelationship;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Modules\Housing\Models\UnitUser;
use Modules\Notification\Models\NotificationUser;
use Modules\Setting\Models\Setting;
use Modules\Tenant\Models\Tenant;
use Tests\Support\Catalog;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Feature tests for GenerateMonthlyChargesCommand under ANCHORED BILLING PERIODS.
 *
 * The engine chains each policy's next period from its LAST batch (seed =
 * MAX(due_date) + 1 day, or valid_from for a virgin policy) — it no longer resolves a
 * calendar-absolute grid per exact date. Covers every scenario from the design/spec:
 *  - Chain seeds from the last batch, no gap/overlap (incl. legacy pre-cutover data and
 *    the live EVERY_TWO_DAYS epoch grid).
 *  - Fires on OR after issuedAt (a missed cron day does not stall the chain).
 *  - Capped catch-up: partial generation + remainder on the next run.
 *  - `--from` lifts the cap for deep catch-up; a date earlier than the chain seed is inert.
 *  - Consolidated (one-per-unit) notification for a multi-period catch-up run.
 *  - period_start idempotency, including DRAFT-leftover recovery.
 *  - valid_to stops the chain; billed rows stay immutable; post-billing valid_from edits
 *    are inert.
 */
final class GenerateMonthlyChargesCommandTest extends TestCase
{
    use RefreshDatabase;
    use WithTenantSetup;

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

    private function setGenerationDay(Tenant $tenant, int $day): void
    {
        $tenant->run(function () use ($day): void {
            Setting::updateOrCreate(
                ['slug' => 'collections.charge_generation_day'],
                [
                    'name' => 'Día de generación',
                    'description' => 'Día del mes en que se generan los cargos',
                    'slug' => 'collections.charge_generation_day',
                    'value' => $day,
                    'type' => Setting::TYPE_INTEGER,
                ]
            );
        });
    }

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

    /**
     * Attach a billing owner to a unit so unitBillingUserId() resolves a recipient for the
     * "charge_created" notification.
     */
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

    /**
     * Create a PERIODIC (or DIRECT, for EVENTUAL) policy plus its INITIAL template batch —
     * exactly what ChargePolicyService::createInitialTemplate produces, bypassing the
     * service so tests control the chain's starting state precisely. `valid_from` must be
     * supplied explicitly for periodic policies: under anchored periods the first period
     * starts exactly there, so an accidental stale default would silently trigger a deep
     * catch-up burst instead of the single period a test expects.
     */
    private function makeConcept(Tenant $tenant, string $periodicityId, array $overrides = []): ChargePolicy
    {
        $policy = null;
        $tenant->run(function () use (&$policy, $periodicityId, $overrides): void {
            $category = ChargeConceptCategory::findOrFail(Catalog::id(ChargeConceptCategory::class, 'Recargo'));

            $concept = ChargeConcept::create([
                'code' => 'TEST_'.strtoupper(substr(md5(uniqid()), 0, 6)),
                'name' => 'Test concept',
                'charge_concept_category_id' => $category->id,
                'is_active' => true,
            ]);

            $isDirect = $periodicityId === Periodicity::EVENTUAL;

            $policy = ChargePolicy::create(array_merge([
                'charge_concept_id' => $concept->id,
                'name' => 'Test policy',
                'amount_mode_id' => AmountMode::FIXED_REQUIRED,
                'fixed_amount' => 100_00,
                'periodicity_id' => $periodicityId,
                'valid_from' => $isDirect ? null : '2026-01-01',
                'valid_to' => null,
                'is_active' => true,
                'grace_days' => 0,
            ], $overrides));

            if (! $isDirect) {
                $draftStatus = ChargeBatchStatus::findOrFail(ChargeBatchStatus::DRAFT);
                ChargeBatch::create([
                    'charge_policy_id' => $policy->id,
                    'title' => 'Plantilla inicial',
                    'year' => 2019,
                    'month' => null,
                    'cycle' => null,
                    'period_start' => null,
                    'target_type' => 'all',
                    'target_selector' => null,
                    'issued_at' => '2019-01-01',
                    'due_date' => '2019-01-31',
                    'charge_batch_status_id' => $draftStatus->id,
                    'progress_pct' => 0,
                    'is_initial' => true,
                ]);
            }
        });

        return $policy;
    }

    /**
     * Directly insert an already-generated, ACTIVATED period batch — simulates the state a
     * prior run (or, with period_start left NULL, a PRE-CUTOVER legacy batch) would have
     * left behind, so the chain's seed = MAX(due_date) + 1 can be exercised without
     * replaying every intermediate period.
     */
    private function makeExistingBatch(Tenant $tenant, ChargePolicy $policy, array $attributes): ChargeBatch
    {
        $batch = null;
        $tenant->run(function () use (&$batch, $policy, $attributes): void {
            $activeStatus = ChargeBatchStatus::findOrFail(ChargeBatchStatus::ACTIVE);
            $batch = ChargeBatch::create(array_merge([
                'charge_policy_id' => $policy->id,
                'title' => 'Período previo',
                'year' => 2026,
                'month' => null,
                'cycle' => null,
                'period_start' => null,
                'target_type' => 'all',
                'target_selector' => null,
                'issued_at' => '2026-01-01',
                'due_date' => '2026-01-01',
                'charge_batch_status_id' => $activeStatus->id,
                'progress_pct' => 0,
                'is_initial' => false,
            ], $attributes));
        });

        return $batch;
    }

    /**
     * Count only the cron's PERIOD batches (is_initial=false) — the policy's INITIAL
     * template batch is fixture setup, not a cron output, so it never counts here.
     */
    private function countBatchesForConcept(Tenant $tenant, ChargePolicy $policy): int
    {
        $count = 0;
        $tenant->run(function () use (&$count, $policy): void {
            $count = ChargeBatch::where('charge_policy_id', $policy->id)
                ->where('is_initial', false)
                ->whereNull('deleted_at')
                ->count();
        });

        return $count;
    }

    private function latestBatchForConcept(Tenant $tenant, ChargePolicy $policy): ?ChargeBatch
    {
        $batch = null;
        $tenant->run(function () use (&$batch, $policy): void {
            $batch = ChargeBatch::where('charge_policy_id', $policy->id)
                ->where('is_initial', false)
                ->whereNull('deleted_at')
                ->latest('due_date')
                ->first();
        });

        return $batch;
    }

    // =========================================================================
    // Chain seeds from the last batch — no gap/overlap
    // =========================================================================

    public function test_chain_seeds_from_last_batch_no_gap_or_overlap(): void
    {
        $tenant = $this->makeTenant('Chain Seed Tenant');
        $this->seedTenant($tenant);
        $this->setGenerationDay($tenant, 5);
        $this->makeUnit($tenant);
        $policy = $this->makeConcept($tenant, Periodicity::MONTHLY, ['valid_from' => '2026-01-01']);

        $this->makeExistingBatch($tenant, $policy, [
            'period_start' => '2026-05-01',
            'year' => 2026,
            'month' => 5,
            'issued_at' => '2026-05-05',
            'due_date' => '2026-05-31',
        ]);

        Carbon::setTestNow(Carbon::create(2026, 6, 5, 7, 0, 0));
        $this->artisan('collections:generate-monthly')->assertSuccessful();

        $this->assertEquals(2, $this->countBatchesForConcept($tenant, $policy), 'The May batch plus a new June batch');

        $newest = $this->latestBatchForConcept($tenant, $policy);
        $this->assertEquals('2026-06-01', $newest->period_start->toDateString(), 'No gap: starts the day after May 31');
        $this->assertEquals('2026-06-30', $newest->due_date->toDateString());
    }

    public function test_chain_seeds_from_legacy_batch_without_period_start(): void
    {
        $tenant = $this->makeTenant('Legacy Cutover Tenant');
        $this->seedTenant($tenant);
        $this->setGenerationDay($tenant, 5);
        $this->makeUnit($tenant);
        $policy = $this->makeConcept($tenant, Periodicity::MONTHLY, ['valid_from' => '2026-01-01']);

        // A pre-cutover batch: period_start is NULL (calendar-grid era), only year/month/cycle
        // + due_date are meaningful — exactly what a real legacy row looks like.
        $this->makeExistingBatch($tenant, $policy, [
            'period_start' => null,
            'year' => 2026,
            'month' => 5,
            'cycle' => null,
            'issued_at' => '2026-05-05',
            'due_date' => '2026-05-31',
        ]);

        Carbon::setTestNow(Carbon::create(2026, 6, 5, 7, 0, 0));
        $this->artisan('collections:generate-monthly')->assertSuccessful();

        $this->assertEquals(2, $this->countBatchesForConcept($tenant, $policy));

        $newest = $this->latestBatchForConcept($tenant, $policy);
        $this->assertNotNull($newest->period_start, 'Every NEW batch is anchored going forward');
        $this->assertEquals('2026-06-01', $newest->period_start->toDateString());
    }

    public function test_chain_continues_every_two_days_epoch_grid_after_legacy_cutover(): void
    {
        $tenant = $this->makeTenant('Legacy EveryTwoDays Tenant');
        $this->seedTenant($tenant);
        $this->setGenerationDay($tenant, 1);
        $this->makeUnit($tenant);
        $policy = $this->makeConcept($tenant, Periodicity::EVERY_TWO_DAYS, ['valid_from' => '2026-07-01']);

        // Derive a REAL epoch window from production code (not hand computed) so the legacy
        // fixture is guaranteed grid-aligned, exactly like a real pre-cutover row would be.
        $window = app(PeriodicityScheduleResolver::class)->periodFor(Periodicity::EVERY_TWO_DAYS, Carbon::create(2026, 7, 1), 1);

        $this->makeExistingBatch($tenant, $policy, [
            'period_start' => null,
            'year' => $window->year,
            'month' => $window->month,
            'cycle' => $window->cycle,
            'issued_at' => $window->issuedAt->toDateString(),
            'due_date' => $window->dueDate->toDateString(),
        ]);

        // Exactly one more window is due: today = the legacy window's end + 2 days.
        Carbon::setTestNow($window->dueDate->copy()->addDays(2));
        $this->artisan('collections:generate-monthly')->assertSuccessful();

        $this->assertEquals(2, $this->countBatchesForConcept($tenant, $policy));

        $newest = $this->latestBatchForConcept($tenant, $policy);
        $this->assertEquals(
            $window->dueDate->copy()->addDay()->toDateString(),
            $newest->period_start->toDateString(),
            'Chain-seed identity: the new window starts exactly where the legacy one ended',
        );
    }

    // =========================================================================
    // Fires on OR after issuedAt — a missed cron day does not stall the chain
    // =========================================================================

    public function test_generation_fires_after_a_missed_cron_day_not_only_on_exact_match(): void
    {
        $tenant = $this->makeTenant('Missed Cron Tenant');
        $this->seedTenant($tenant);
        $this->setGenerationDay($tenant, 5);
        $this->makeUnit($tenant);
        // First period starts exactly at valid_from → issuedAt = 2026-06-05.
        $policy = $this->makeConcept($tenant, Periodicity::MONTHLY, ['valid_from' => '2026-06-01']);

        // The cron did not run on the 5th; it runs 5 days late.
        Carbon::setTestNow(Carbon::create(2026, 6, 10, 7, 0, 0));
        $this->artisan('collections:generate-monthly')->assertSuccessful();

        $this->assertEquals(1, $this->countBatchesForConcept($tenant, $policy), 'A missed exact day must not stall the chain');
        $this->assertEquals('2026-06-01', $this->latestBatchForConcept($tenant, $policy)->period_start->toDateString());
    }

    public function test_generation_does_not_fire_before_issued_at(): void
    {
        $tenant = $this->makeTenant('Too Early Tenant');
        $this->seedTenant($tenant);
        $this->setGenerationDay($tenant, 20);
        $this->makeUnit($tenant);
        $policy = $this->makeConcept($tenant, Periodicity::MONTHLY, ['valid_from' => '2026-06-01']);

        // issuedAt = 2026-06-20; today is still before it.
        Carbon::setTestNow(Carbon::create(2026, 6, 10, 7, 0, 0));
        $this->artisan('collections:generate-monthly')->assertSuccessful();

        $this->assertEquals(0, $this->countBatchesForConcept($tenant, $policy));
    }

    // =========================================================================
    // Capped catch-up (spec scenario verbatim: 5 periods behind, cap 3 → 3 this run)
    // =========================================================================

    public function test_cap_generates_partial_backlog_then_remainder_next_run(): void
    {
        config(['collections.catchup_max_periods' => 3]);

        $tenant = $this->makeTenant('Cap Tenant');
        $this->seedTenant($tenant);
        $this->setGenerationDay($tenant, 5);
        $this->makeUnit($tenant);
        $policy = $this->makeConcept($tenant, Periodicity::MONTHLY, ['valid_from' => '2026-01-01']);

        // 5 periods are due: Jan..May (May's issuedAt = 2026-05-05 <= today).
        Carbon::setTestNow(Carbon::create(2026, 5, 20, 7, 0, 0));

        $this->artisan('collections:generate-monthly')->assertSuccessful();
        $this->assertEquals(3, $this->countBatchesForConcept($tenant, $policy), 'Exactly 3 of 5 pending periods generate this run');
        $this->assertEquals(3, $this->latestBatchForConcept($tenant, $policy)->month, 'Chain order preserved: Jan, Feb, Mar');

        $this->artisan('collections:generate-monthly')->assertSuccessful();
        $this->assertEquals(5, $this->countBatchesForConcept($tenant, $policy), 'The remaining 2 periods generate on the next run');
        $this->assertEquals(5, $this->latestBatchForConcept($tenant, $policy)->month);
    }

    // =========================================================================
    // --from lifts the cap for deep catch-up
    // =========================================================================

    public function test_from_lifts_the_cap_and_drains_full_backlog_in_one_run(): void
    {
        config(['collections.catchup_max_periods' => 3]);

        $tenant = $this->makeTenant('From Lifts Cap Tenant');
        $this->seedTenant($tenant);
        $this->setGenerationDay($tenant, 5);
        $this->makeUnit($tenant);
        $policy = $this->makeConcept($tenant, Periodicity::MONTHLY, ['valid_from' => '2026-01-01']);

        Carbon::setTestNow(Carbon::create(2026, 5, 20, 7, 0, 0));

        $this->artisan('collections:generate-monthly', ['--from' => '2026-01-01'])->assertSuccessful();

        $this->assertEquals(5, $this->countBatchesForConcept($tenant, $policy), '--from drains the full 5-period backlog in one run despite the cap of 3');
    }

    public function test_from_earlier_than_the_chain_seed_is_a_noop_on_existing_batches(): void
    {
        $tenant = $this->makeTenant('From Noop Tenant');
        $this->seedTenant($tenant);
        $this->setGenerationDay($tenant, 5);
        $this->makeUnit($tenant);
        $policy = $this->makeConcept($tenant, Periodicity::MONTHLY, ['valid_from' => '2026-01-01']);

        $this->makeExistingBatch($tenant, $policy, [
            'period_start' => '2026-05-01',
            'year' => 2026,
            'month' => 5,
            'issued_at' => '2026-05-05',
            'due_date' => '2026-05-31',
        ]);

        $before = $this->latestBatchForConcept($tenant, $policy)->getAttributes();

        // A date earlier than the chain seed (but still within the 400-day validation
        // window) — the chain still only appends after MAX(due_date), so nothing about the
        // existing batch changes.
        Carbon::setTestNow(Carbon::create(2026, 5, 31, 7, 0, 0));
        $this->artisan('collections:generate-monthly', ['--from' => '2025-06-01'])->assertSuccessful();

        $this->assertEquals(1, $this->countBatchesForConcept($tenant, $policy), 'No new batch: June is not due yet on 2026-05-31');

        $unchanged = $this->latestBatchForConcept($tenant, $policy)->getAttributes();
        $this->assertSame($before, $unchanged, 'The existing batch must be byte-identical — --from never regenerates/re-dates/reslices');
    }

    // =========================================================================
    // Consolidated catch-up notification — one per unit, covering the whole run
    // =========================================================================

    public function test_consolidated_notification_fires_once_per_unit_for_a_multiperiod_catchup(): void
    {
        $tenant = $this->makeTenant('Consolidated Notify Tenant');
        $this->seedTenant($tenant);
        $this->setGenerationDay($tenant, 5);
        $unit = $this->makeUnit($tenant);
        $billingUser = $this->attachBillingOwner($tenant, $unit);
        $policy = $this->makeConcept($tenant, Periodicity::MONTHLY, ['valid_from' => '2026-01-01']);

        // 3 periods are due in one run: Jan, Feb, Mar.
        Carbon::setTestNow(Carbon::create(2026, 3, 20, 7, 0, 0));
        $this->artisan('collections:generate-monthly')->assertSuccessful();

        $this->assertEquals(3, $this->countBatchesForConcept($tenant, $policy));

        $tenant->run(function () use ($billingUser, $policy): void {
            // Derived from the real generated chain, never hand-computed: the assert is
            // about the notification carrying the run's due-date SPAN, not about the
            // resolver's calendar arithmetic (covered by its own tests).
            $dueDates = ChargeBatch::where('charge_policy_id', $policy->id)
                ->where('is_initial', false)
                ->orderBy('due_date')
                ->pluck('due_date');
            $firstDue = $dueDates->first()->format('d/m/Y');
            $lastDue = $dueDates->last()->format('d/m/Y');

            $rows = NotificationUser::with('notification')->where('user_id', $billingUser->id)->get();

            $this->assertCount(
                1,
                $rows,
                'A 3-period catch-up must send exactly ONE notification per affected unit, not one per period',
            );

            $notification = $rows->first()->notification;

            // CONTENT, not just cardinality. The consolidated path used to reuse the
            // SINGULAR notification_template.charge_created, whose body declares a
            // {due_date} that a multi-period run cannot fill — TemplateRenderer leaves
            // unknown tokens intact on purpose, so a literal "{due_date}" shipped to
            // residents via in-app and push. A count-only assert never saw it.
            foreach (['title' => $notification->title, 'message' => $notification->message] as $field => $text) {
                $this->assertDoesNotMatchRegularExpression(
                    '/\{[a-z_]+\}/',
                    (string) $text,
                    "The rendered {$field} still contains an unsubstituted template token: {$text}",
                );
            }

            // The single notification must actually describe the WHOLE run (3 periods),
            // not read as one charge — that is the spec requirement the cardinality
            // assert cannot express.
            $this->assertStringContainsString('3', (string) $notification->message);
            $this->assertStringContainsString('Test concept', (string) $notification->title);
            $this->assertStringContainsString('cargos', (string) $notification->message);

            // The due dates of the first and last generated period both appear, so the
            // resident can tell which periods the catch-up covers.
            $this->assertStringContainsString($firstDue, (string) $notification->message);
            $this->assertStringContainsString($lastDue, (string) $notification->message);
            $this->assertNotSame($firstDue, $lastDue, 'A 3-period catch-up must span more than one due date');

            $this->assertSame('/statement', $notification->click_action);
        });
    }

    public function test_single_period_run_renders_the_singular_template_untouched(): void
    {
        $tenant = $this->makeTenant('Singular Copy Tenant');
        $this->seedTenant($tenant);
        $this->setGenerationDay($tenant, 5);
        $unit = $this->makeUnit($tenant);
        $billingUser = $this->attachBillingOwner($tenant, $unit);
        $policy = $this->makeConcept($tenant, Periodicity::MONTHLY, ['valid_from' => '2026-06-01']);

        Carbon::setTestNow(Carbon::create(2026, 6, 5, 7, 0, 0));
        $this->artisan('collections:generate-monthly')->assertSuccessful();

        $tenant->run(function () use ($billingUser, $policy): void {
            $dueDate = ChargeBatch::where('charge_policy_id', $policy->id)
                ->where('is_initial', false)
                ->firstOrFail()
                ->due_date
                ->format('d/m/Y');

            $notification = NotificationUser::with('notification')
                ->where('user_id', $billingUser->id)
                ->firstOrFail()
                ->notification;

            // The single-period path keeps notification_template.charge_created and its
            // {due_date}: adding the plural variant must not bleed into it. Its
            // {concept_name} is the BATCH title (concept + period label), unlike the
            // consolidated path, which spans periods and so uses the bare concept name.
            $this->assertStringStartsWith('Nuevo cargo: Test concept', (string) $notification->title);
            $this->assertDoesNotMatchRegularExpression('/\{[a-z_]+\}/', (string) $notification->title);
            $this->assertStringContainsString("Vence el {$dueDate}", (string) $notification->message);
            $this->assertDoesNotMatchRegularExpression('/\{[a-z_]+\}/', (string) $notification->message);
        });
    }

    public function test_single_period_run_keeps_the_per_batch_notification(): void
    {
        $tenant = $this->makeTenant('Single Notify Tenant');
        $this->seedTenant($tenant);
        $this->setGenerationDay($tenant, 5);
        $unit = $this->makeUnit($tenant);
        $billingUser = $this->attachBillingOwner($tenant, $unit);
        $policy = $this->makeConcept($tenant, Periodicity::MONTHLY, ['valid_from' => '2026-06-01']);

        Carbon::setTestNow(Carbon::create(2026, 6, 5, 7, 0, 0));
        $this->artisan('collections:generate-monthly')->assertSuccessful();

        $this->assertEquals(1, $this->countBatchesForConcept($tenant, $policy));

        $tenant->run(function () use ($billingUser): void {
            $this->assertSame(1, NotificationUser::where('user_id', $billingUser->id)->count());
        });
    }

    // =========================================================================
    // period_start idempotency
    // =========================================================================

    public function test_period_start_idempotency_rerun_does_not_duplicate(): void
    {
        $tenant = $this->makeTenant('Idempotency Tenant');
        $this->seedTenant($tenant);
        $this->setGenerationDay($tenant, 5);
        $this->makeUnit($tenant);
        $policy = $this->makeConcept($tenant, Periodicity::MONTHLY, ['valid_from' => '2026-06-01']);

        Carbon::setTestNow(Carbon::create(2026, 6, 5, 7, 0, 0));

        $this->artisan('collections:generate-monthly')->assertSuccessful();
        $this->assertEquals(1, $this->countBatchesForConcept($tenant, $policy));

        $this->artisan('collections:generate-monthly')->assertSuccessful();
        $this->assertEquals(1, $this->countBatchesForConcept($tenant, $policy), 'Re-running the same day must not duplicate the period');

        $batch = $this->latestBatchForConcept($tenant, $policy);
        $this->assertEquals(ChargeBatchStatus::ACTIVE, $batch->charge_batch_status_id);
    }

    public function test_draft_leftover_is_recovered_by_period_start_not_duplicated(): void
    {
        $tenant = $this->makeTenant('Draft Leftover Tenant');
        $this->seedTenant($tenant);
        $this->setGenerationDay($tenant, 5);
        $this->makeUnit($tenant);
        $policy = $this->makeConcept($tenant, Periodicity::MONTHLY, ['valid_from' => '2026-06-01']);

        // Simulate a crashed prior run: the batch was created (unique index claimed) but
        // activate() never ran, leaving a DRAFT row with no charges.
        $tenant->run(function () use ($policy): void {
            $draftStatus = ChargeBatchStatus::findOrFail(ChargeBatchStatus::DRAFT);
            ChargeBatch::create([
                'charge_policy_id' => $policy->id,
                'title' => 'Leftover draft',
                'year' => 2026,
                'month' => 6,
                'cycle' => null,
                'period_start' => '2026-06-01',
                'target_type' => 'all',
                'target_selector' => null,
                'issued_at' => '2026-06-05',
                'due_date' => '2026-06-30',
                'charge_batch_status_id' => $draftStatus->id,
                'progress_pct' => 0,
                'is_initial' => false,
            ]);
        });

        Carbon::setTestNow(Carbon::create(2026, 6, 5, 7, 0, 0));
        $this->artisan('collections:generate-monthly')->assertSuccessful();

        $this->assertEquals(1, $this->countBatchesForConcept($tenant, $policy), 'The leftover DRAFT is reused, not duplicated');

        $batch = $this->latestBatchForConcept($tenant, $policy);
        $this->assertEquals(ChargeBatchStatus::ACTIVE, $batch->charge_batch_status_id, 'The leftover draft must be activated');
    }

    // =========================================================================
    // valid_to stops the chain
    // =========================================================================

    public function test_valid_to_stops_the_chain(): void
    {
        $tenant = $this->makeTenant('ValidTo Tenant');
        $this->seedTenant($tenant);
        $this->setGenerationDay($tenant, 5);
        $this->makeUnit($tenant);
        $policy = $this->makeConcept($tenant, Periodicity::MONTHLY, [
            'valid_from' => '2026-01-01',
            'valid_to' => '2026-04-30',
        ]);

        // Far past valid_to — without the stop, the chain would keep generating past April.
        Carbon::setTestNow(Carbon::create(2026, 8, 1, 7, 0, 0));
        $this->artisan('collections:generate-monthly')->assertSuccessful();

        $this->assertEquals(4, $this->countBatchesForConcept($tenant, $policy), 'Only Jan..Apr generate; valid_to stops the chain');
        $this->assertEquals(4, $this->latestBatchForConcept($tenant, $policy)->month);
    }

    // =========================================================================
    // Billed records are immutable
    // =========================================================================

    public function test_paid_charge_rows_remain_byte_identical_after_generation_runs(): void
    {
        $tenant = $this->makeTenant('Immutability Tenant');
        $this->seedTenant($tenant);
        $this->setGenerationDay($tenant, 5);
        $unit = $this->makeUnit($tenant);
        $policy = $this->makeConcept($tenant, Periodicity::MONTHLY, ['valid_from' => '2026-01-01']);

        $paidChargeId = null;
        $before = null;

        $this->makeExistingBatch($tenant, $policy, [
            'period_start' => '2026-05-01',
            'year' => 2026,
            'month' => 5,
            'issued_at' => '2026-05-05',
            'due_date' => '2026-05-31',
        ]);

        $tenant->run(function () use (&$paidChargeId, &$before, $policy, $unit): void {
            $batch = ChargeBatch::where('charge_policy_id', $policy->id)->where('month', 5)->firstOrFail();
            $completed = ChargeStatus::findOrFail(ChargeStatus::COMPLETED);

            $charge = Charge::create([
                'charge_batch_id' => $batch->id,
                'charge_concept_id' => $policy->charge_concept_id,
                'charge_policy_id' => $policy->id,
                'unit_id' => $unit->id,
                'expected_amount' => 100_00,
                'paid_amount' => 100_00,
                'issued_at' => $batch->issued_at,
                'due_date' => $batch->due_date,
                'charge_status_id' => $completed->id,
                'is_cancelled' => false,
            ]);

            $paidChargeId = $charge->id;
            $before = $charge->fresh()->getAttributes();
        });

        // Run generation for a LATER period — must never touch the paid charge above.
        Carbon::setTestNow(Carbon::create(2026, 6, 5, 7, 0, 0));
        $this->artisan('collections:generate-monthly')->assertSuccessful();

        $tenant->run(function () use ($paidChargeId, $before): void {
            $after = Charge::findOrFail($paidChargeId)->getAttributes();
            $this->assertSame($before, $after, 'A paid charge must be byte-identical after any generation run');
        });
    }

    public function test_editing_valid_from_after_billing_does_not_reslice_next_period(): void
    {
        $tenant = $this->makeTenant('Post-Billing Edit Tenant');
        $this->seedTenant($tenant);
        $this->setGenerationDay($tenant, 5);
        $this->makeUnit($tenant);
        $policy = $this->makeConcept($tenant, Periodicity::MONTHLY, ['valid_from' => '2026-01-01']);

        $this->makeExistingBatch($tenant, $policy, [
            'period_start' => '2026-05-01',
            'year' => 2026,
            'month' => 5,
            'issued_at' => '2026-05-05',
            'due_date' => '2026-05-31',
        ]);

        // Admin edits valid_from after billing has already happened.
        $tenant->run(function () use ($policy): void {
            $policy->valid_from = '2026-07-15';
            $policy->save();
        });

        Carbon::setTestNow(Carbon::create(2026, 6, 5, 7, 0, 0));
        $this->artisan('collections:generate-monthly')->assertSuccessful();

        $newest = $this->latestBatchForConcept($tenant, $policy);
        $this->assertEquals(
            '2026-06-01',
            $newest->period_start->toDateString(),
            'The next period must still chain from the last batch (May 31 + 1), ignoring the edited valid_from',
        );
    }

    // =========================================================================
    // Template cloning + missing-template guard (unaffected by anchoring)
    // =========================================================================

    public function test_cron_clones_initial_batch_target_instead_of_all(): void
    {
        $tenant = $this->makeTenant('Clone Target Tenant');
        $this->seedTenant($tenant);
        $this->setGenerationDay($tenant, 5);

        $targetUnit = $this->makeUnit($tenant);
        $otherUnit = $this->makeUnit($tenant);

        $policy = $this->makeConcept($tenant, Periodicity::MONTHLY, ['valid_from' => '2026-06-01']);

        $tenant->run(function () use ($policy, $targetUnit): void {
            ChargeBatch::where('charge_policy_id', $policy->id)
                ->where('is_initial', true)
                ->update([
                    'target_type' => 'single_unit',
                    'target_selector' => json_encode(['unit_id' => $targetUnit->id]),
                ]);
        });

        Carbon::setTestNow(Carbon::create(2026, 6, 5, 7, 0, 0));
        $this->artisan('collections:generate-monthly')->assertSuccessful();

        $clone = $this->latestBatchForConcept($tenant, $policy);
        $this->assertNotNull($clone);
        $this->assertFalse($clone->is_initial);
        $this->assertSame('single_unit', $clone->target_type);
        $this->assertSame(['unit_id' => $targetUnit->id], $clone->target_selector);

        $tenant->run(function () use ($clone, $targetUnit, $otherUnit): void {
            $this->assertSame(1, Charge::where('charge_batch_id', $clone->id)->where('unit_id', $targetUnit->id)->count());
            $this->assertSame(0, Charge::where('charge_batch_id', $clone->id)->where('unit_id', $otherUnit->id)->count());
        });
    }

    public function test_only_one_initial_batch_per_policy(): void
    {
        $tenant = $this->makeTenant('One Initial Tenant');
        $this->seedTenant($tenant);
        $this->makeUnit($tenant);

        $policy = $this->makeConcept($tenant, Periodicity::MONTHLY, ['valid_from' => '2026-01-01']);

        $tenant->run(function () use ($policy): void {
            $this->assertSame(
                1,
                ChargeBatch::where('charge_policy_id', $policy->id)->where('is_initial', true)->count(),
            );

            $draftStatus = ChargeBatchStatus::findOrFail(ChargeBatchStatus::DRAFT);
            $this->expectException(UniqueConstraintViolationException::class);
            ChargeBatch::create([
                'charge_policy_id' => $policy->id,
                'title' => 'Segundo inicial',
                'year' => 2018,
                'month' => 1,
                'target_type' => 'all',
                'target_selector' => null,
                'issued_at' => '2018-01-01',
                'due_date' => '2018-01-31',
                'charge_batch_status_id' => $draftStatus->id,
                'progress_pct' => 0,
                'is_initial' => true,
            ]);
        });
    }

    public function test_cron_skips_policy_with_no_initial_batch(): void
    {
        $tenant = $this->makeTenant('No Template Tenant');
        $this->seedTenant($tenant);
        $this->setGenerationDay($tenant, 5);
        $this->makeUnit($tenant);

        $policy = $this->makeConcept($tenant, Periodicity::MONTHLY, ['valid_from' => '2026-06-01']);

        $tenant->run(function () use ($policy): void {
            ChargeBatch::where('charge_policy_id', $policy->id)
                ->where('is_initial', true)
                ->delete();
        });

        Carbon::setTestNow(Carbon::create(2026, 6, 5, 7, 0, 0));
        $this->artisan('collections:generate-monthly')->assertSuccessful();

        $this->assertEquals(0, $this->countBatchesForConcept($tenant, $policy));
    }

    // =========================================================================
    // ChargePolicyService::create → cron continuation (end-to-end, unaffected shape)
    // =========================================================================

    public function test_periodic_service_creates_first_period_then_cron_adds_second_without_duplicating(): void
    {
        $tenant = $this->makeTenant('First+Second Period Tenant');
        $this->seedTenant($tenant);
        $this->setGenerationDay($tenant, 5);
        $unit = $this->makeUnit($tenant);

        Carbon::setTestNow(Carbon::create(2026, 1, 5, 7, 0, 0));

        $policy = null;
        $tenant->run(function () use (&$policy): void {
            $category = ChargeConceptCategory::findOrFail(Catalog::id(ChargeConceptCategory::class, 'Recargo'));
            $concept = ChargeConcept::create([
                'code' => 'SVC_'.strtoupper(substr(md5(uniqid()), 0, 6)),
                'name' => 'Cuota mensual',
                'charge_concept_category_id' => $category->id,
                'is_active' => true,
            ]);

            $policy = app(ChargePolicyService::class)->create([
                'charge_concept_id' => $concept->id,
                'name' => 'Cuota mensual',
                'amount_mode_id' => AmountMode::FIXED_REQUIRED,
                'fixed_amount' => 100_00,
                'periodicity_id' => Periodicity::MONTHLY,
                'valid_from' => '2026-01-01',
                'valid_to' => '2026-12-31',
                'is_active' => true,
                'grace_days' => 0,
                'target_type' => 'all',
            ]);
        });

        $this->assertEquals(1, $this->countBatchesForConcept($tenant, $policy), 'First period must already exist after creation');

        $this->artisan('collections:generate-monthly')->assertSuccessful();
        $this->assertEquals(1, $this->countBatchesForConcept($tenant, $policy), 'Cron must not duplicate the existing first period on the same day');

        Carbon::setTestNow(Carbon::create(2026, 2, 5, 7, 0, 0));
        $this->artisan('collections:generate-monthly')->assertSuccessful();
        $this->assertEquals(2, $this->countBatchesForConcept($tenant, $policy), 'Cron must add the second period as a new clone');

        $tenant->run(function () use ($policy, $unit): void {
            $template = ChargeBatch::where('charge_policy_id', $policy->id)->where('is_initial', true)->firstOrFail();
            $this->assertNull($template->month);
            $this->assertSame(0, Charge::where('charge_batch_id', $template->id)->count());

            $clones = ChargeBatch::where('charge_policy_id', $policy->id)
                ->where('is_initial', false)
                ->orderBy('month')
                ->get();
            $this->assertSame([1, 2], $clones->pluck('month')->all());
            foreach ($clones as $clone) {
                $this->assertSame(ChargeBatchStatus::ACTIVE, $clone->charge_batch_status_id);
                $this->assertSame(1, Charge::where('charge_batch_id', $clone->id)->where('unit_id', $unit->id)->count());
            }
        });
    }

    // =========================================================================
    // --from validation (UNCHANGED — retained verbatim per design decision 10a)
    // =========================================================================

    public function test_from_rejects_a_malformed_date(): void
    {
        $this->artisan('collections:generate-monthly', ['--from' => '2026-02-30'])->assertFailed();
        $this->artisan('collections:generate-monthly', ['--from' => 'ayer'])->assertFailed();
    }

    public function test_from_rejects_a_future_date(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 7, 9, 7, 0, 0));

        $this->artisan('collections:generate-monthly', ['--from' => '2026-07-10'])->assertFailed();
    }

    public function test_from_rejects_a_range_beyond_the_defensive_cap(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 7, 9, 7, 0, 0));

        $this->artisan('collections:generate-monthly', ['--from' => '2020-01-01'])->assertFailed();
    }
}
