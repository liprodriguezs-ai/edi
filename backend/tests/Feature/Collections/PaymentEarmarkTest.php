<?php

declare(strict_types=1);

namespace Tests\Feature\Collections;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Modules\Collections\Models\AmountMode;
use Modules\Collections\Models\Charge;
use Modules\Collections\Models\ChargeConcept;
use Modules\Collections\Models\ChargeConceptCategory;
use Modules\Collections\Models\ChargeStatus;
use Modules\Collections\Models\CreditBalance;
use Modules\Collections\Models\Payment;
use Modules\Collections\Models\PaymentApplication;
use Modules\Collections\Models\PaymentMethod;
use Modules\Collections\Models\PaymentStatus;
use Modules\Collections\Services\ChargeStatusService;
use Modules\Collections\Services\PaymentService;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitRelationship;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Modules\Housing\Models\UnitUser;
use Modules\Projects\Models\CommunityProject;
use Modules\Projects\Models\CommunityProjectCategory;
use Modules\Projects\Models\CommunityProjectStatus;
use Modules\Projects\Models\ParticipationType;
use Modules\Projects\Models\ProjectContribution;
use Modules\Projects\Models\ProjectContributionStatus;
use Modules\Projects\Services\ContributionService;
use Modules\Projects\Services\ProjectActivationService;
use Modules\Tenant\Models\Tenant;
use Modules\Treasury\Models\BankAccount;
use Modules\Treasury\Models\BankAccountPurpose;
use Modules\Treasury\Models\BankAccountType;
use Tests\Support\Catalog;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Earmarked payments (pagos con destino etiquetado) — project contributions.
 *
 * An earmarked payment carries earmark_type/earmark_id: on confirmation the
 * registered handler creates the destination charge and PINS the full amount to
 * it (never FIFO toward unrelated debt). Covers the resident report flow
 * (submit → validate), the admin direct flow (register + earmark — the 1:1
 * replacement of the retired ContributionService::contribute($paymentData)),
 * capture-time validations, reject/cancel reversals, and the FIFO regression
 * for normal (non-earmarked) payments.
 */
final class PaymentEarmarkTest extends TestCase
{
    use RefreshDatabase;
    use WithTenantSetup;

    private const EARMARK_TYPE = 'project_contribution';

    /** @var array<string> */
    protected $connectionsToTransact = [];

    /** @var array<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    // =========================================================================
    // Resident report flow: submit → pending, NO charge yet
    // =========================================================================

    public function test_submit_earmarked_creates_pending_payment_without_charge(): void
    {
        $scn = $this->earmarkScenario();

        $response = $this->tenantRequest($scn['residentToken'], $scn['tenant'])
            ->postJson('/api/payment/submit', [
                'unit_id' => $scn['unit']->id,
                'paid_at' => '2026-07-01',
                'total_amount' => 30000,
                'payment_method_id' => PaymentMethod::CASH,
                'earmark_type' => self::EARMARK_TYPE,
                'earmark_id' => $scn['contributionId'],
            ]);

        $response->assertStatus(201);

        $scn['tenant']->run(function () use ($scn, $response): void {
            $payment = Payment::findOrFail($response->json('data.id'));
            $this->assertSame(PaymentStatus::PENDING_VALIDATION, $payment->payment_status_id);
            $this->assertSame(self::EARMARK_TYPE, $payment->earmark_type);
            $this->assertSame($scn['contributionId'], $payment->earmark_id);
            $this->assertNull($payment->receipt_number);

            // The charge is only born on validation; the contribution stays intact.
            $this->assertSame(0, Charge::where('community_project_id', $scn['project']->id)->count());
            $contribution = ProjectContribution::findOrFail($scn['contributionId']);
            $this->assertSame(0, (int) $contribution->getAttributes()['contributed_amount']);

            // No bank movement while pending.
            $this->assertSame(0, (int) BankAccount::findOrFail($scn['account']->id)->getAttributes()['current_balance']);
        });
    }

    // =========================================================================
    // Capture-time validations (422)
    // =========================================================================

    public function test_submit_rejects_unknown_earmark_type(): void
    {
        $scn = $this->earmarkScenario();

        $this->tenantRequest($scn['residentToken'], $scn['tenant'])
            ->postJson('/api/payment/submit', [
                'unit_id' => $scn['unit']->id,
                'paid_at' => '2026-07-01',
                'total_amount' => 30000,
                'payment_method_id' => PaymentMethod::CASH,
                'earmark_type' => 'unknown_target',
                'earmark_id' => $scn['contributionId'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('earmark_type');
    }

    public function test_submit_rejects_unit_mismatch_and_mandatory_project(): void
    {
        $scn = $this->earmarkScenario();

        // A second unit of the SAME resident: membership passes, earmark target does not.
        $otherUnit = $this->makeUnit($scn['tenant']);
        $this->attachToUnit($scn['tenant'], $otherUnit, $scn['resident'], billingOwner: false);

        $this->tenantRequest($scn['residentToken'], $scn['tenant'])
            ->postJson('/api/payment/submit', [
                'unit_id' => $otherUnit->id,
                'paid_at' => '2026-07-01',
                'total_amount' => 30000,
                'payment_method_id' => PaymentMethod::CASH,
                'earmark_type' => self::EARMARK_TYPE,
                'earmark_id' => $scn['contributionId'],
            ])
            ->assertStatus(422);

        // Mandatory project: its money only travels through its own charge (FIFO).
        $mandatory = $this->makeProject($scn['tenant'], [
            'participation_type_id' => ParticipationType::MANDATORY,
            'amount_mode_id' => AmountMode::FIXED_REQUIRED,
            'fixed_amount' => 50000,
            'min_amount' => null,
            'ideal_amount' => null,
            'target_type' => 'single_unit',
            'target_selector' => ['unit_id' => $scn['unit']->id],
        ]);
        $mandatoryContributionId = $this->contributionId($scn['tenant'], $mandatory, $scn['unit']);

        $response = $this->tenantRequest($scn['residentToken'], $scn['tenant'])
            ->postJson('/api/payment/submit', [
                'unit_id' => $scn['unit']->id,
                'paid_at' => '2026-07-01',
                'total_amount' => 50000,
                'payment_method_id' => PaymentMethod::CASH,
                'earmark_type' => self::EARMARK_TYPE,
                'earmark_id' => $mandatoryContributionId,
            ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('obligatorio', (string) $response->json('message'));
    }

    public function test_submit_rejects_inactive_project_and_declined_contribution(): void
    {
        $scn = $this->earmarkScenario();

        // Declined contribution first (the project is still active here).
        $scn['tenant']->run(function () use ($scn): void {
            app(ContributionService::class)->decline(ProjectContribution::findOrFail($scn['contributionId']));
        });

        $response = $this->tenantRequest($scn['residentToken'], $scn['tenant'])
            ->postJson('/api/payment/submit', $this->earmarkSubmitPayload($scn));

        $response->assertStatus(422);
        $this->assertStringContainsString('declinada', (string) $response->json('message'));

        // Cancelled project → inactive.
        $scn['tenant']->run(function () use ($scn): void {
            app(ContributionService::class)->revertDecline(ProjectContribution::findOrFail($scn['contributionId']));
            app(ProjectActivationService::class)->cancel($scn['project']->refresh());
        });

        $response = $this->tenantRequest($scn['residentToken'], $scn['tenant'])
            ->postJson('/api/payment/submit', $this->earmarkSubmitPayload($scn));

        $response->assertStatus(422);
        $this->assertStringContainsString('no está activo', (string) $response->json('message'));
    }

    public function test_submit_rejects_duplicate_pending_report(): void
    {
        $scn = $this->earmarkScenario();

        $this->tenantRequest($scn['residentToken'], $scn['tenant'])
            ->postJson('/api/payment/submit', $this->earmarkSubmitPayload($scn))
            ->assertStatus(201);

        $response = $this->tenantRequest($scn['residentToken'], $scn['tenant'])
            ->postJson('/api/payment/submit', $this->earmarkSubmitPayload($scn));

        $response->assertStatus(422);
        $this->assertStringContainsString('pendiente de validación', (string) $response->json('message'));
    }

    // =========================================================================
    // Validation confirms: charge born + full amount PINNED (never FIFO)
    // =========================================================================

    public function test_validate_earmarked_creates_charge_and_pins_full_amount(): void
    {
        $scn = $this->earmarkScenario();

        // Old overdue debt that plain FIFO would grab — it must stay untouched.
        $oldChargeId = $this->makeOldDebtCharge($scn['tenant'], $scn['unit'], 150000);

        $paymentId = $this->tenantRequest($scn['residentToken'], $scn['tenant'])
            ->postJson('/api/payment/submit', $this->earmarkSubmitPayload($scn))
            ->assertStatus(201)
            ->json('data.id');

        $this->tenantRequest($scn['treasurerToken'], $scn['tenant'])
            ->postJson("/api/payment/{$paymentId}/validate")
            ->assertStatus(200);

        $scn['tenant']->run(function () use ($scn, $paymentId, $oldChargeId): void {
            // The project charge was born on validation, fully paid.
            $charge = Charge::where('community_project_id', $scn['project']->id)
                ->where('unit_id', $scn['unit']->id)
                ->firstOrFail();
            $this->assertSame(30000, (int) $charge->getAttributes()['paid_amount']);
            $this->assertSame(ChargeStatus::COMPLETED, $charge->charge_status_id);

            // A SINGLE application, pinned to that charge for the full amount.
            $applications = PaymentApplication::where('payment_id', $paymentId)->get();
            $this->assertCount(1, $applications);
            $this->assertSame($charge->id, $applications->first()->charge_id);
            $this->assertSame(30000, (int) $applications->first()->getAttributes()['applied_amount']);

            // Old debt untouched — the earmark defeats FIFO by design.
            $oldCharge = Charge::findOrFail($oldChargeId);
            $this->assertSame(0, (int) $oldCharge->getAttributes()['paid_amount']);
            $this->assertSame(ChargeStatus::OVERDUE, $oldCharge->charge_status_id);

            // Contribution rollup via the observer; no credit generated.
            $contribution = ProjectContribution::findOrFail($scn['contributionId']);
            $this->assertSame(30000, (int) $contribution->getAttributes()['contributed_amount']);
            $creditRow = CreditBalance::where('unit_id', $scn['unit']->id)->first();
            $this->assertSame(0, $creditRow ? (int) $creditRow->getAttributes()['amount'] : 0);

            // Bank incremented and folio assigned on validation.
            $this->assertSame(30000, (int) BankAccount::findOrFail($scn['account']->id)->getAttributes()['current_balance']);
            $payment = Payment::findOrFail($paymentId);
            $this->assertSame(PaymentStatus::CONFIRMED, $payment->payment_status_id);
            $this->assertMatchesRegularExpression('/^R-\d{4}-\d{6}$/', (string) $payment->receipt_number);
        });
    }

    public function test_validation_crossing_goal_latches_fully_funded(): void
    {
        $scn = $this->earmarkScenario(['goal_amount' => 30000]);

        $paymentId = $this->tenantRequest($scn['residentToken'], $scn['tenant'])
            ->postJson('/api/payment/submit', $this->earmarkSubmitPayload($scn))
            ->assertStatus(201)
            ->json('data.id');

        $this->tenantRequest($scn['treasurerToken'], $scn['tenant'])
            ->postJson("/api/payment/{$paymentId}/validate")
            ->assertStatus(200);

        $scn['tenant']->run(function () use ($scn): void {
            $this->assertSame(
                CommunityProjectStatus::FULLY_FUNDED,
                CommunityProject::findOrFail($scn['project']->id)->community_project_status_id,
            );
        });
    }

    public function test_validate_fails_when_project_cancelled_after_submit(): void
    {
        $scn = $this->earmarkScenario();

        $paymentId = $this->tenantRequest($scn['residentToken'], $scn['tenant'])
            ->postJson('/api/payment/submit', $this->earmarkSubmitPayload($scn))
            ->assertStatus(201)
            ->json('data.id');

        $scn['tenant']->run(function () use ($scn): void {
            app(ProjectActivationService::class)->cancel($scn['project']->refresh());
        });

        $this->tenantRequest($scn['treasurerToken'], $scn['tenant'])
            ->postJson("/api/payment/{$paymentId}/validate")
            ->assertStatus(422);

        $scn['tenant']->run(function () use ($scn, $paymentId): void {
            // The whole confirmation rolled back: still pending, no charge, no folio, no bank move.
            $payment = Payment::findOrFail($paymentId);
            $this->assertSame(PaymentStatus::PENDING_VALIDATION, $payment->payment_status_id);
            $this->assertNull($payment->receipt_number);
            $this->assertSame(0, Charge::where('community_project_id', $scn['project']->id)->count());
            $this->assertSame(0, (int) BankAccount::findOrFail($scn['account']->id)->getAttributes()['current_balance']);
        });
    }

    // =========================================================================
    // Reject / cancel reversals
    // =========================================================================

    public function test_reject_earmarked_leaves_project_untouched(): void
    {
        $scn = $this->earmarkScenario();

        $paymentId = $this->tenantRequest($scn['residentToken'], $scn['tenant'])
            ->postJson('/api/payment/submit', $this->earmarkSubmitPayload($scn))
            ->assertStatus(201)
            ->json('data.id');

        $this->tenantRequest($scn['treasurerToken'], $scn['tenant'])
            ->postJson("/api/payment/{$paymentId}/reject", ['reason' => 'Comprobante ilegible.'])
            ->assertStatus(200);

        $scn['tenant']->run(function () use ($scn, $paymentId): void {
            $this->assertSame(PaymentStatus::CANCELLED, Payment::findOrFail($paymentId)->payment_status_id);
            $this->assertSame(0, Charge::where('community_project_id', $scn['project']->id)->count());
            $contribution = ProjectContribution::findOrFail($scn['contributionId']);
            $this->assertSame(0, (int) $contribution->getAttributes()['contributed_amount']);
            $this->assertSame(ProjectContributionStatus::PENDING, $contribution->project_contribution_status_id);
        });
    }

    public function test_cancel_confirmed_earmarked_reverses_contribution(): void
    {
        $scn = $this->earmarkScenario();

        $paymentId = $this->tenantRequest($scn['residentToken'], $scn['tenant'])
            ->postJson('/api/payment/submit', $this->earmarkSubmitPayload($scn))
            ->assertStatus(201)
            ->json('data.id');

        $this->tenantRequest($scn['treasurerToken'], $scn['tenant'])
            ->postJson("/api/payment/{$paymentId}/validate")
            ->assertStatus(200);

        $scn['tenant']->run(function () use ($scn, $paymentId): void {
            $this->assertSame(
                30000,
                (int) ProjectContribution::findOrFail($scn['contributionId'])->getAttributes()['contributed_amount'],
            );

            app(PaymentService::class)->cancel(Payment::findOrFail($paymentId), 'Pago capturado por error.');

            $contribution = ProjectContribution::findOrFail($scn['contributionId']);
            $this->assertSame(0, (int) $contribution->getAttributes()['contributed_amount']);
            $this->assertSame(ProjectContributionStatus::PENDING, $contribution->project_contribution_status_id);
            $this->assertSame(0, (int) BankAccount::findOrFail($scn['account']->id)->getAttributes()['current_balance']);
        });
    }

    // =========================================================================
    // Regression: a NORMAL payment (no earmark) keeps the exact FIFO behavior
    // =========================================================================

    public function test_validate_normal_payment_without_earmark_applies_fifo(): void
    {
        $scn = $this->earmarkScenario();

        $oldChargeId = $this->makeOldDebtCharge($scn['tenant'], $scn['unit'], 50000);

        $paymentId = $this->tenantRequest($scn['residentToken'], $scn['tenant'])
            ->postJson('/api/payment/submit', [
                'unit_id' => $scn['unit']->id,
                'paid_at' => '2026-07-01',
                'total_amount' => 50000,
                'payment_method_id' => PaymentMethod::CASH,
            ])
            ->assertStatus(201)
            ->json('data.id');

        $this->tenantRequest($scn['treasurerToken'], $scn['tenant'])
            ->postJson("/api/payment/{$paymentId}/validate")
            ->assertStatus(200);

        $scn['tenant']->run(function () use ($oldChargeId, $paymentId): void {
            $oldCharge = Charge::findOrFail($oldChargeId);
            $this->assertSame(50000, (int) $oldCharge->getAttributes()['paid_amount']);
            $this->assertSame(ChargeStatus::COMPLETED, $oldCharge->charge_status_id);

            $applications = PaymentApplication::where('payment_id', $paymentId)->get();
            $this->assertCount(1, $applications);
            $this->assertSame($oldChargeId, $applications->first()->charge_id);
        });
    }

    // =========================================================================
    // The comprobante survives validation
    // =========================================================================

    public function test_receipt_media_survives_validation(): void
    {
        $scn = $this->earmarkScenario();

        $paymentId = $this->tenantRequest($scn['residentToken'], $scn['tenant'])
            ->post('/api/payment/submit', [
                'unit_id' => $scn['unit']->id,
                'paid_at' => '2026-07-01',
                'total_amount' => 30000,
                'payment_method_id' => PaymentMethod::TRANSFER,
                'reference' => 'TRX-EARMARK-1',
                'receipt' => $this->fakeFile('comprobante.pdf', 'application/pdf'),
                'earmark_type' => self::EARMARK_TYPE,
                'earmark_id' => $scn['contributionId'],
            ])
            ->assertStatus(201)
            ->json('data.id');

        $this->tenantRequest($scn['treasurerToken'], $scn['tenant'])
            ->postJson("/api/payment/{$paymentId}/validate")
            ->assertStatus(200);

        $scn['tenant']->run(function () use ($paymentId): void {
            $payment = Payment::findOrFail($paymentId);
            $this->assertSame(PaymentStatus::CONFIRMED, $payment->payment_status_id);
            $this->assertCount(1, $payment->getMedia('receipt'));
        });
    }

    // =========================================================================
    // Consolidation: register + earmark == the retired contribute($paymentData)
    // =========================================================================

    public function test_register_with_earmark_matches_retired_contribute_flow(): void
    {
        $scn = $this->earmarkScenario();
        $adminToken = $this->makeUser($scn['tenant'])->createToken('admin', ['*'])->plainTextToken;

        $this->tenantRequest($adminToken, $scn['tenant'])
            ->postJson('/api/payment/register', [
                'unit_id' => $scn['unit']->id,
                'paid_at' => '2026-07-01',
                'total_amount' => 50000,
                'payment_method_id' => PaymentMethod::CASH,
                'bank_account_id' => $scn['account']->id,
                'earmark_type' => self::EARMARK_TYPE,
                'earmark_id' => $scn['contributionId'],
            ])
            ->assertStatus(201);

        $scn['tenant']->run(function () use ($scn): void {
            // Same end state the retired contribute($paymentData) produced (§11.8.1).
            $charge = Charge::where('community_project_id', $scn['project']->id)
                ->where('unit_id', $scn['unit']->id)
                ->firstOrFail();
            $this->assertSame(50000, (int) $charge->getAttributes()['paid_amount']);
            $this->assertSame(ChargeStatus::COMPLETED, $charge->charge_status_id);

            $contribution = ProjectContribution::findOrFail($scn['contributionId']);
            $this->assertSame(50000, (int) $contribution->getAttributes()['contributed_amount']);
            $this->assertSame(ProjectContributionStatus::IDEAL, $contribution->project_contribution_status_id);

            $payment = Payment::where('unit_id', $scn['unit']->id)->firstOrFail();
            $this->assertSame(PaymentStatus::CONFIRMED, $payment->payment_status_id);
            $this->assertMatchesRegularExpression('/^R-\d{4}-\d{6}$/', (string) $payment->receipt_number);

            $this->assertSame(50000, (int) BankAccount::findOrFail($scn['account']->id)->getAttributes()['current_balance']);
        });
    }

    public function test_register_with_earmark_and_manual_applications_is_422(): void
    {
        $scn = $this->earmarkScenario();
        $adminToken = $this->makeUser($scn['tenant'])->createToken('admin', ['*'])->plainTextToken;

        $response = $this->tenantRequest($adminToken, $scn['tenant'])
            ->postJson('/api/payment/register', [
                'unit_id' => $scn['unit']->id,
                'paid_at' => '2026-07-01',
                'total_amount' => 50000,
                'payment_method_id' => PaymentMethod::CASH,
                'bank_account_id' => $scn['account']->id,
                'earmark_type' => self::EARMARK_TYPE,
                'earmark_id' => $scn['contributionId'],
                'manual_applications' => [
                    ['charge_id' => (string) Str::uuid(), 'apply_amount' => 50000],
                ],
            ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('aplicaciones manuales', (string) $response->json('message'));

        $scn['tenant']->run(function () use ($scn): void {
            $this->assertSame(0, Payment::where('unit_id', $scn['unit']->id)->count());
        });
    }

    public function test_contribute_endpoint_no_longer_accepts_payment_fields(): void
    {
        $scn = $this->earmarkScenario();
        $adminToken = $this->makeUser($scn['tenant'])->createToken('admin', ['*'])->plainTextToken;

        $this->tenantRequest($adminToken, $scn['tenant'])
            ->postJson("/api/community-project/{$scn['project']->id}/contribute", [
                'amount' => 50000,
                'payment_method_id' => PaymentMethod::CASH,
                'bank_account_id' => $scn['account']->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['payment_method_id', 'bank_account_id']);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Voluntary active project + resident attached to a unit + primary bank account.
     *
     * @param  array<string, mixed>  $projectOverrides
     * @return array{tenant: Tenant, unit: Unit, resident: User, residentToken: string, treasurerToken: string, account: BankAccount, project: CommunityProject, contributionId: string}
     */
    private function earmarkScenario(array $projectOverrides = []): array
    {
        $tenant = $this->makeTenant('Earmark');
        $this->seedTenant($tenant);

        $unit = $this->makeUnit($tenant);
        $resident = $this->makeUser($tenant);
        $this->attachToUnit($tenant, $unit, $resident);
        $account = $this->makeBankAccount($tenant);

        $treasurer = $this->makeUser($tenant);

        $project = $this->makeProject($tenant, $projectOverrides);
        $contributionId = $this->contributionId($tenant, $project, $unit);

        return [
            'tenant' => $tenant,
            'unit' => $unit,
            'resident' => $resident,
            'residentToken' => $resident->createToken('resident', ['create.payment_submission'])->plainTextToken,
            'treasurerToken' => $treasurer->createToken('treasurer', ['update.payment'])->plainTextToken,
            'account' => $account,
            'project' => $project,
            'contributionId' => $contributionId,
        ];
    }

    /**
     * The standard resident earmarked submit payload (cash — no comprobante required).
     *
     * @param  array{unit: Unit, contributionId: string}  $scn
     * @return array<string, mixed>
     */
    private function earmarkSubmitPayload(array $scn): array
    {
        return [
            'unit_id' => $scn['unit']->id,
            'paid_at' => '2026-07-01',
            'total_amount' => 30000,
            'payment_method_id' => PaymentMethod::CASH,
            'earmark_type' => self::EARMARK_TYPE,
            'earmark_id' => $scn['contributionId'],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeProject(Tenant $tenant, array $overrides = []): CommunityProject
    {
        $project = null;
        $tenant->run(function () use (&$project, $overrides): void {
            $project = CommunityProject::create(array_merge([
                'title' => 'Proyecto Earmark '.Str::random(6),
                'description' => 'Proyecto de prueba earmark',
                'community_project_category_id' => Catalog::id(CommunityProjectCategory::class, 'Obra'),
                'participation_type_id' => ParticipationType::VOLUNTARY,
                'amount_mode_id' => AmountMode::RANGE,
                'min_amount' => 20000,
                'ideal_amount' => 50000,
                'allow_decline' => true,
                'target_type' => 'all',
                'target_selector' => null,
                'goal_amount' => 1000000,
                'start_date' => '2026-04-01',
                'due_date' => '2026-12-31',
                'visible_to_residents' => true,
                'allow_comments' => true,
                'community_project_status_id' => CommunityProjectStatus::DRAFT,
            ], $overrides));

            app(ProjectActivationService::class)->activate($project);
        });

        return $project;
    }

    private function contributionId(Tenant $tenant, CommunityProject $project, Unit $unit): string
    {
        return (string) $tenant->run(
            fn () => ProjectContribution::where('community_project_id', $project->id)
                ->where('unit_id', $unit->id)
                ->value('id')
        );
    }

    private function makeUnit(Tenant $tenant): Unit
    {
        $unit = null;
        $tenant->run(function () use (&$unit): void {
            $unit = Unit::create([
                'unit_type_id' => Catalog::id(UnitType::class, 'Casa'),
                'number' => (string) mt_rand(100, 99999),
                'unit_status_id' => Catalog::id(UnitStatus::class, 'Activa'),
                'ownership_share' => 1.0,
            ]);
        });

        return $unit;
    }

    private function attachToUnit(Tenant $tenant, Unit $unit, User $user, bool $billingOwner = true): void
    {
        $tenant->run(function () use ($unit, $user, $billingOwner): void {
            UnitUser::create([
                'unit_id' => $unit->id,
                'user_id' => $user->id,
                'unit_relationship_id' => UnitRelationship::OWNER,
                'is_billing_owner' => $billingOwner,
            ]);
        });
    }

    private function makeBankAccount(Tenant $tenant): BankAccount
    {
        $account = null;
        $tenant->run(function () use (&$account): void {
            $account = BankAccount::create([
                'name' => 'Caja earmark',
                'bank_account_type_id' => BankAccountType::CASH,
                'bank_account_purpose_id' => Catalog::id(BankAccountPurpose::class, 'Operativa'),
                'currency' => 'MXN',
                'initial_balance' => 0,
                'current_balance' => 0,
                'is_primary' => true,
                'is_active' => true,
            ]);
        });

        return $account;
    }

    /**
     * An old, overdue, unpaid charge — exactly what plain FIFO would grab first.
     */
    private function makeOldDebtCharge(Tenant $tenant, Unit $unit, int $amount): string
    {
        return (string) $tenant->run(function () use ($unit, $amount): string {
            $concept = ChargeConcept::create([
                'code' => 'MAINT_OLD_'.mt_rand(1000, 99999),
                'name' => 'Mantenimiento',
                'charge_concept_category_id' => Catalog::id(ChargeConceptCategory::class, 'Ordinaria'),
                'is_active' => true,
            ]);

            $charge = new Charge;
            $charge->fill([
                'charge_concept_id' => $concept->id,
                'unit_id' => $unit->id,
                'expected_amount' => $amount,
                'issued_at' => '2025-01-01',
                'due_date' => '2025-01-31',
                'grace_days' => 0,
            ]);
            $charge->paid_amount = 0;
            $charge->is_cancelled = false;
            $charge->charge_status_id = app(ChargeStatusService::class)->resolveStatusKey($charge);
            $charge->save();

            return $charge->id;
        });
    }

    private function fakeFile(string $name, string $mime): UploadedFile
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'earmark_up_');
        $this->tempFiles[] = $tmpPath;
        file_put_contents($tmpPath, str_repeat('%PDF-1.4 comprobante ', 200));

        return new UploadedFile($tmpPath, $name, $mime, null, true);
    }
}
