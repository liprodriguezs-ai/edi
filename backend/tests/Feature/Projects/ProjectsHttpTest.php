<?php

declare(strict_types=1);

namespace Tests\Feature\Projects;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Collections\Models\AmountMode;
use Modules\Collections\Models\Charge;
use Modules\Collections\Models\ChargeStatus;
use Modules\Collections\Models\CreditBalance;
use Modules\Collections\Models\Payment;
use Modules\Collections\Models\PaymentMethod;
use Modules\Collections\Services\PaymentService;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitRelationship;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Modules\Housing\Models\UnitUser;
use Modules\Notification\Models\Notification;
use Modules\Projects\Models\CommunityProject;
use Modules\Projects\Models\CommunityProjectCategory;
use Modules\Projects\Models\CommunityProjectStatus;
use Modules\Projects\Models\ParticipationType;
use Modules\Projects\Models\ProjectComment;
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
 * HTTP tests for Module 7 — Community Projects.
 *
 * Covers the Wave-1 findings:
 *  - projects-01/sec-money-05: POST /community-project/{id}/contribute (happy path +
 *    negatives: hidden 404, out-of-range amount 422, mandatory 422, executed 422)
 *  - projects-03/sec-money-06: voluntary contributions create Payment + Charge;
 *    paying a mandatory project charge syncs project_contribution (observer), incl.
 *    overpayment → credit (docblock flow C) and payment cancellation reversal
 *  - projects-05: resident visibility (drafts + hidden projects are 404/excluded)
 *  - projects-07/-09: comment author cannot be spoofed; FormRequest validation
 *  - projects-08: hidden comments invisible to residents
 *  - projects-04: decline blocked on mandatory / allow_decline=false
 *  - projects-10/sec-input-09: FSM and money columns not PATCHable (422)
 *  - projects-12: /cancel endpoint (cancel.community_project) + treasurer edit rules
 *  - projects-06: activation of a hidden project does not notify residents
 *  - sec-money-07/projects-15: sequential contributions stay consistent
 */
final class ProjectsHttpTest extends TestCase
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

    // =========================================================================
    // Helpers
    // =========================================================================

    private function makeUser(Tenant $tenant): User
    {
        $user = User::create([
            'name' => 'Test '.Str::random(5),
            'email' => Str::random(10).'@test.com',
            'password' => Hash::make('secret1234'),
            'auth_active' => true,
        ]);

        $user->tenants()->attach($tenant->id);

        return $user;
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
                'name' => 'Caja proyectos',
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
     * Build (and optionally activate) a project inside the tenant context.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function makeProject(Tenant $tenant, array $overrides = [], bool $activate = true): CommunityProject
    {
        $project = null;
        $tenant->run(function () use (&$project, $overrides, $activate): void {
            $project = CommunityProject::create(array_merge([
                'title' => 'Proyecto HTTP '.Str::random(6),
                'description' => 'Proyecto de prueba HTTP',
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

            if ($activate) {
                app(ProjectActivationService::class)->activate($project);
            }
        });

        return $project;
    }

    private function adminToken(User $user): string
    {
        return $user->createToken('admin', ['*'])->plainTextToken;
    }

    /**
     * @param  array<string>  $abilities
     */
    private function residentToken(User $user, array $abilities = ['read.community_project', 'read.project_contribution', 'read.project_comment', 'create.project_comment']): string
    {
        return $user->createToken('resident', $abilities)->plainTextToken;
    }

    /**
     * @return array{tenant: Tenant, unit: Unit, resident: User, token: string, account: BankAccount}
     */
    private function residentScenario(): array
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $unit = $this->makeUnit($tenant);
        $resident = $this->makeUser($tenant);
        $this->attachToUnit($tenant, $unit, $resident);
        $account = $this->makeBankAccount($tenant);

        return [
            'tenant' => $tenant,
            'unit' => $unit,
            'resident' => $resident,
            'token' => $this->residentToken($resident),
            'account' => $account,
        ];
    }

    // =========================================================================
    // Contribute endpoint — projects-01 / sec-money-05 / projects-03
    // =========================================================================

    public function test_register_with_earmark_creates_payment_charge_and_updates_contribution(): void
    {
        $scn = $this->residentScenario();
        $project = $this->makeProject($scn['tenant']);
        $admin = $this->makeUser($scn['tenant']);

        $contributionId = $scn['tenant']->run(
            fn () => ProjectContribution::where('community_project_id', $project->id)
                ->where('unit_id', $scn['unit']->id)
                ->value('id')
        );

        // Earmark consolidation: the admin direct contribution travels through
        // payment/register + earmark (1:1 replacement of contribute($paymentData)).
        $this->tenantRequest($this->adminToken($admin), $scn['tenant'])
            ->postJson('/api/payment/register', [
                'unit_id' => $scn['unit']->id,
                'paid_at' => '2026-06-10',
                'total_amount' => 50000,
                'payment_method_id' => PaymentMethod::CASH,
                'bank_account_id' => $scn['account']->id,
                'earmark_type' => 'project_contribution',
                'earmark_id' => $contributionId,
            ])
            ->assertStatus(201);

        $scn['tenant']->run(function () use ($project, $scn): void {
            $contribution = ProjectContribution::where('community_project_id', $project->id)
                ->where('unit_id', $scn['unit']->id)
                ->firstOrFail();

            $this->assertSame(50000, (int) $contribution->getAttributes()['contributed_amount']);
            $this->assertSame(ProjectContributionStatus::IDEAL, $contribution->project_contribution_status_id);

            // §11.8.1: the eventual charge exists, linked to the project, fully paid.
            $charge = Charge::where('community_project_id', $project->id)
                ->where('unit_id', $scn['unit']->id)
                ->firstOrFail();
            $this->assertSame(50000, (int) $charge->getAttributes()['paid_amount']);
            $this->assertSame(ChargeStatus::COMPLETED, $charge->charge_status_id);

            // §11.9 Flujo B paso 4: "registro en pago + aportación" — receipt issued.
            $payment = Payment::where('unit_id', $scn['unit']->id)->firstOrFail();
            $this->assertSame(50000, (int) $payment->getAttributes()['total_amount']);
            $this->assertMatchesRegularExpression('/^R-\d{4}-\d{6}$/', $payment->receipt_number);

            // Treasury sees the money.
            $account = BankAccount::findOrFail($scn['account']->id);
            $this->assertSame(50000, (int) $account->getAttributes()['current_balance']);
        });
    }

    public function test_contribute_hidden_or_draft_project_is_404_for_residents(): void
    {
        $scn = $this->residentScenario();

        $hidden = $this->makeProject($scn['tenant'], ['visible_to_residents' => false]);
        $draft = $this->makeProject($scn['tenant'], [], activate: false);

        $payload = ['amount' => 20000];

        $this->tenantRequest($scn['token'], $scn['tenant'])
            ->postJson("/api/community-project/{$hidden->id}/contribute", $payload)
            ->assertStatus(404);

        $this->tenantRequest($scn['token'], $scn['tenant'])
            ->postJson("/api/community-project/{$draft->id}/contribute", $payload)
            ->assertStatus(404);
    }

    public function test_contribute_rejects_out_of_range_amounts(): void
    {
        $scn = $this->residentScenario();
        $project = $this->makeProject($scn['tenant']);

        // Authorization wins before validation: only a board caller reaches the
        // amount rules (a resident gets 403 without learning the field contract).
        $boardToken = $this->residentToken($scn['resident'], ['read.community_project', 'update.project_contribution']);

        foreach ([0, -5000, 1000000000] as $badAmount) {
            $this->tenantRequest($boardToken, $scn['tenant'])
                ->postJson("/api/community-project/{$project->id}/contribute", [
                    'amount' => $badAmount,
                    'unit_id' => $scn['unit']->id,
                ])
                ->assertStatus(422);
        }

        // A resident with the same bad payload never sees validation details — 403 first.
        $this->tenantRequest($scn['token'], $scn['tenant'])
            ->postJson("/api/community-project/{$project->id}/contribute", ['amount' => 0])
            ->assertStatus(403);
    }

    public function test_contribute_rejected_for_mandatory_and_executed_projects(): void
    {
        $scn = $this->residentScenario();
        $admin = $this->makeUser($scn['tenant']);
        $adminToken = $this->adminToken($admin);

        $mandatory = $this->makeProject($scn['tenant'], [
            'participation_type_id' => ParticipationType::MANDATORY,
            'amount_mode_id' => AmountMode::FIXED_REQUIRED,
            'fixed_amount' => 50000,
            'min_amount' => null,
            'ideal_amount' => null,
        ]);

        $executed = $this->makeProject($scn['tenant']);
        $scn['tenant']->run(function () use ($executed): void {
            app(ProjectActivationService::class)->execute($executed->refresh());
        });

        $payload = [
            'amount' => 20000,
            'unit_id' => $scn['unit']->id,
        ];

        $this->tenantRequest($adminToken, $scn['tenant'])
            ->postJson("/api/community-project/{$mandatory->id}/contribute", $payload)
            ->assertStatus(422);

        $this->tenantRequest($adminToken, $scn['tenant'])
            ->postJson("/api/community-project/{$executed->id}/contribute", $payload)
            ->assertStatus(422);
    }

    public function test_contribute_after_decline_reactivates_the_contribution(): void
    {
        $scn = $this->residentScenario();
        $project = $this->makeProject($scn['tenant']);

        $scn['tenant']->run(function () use ($project, $scn): void {
            $contribution = ProjectContribution::where('community_project_id', $project->id)
                ->where('unit_id', $scn['unit']->id)
                ->firstOrFail();
            app(ContributionService::class)->decline($contribution);
        });

        $admin = $this->makeUser($scn['tenant']);

        $this->tenantRequest($this->adminToken($admin), $scn['tenant'])
            ->postJson("/api/community-project/{$project->id}/contribute", [
                'amount' => 20000,
                'unit_id' => $scn['unit']->id,
            ])
            ->assertStatus(200);

        $scn['tenant']->run(function () use ($project, $scn): void {
            $contribution = ProjectContribution::where('community_project_id', $project->id)
                ->where('unit_id', $scn['unit']->id)
                ->firstOrFail();

            $this->assertFalse((bool) $contribution->declined, '§11.8.2: contributing after a decline reverts the decline');
            $this->assertSame(20000, (int) $contribution->getAttributes()['contributed_amount']);
        });
    }

    public function test_contribute_is_board_only_residents_get_403(): void
    {
        $scn = $this->residentScenario();
        $project = $this->makeProject($scn['tenant']);

        // Earmark consolidation: a resident (even a member of the targeted unit)
        // can no longer adjust money directly — they report via payment/submit +
        // earmark and wait for treasurer validation.
        $this->tenantRequest($scn['token'], $scn['tenant'])
            ->postJson("/api/community-project/{$project->id}/contribute", [
                'amount' => 20000,
                'unit_id' => $scn['unit']->id,
            ])
            ->assertStatus(403);

        // A stranger without board abilities is rejected the same way.
        $stranger = $this->makeUser($scn['tenant']);
        $this->tenantRequest($this->residentToken($stranger), $scn['tenant'])
            ->postJson("/api/community-project/{$project->id}/contribute", [
                'amount' => 20000,
                'unit_id' => $scn['unit']->id,
            ])
            ->assertStatus(403);

        $scn['tenant']->run(function () use ($project, $scn): void {
            $contribution = ProjectContribution::where('community_project_id', $project->id)
                ->where('unit_id', $scn['unit']->id)
                ->firstOrFail();
            $this->assertSame(0, (int) $contribution->getAttributes()['contributed_amount']);
        });
    }

    public function test_sequential_contributions_accumulate_consistently(): void
    {
        $scn = $this->residentScenario();
        $project = $this->makeProject($scn['tenant']);
        $admin = $this->makeUser($scn['tenant']);
        $adminToken = $this->adminToken($admin);

        $contributionId = $scn['tenant']->run(
            fn () => ProjectContribution::where('community_project_id', $project->id)
                ->where('unit_id', $scn['unit']->id)
                ->value('id')
        );

        foreach ([20000, 30000] as $amount) {
            $this->tenantRequest($adminToken, $scn['tenant'])
                ->postJson('/api/payment/register', [
                    'unit_id' => $scn['unit']->id,
                    'paid_at' => '2026-06-10',
                    'total_amount' => $amount,
                    'payment_method_id' => PaymentMethod::CASH,
                    'bank_account_id' => $scn['account']->id,
                    'earmark_type' => 'project_contribution',
                    'earmark_id' => $contributionId,
                ])
                ->assertStatus(201);
        }

        $scn['tenant']->run(function () use ($project, $scn): void {
            $contribution = ProjectContribution::where('community_project_id', $project->id)
                ->where('unit_id', $scn['unit']->id)
                ->firstOrFail();

            $this->assertSame(50000, (int) $contribution->getAttributes()['contributed_amount']);
            $this->assertSame(ProjectContributionStatus::IDEAL, $contribution->project_contribution_status_id);
            $this->assertSame(2, Payment::where('unit_id', $scn['unit']->id)->count());
            $this->assertSame(2, Charge::where('community_project_id', $project->id)->count());

            $account = BankAccount::findOrFail($scn['account']->id);
            $this->assertSame(50000, (int) $account->getAttributes()['current_balance']);
        });
    }

    // =========================================================================
    // Mandatory charge → contribution bridge — projects-02 / sec-money-06 (flow C)
    // =========================================================================

    public function test_paying_mandatory_project_charge_syncs_contribution_and_overpayment_becomes_credit(): void
    {
        $scn = $this->residentScenario();
        $admin = $this->makeUser($scn['tenant']);
        $adminToken = $this->adminToken($admin);

        $project = $this->makeProject($scn['tenant'], [
            'participation_type_id' => ParticipationType::MANDATORY,
            'amount_mode_id' => AmountMode::FIXED_REQUIRED,
            'fixed_amount' => 50000,
            'min_amount' => null,
            'ideal_amount' => null,
            'target_type' => 'single_unit',
            'target_selector' => ['unit_id' => $scn['unit']->id],
        ]);

        // Flow C: pay the mandatory project charge WITH OVERPAYMENT via FIFO.
        $this->tenantRequest($adminToken, $scn['tenant'])
            ->postJson('/api/payment/register', [
                'unit_id' => $scn['unit']->id,
                'paid_at' => '2026-06-10',
                'total_amount' => 60000,
                'payment_method_id' => PaymentMethod::CASH,
                'bank_account_id' => $scn['account']->id,
            ])
            ->assertStatus(201);

        $scn['tenant']->run(function () use ($project, $scn): void {
            // §11.9 Flujo A paso 3: the contribution is marked complete automatically.
            $contribution = ProjectContribution::where('community_project_id', $project->id)
                ->where('unit_id', $scn['unit']->id)
                ->firstOrFail();
            $this->assertSame(50000, (int) $contribution->getAttributes()['contributed_amount']);
            $this->assertSame(ProjectContributionStatus::IDEAL, $contribution->project_contribution_status_id);

            // §11.8.4: the surplus became credit for the unit.
            $credit = CreditBalance::where('unit_id', $scn['unit']->id)->firstOrFail();
            $this->assertSame(10000, (int) $credit->getAttributes()['amount']);

            // The summary rollup now reflects the mandatory payment.
            $summary = app(ContributionService::class)->getSummary($project->refresh());
            $this->assertSame(50000, $summary['raised']);
        });
    }

    public function test_cancelling_payment_reverses_mandatory_contribution_sync(): void
    {
        $scn = $this->residentScenario();
        $admin = $this->makeUser($scn['tenant']);
        $adminToken = $this->adminToken($admin);

        $project = $this->makeProject($scn['tenant'], [
            'participation_type_id' => ParticipationType::MANDATORY,
            'amount_mode_id' => AmountMode::FIXED_REQUIRED,
            'fixed_amount' => 50000,
            'min_amount' => null,
            'ideal_amount' => null,
            'target_type' => 'single_unit',
            'target_selector' => ['unit_id' => $scn['unit']->id],
        ]);

        $this->tenantRequest($adminToken, $scn['tenant'])
            ->postJson('/api/payment/register', [
                'unit_id' => $scn['unit']->id,
                'paid_at' => '2026-06-10',
                'total_amount' => 50000,
                'payment_method_id' => PaymentMethod::CASH,
                'bank_account_id' => $scn['account']->id,
            ])
            ->assertStatus(201);

        $scn['tenant']->run(function () use ($project, $scn): void {
            $contribution = ProjectContribution::where('community_project_id', $project->id)
                ->where('unit_id', $scn['unit']->id)
                ->firstOrFail();
            $this->assertSame(50000, (int) $contribution->getAttributes()['contributed_amount']);

            $payment = Payment::where('unit_id', $scn['unit']->id)->firstOrFail();
            app(PaymentService::class)->cancel($payment, 'Pago capturado por error.');

            $contribution->refresh();
            $this->assertSame(0, (int) $contribution->getAttributes()['contributed_amount']);
            $this->assertSame(ProjectContributionStatus::PENDING, $contribution->project_contribution_status_id);
        });
    }

    // =========================================================================
    // FSM + mass assignment — projects-10 / sec-input-09
    // =========================================================================

    public function test_project_status_cannot_be_changed_via_patch(): void
    {
        $scn = $this->residentScenario();
        $admin = $this->makeUser($scn['tenant']);
        $adminToken = $this->adminToken($admin);

        $project = $this->makeProject($scn['tenant'], [], activate: false);

        $this->tenantRequest($adminToken, $scn['tenant'])
            ->patchJson("/api/community_project/{$project->id}", [
                'community_project_status_id' => CommunityProjectStatus::ACTIVE,
            ])
            ->assertStatus(422);

        $scn['tenant']->run(function () use ($project): void {
            $fresh = CommunityProject::findOrFail($project->id);
            $this->assertSame(CommunityProjectStatus::DRAFT, $fresh->community_project_status_id, 'FSM bypass via PATCH must be impossible');

            // No contributions were created — activation never ran.
            $this->assertSame(0, ProjectContribution::where('community_project_id', $project->id)->count());
        });
    }

    public function test_contribution_money_and_status_cannot_be_mass_assigned(): void
    {
        $scn = $this->residentScenario();
        $admin = $this->makeUser($scn['tenant']);
        $adminToken = $this->adminToken($admin);

        $project = $this->makeProject($scn['tenant']);

        $contributionId = null;
        $scn['tenant']->run(function () use ($project, $scn, &$contributionId): void {
            $contributionId = ProjectContribution::where('community_project_id', $project->id)
                ->where('unit_id', $scn['unit']->id)
                ->value('id');
        });

        $this->tenantRequest($adminToken, $scn['tenant'])
            ->patchJson("/api/project_contribution/{$contributionId}", [
                'contributed_amount' => 99999999,
            ])
            ->assertStatus(422);

        $this->tenantRequest($adminToken, $scn['tenant'])
            ->patchJson("/api/project_contribution/{$contributionId}", [
                'project_contribution_status_id' => ProjectContributionStatus::GENEROUS,
            ])
            ->assertStatus(422);

        $scn['tenant']->run(function () use ($contributionId): void {
            $fresh = ProjectContribution::findOrFail($contributionId);
            $this->assertSame(0, (int) $fresh->getAttributes()['contributed_amount']);
            $this->assertSame(ProjectContributionStatus::PENDING, $fresh->project_contribution_status_id);
        });
    }

    // =========================================================================
    // Restricted update contract — structural/money fields frozen (2026-07)
    // =========================================================================

    public function test_editable_fields_can_be_patched_after_creation(): void
    {
        $scn = $this->residentScenario();
        $admin = $this->makeUser($scn['tenant']);
        $adminToken = $this->adminToken($admin);

        $project = $this->makeProject($scn['tenant'], [], activate: false);

        $this->tenantRequest($adminToken, $scn['tenant'])
            ->patchJson("/api/community_project/{$project->id}", [
                'title' => 'Pintura de fachadas 2026',
                'description_html' => '<p>Nueva <strong>descripcion</strong> del proyecto</p>',
                'payments_visible_to_residents' => true,
            ])
            ->assertStatus(200);

        $scn['tenant']->run(function () use ($project): void {
            $fresh = CommunityProject::findOrFail($project->id);
            $this->assertSame('Pintura de fachadas 2026', $fresh->title);
            $this->assertStringContainsString('strong', (string) $fresh->description_html);
            // The plain-text description is derived from the rich HTML.
            $this->assertStringContainsString('descripcion', (string) $fresh->description);
            $this->assertStringNotContainsString('<', (string) $fresh->description);
            $this->assertTrue((bool) $fresh->payments_visible_to_residents);
        });
    }

    public function test_structural_fields_cannot_be_patched_after_creation(): void
    {
        $scn = $this->residentScenario();
        $admin = $this->makeUser($scn['tenant']);
        $adminToken = $this->adminToken($admin);

        $project = $this->makeProject($scn['tenant'], [], activate: false);

        $frozenMessage = 'Este campo no es editable después de crear el proyecto.';

        $this->tenantRequest($adminToken, $scn['tenant'])
            ->patchJson("/api/community_project/{$project->id}", [
                'goal_amount' => 5000000,
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.goal_amount.0', $frozenMessage);

        $this->tenantRequest($adminToken, $scn['tenant'])
            ->patchJson("/api/community_project/{$project->id}", [
                'participation_type_id' => ParticipationType::MANDATORY,
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.participation_type_id.0', $frozenMessage);

        $scn['tenant']->run(function () use ($project): void {
            $fresh = CommunityProject::findOrFail($project->id);
            $this->assertSame(1000000, (int) $fresh->getAttributes()['goal_amount']);
            $this->assertSame(ParticipationType::VOLUNTARY, $fresh->participation_type_id);
        });
    }

    // =========================================================================
    // Visibility — projects-05
    // =========================================================================

    public function test_residents_do_not_see_drafts_or_hidden_projects(): void
    {
        $scn = $this->residentScenario();

        $visible = $this->makeProject($scn['tenant']);
        $hidden = $this->makeProject($scn['tenant'], ['visible_to_residents' => false]);
        $draft = $this->makeProject($scn['tenant'], [], activate: false);

        $ids = collect(
            $this->tenantRequest($scn['token'], $scn['tenant'])
                ->getJson('/api/community_project')
                ->assertStatus(200)
                ->json('data')
        )->pluck('id');

        $this->assertTrue($ids->contains($visible->id));
        $this->assertFalse($ids->contains($hidden->id), '§11.8.7: hidden projects must not be listed for residents');
        $this->assertFalse($ids->contains($draft->id), 'drafts must not be listed for residents');

        $this->tenantRequest($scn['token'], $scn['tenant'])
            ->getJson("/api/community_project/{$hidden->id}")
            ->assertStatus(404);

        // Board/admin keeps seeing everything.
        $admin = $this->makeUser($scn['tenant']);
        $adminIds = collect(
            $this->tenantRequest($this->adminToken($admin), $scn['tenant'])
                ->getJson('/api/community_project')
                ->assertStatus(200)
                ->json('data')
        )->pluck('id');

        $this->assertTrue($adminIds->contains($hidden->id));
        $this->assertTrue($adminIds->contains($draft->id));
    }

    // =========================================================================
    // Comments — projects-07 / projects-08 / projects-09
    // =========================================================================

    public function test_comment_author_cannot_be_spoofed_and_is_stamped_from_auth(): void
    {
        $scn = $this->residentScenario();
        $project = $this->makeProject($scn['tenant']);
        $victim = $this->makeUser($scn['tenant']);

        // Sending user_id (anyone's) is rejected outright.
        $this->tenantRequest($scn['token'], $scn['tenant'])
            ->postJson('/api/project_comment', [
                'community_project_id' => $project->id,
                'body' => 'Comentario suplantado',
                'user_id' => $victim->id,
            ])
            ->assertStatus(422);

        // Sending hidden is rejected too (moderation only via /hide).
        $this->tenantRequest($scn['token'], $scn['tenant'])
            ->postJson('/api/project_comment', [
                'community_project_id' => $project->id,
                'body' => 'Comentario oculto de fábrica',
                'hidden' => true,
            ])
            ->assertStatus(422);

        // A clean comment is stamped with the AUTHENTICATED author.
        $created = $this->tenantRequest($scn['token'], $scn['tenant'])
            ->postJson('/api/project_comment', [
                'community_project_id' => $project->id,
                'body' => 'Comentario legítimo',
            ])
            ->assertStatus(201)
            ->json('data');

        $scn['tenant']->run(function () use ($created, $scn): void {
            $comment = ProjectComment::findOrFail($created['id']);
            $this->assertSame($scn['resident']->id, $comment->user_id);
            $this->assertFalse((bool) $comment->hidden);
        });
    }

    public function test_comment_validation_enforces_allow_comments_and_same_project_parent(): void
    {
        $scn = $this->residentScenario();

        $noComments = $this->makeProject($scn['tenant'], ['allow_comments' => false]);
        $this->tenantRequest($scn['token'], $scn['tenant'])
            ->postJson('/api/project_comment', [
                'community_project_id' => $noComments->id,
                'body' => 'No debería entrar',
            ])
            ->assertStatus(422);

        $projectA = $this->makeProject($scn['tenant']);
        $projectB = $this->makeProject($scn['tenant']);

        $parentId = null;
        $scn['tenant']->run(function () use ($projectA, $scn, &$parentId): void {
            $parentId = ProjectComment::create([
                'community_project_id' => $projectA->id,
                'user_id' => $scn['resident']->id,
                'body' => 'Padre',
                'hidden' => false,
            ])->id;
        });

        $this->tenantRequest($scn['token'], $scn['tenant'])
            ->postJson('/api/project_comment', [
                'community_project_id' => $projectB->id,
                'parent_id' => $parentId,
                'body' => 'Hilo cruzado',
            ])
            ->assertStatus(422);
    }

    public function test_hidden_comments_are_invisible_to_residents_but_visible_to_moderators(): void
    {
        $scn = $this->residentScenario();
        $project = $this->makeProject($scn['tenant']);

        $visibleId = null;
        $hiddenId = null;
        $scn['tenant']->run(function () use ($project, $scn, &$visibleId, &$hiddenId): void {
            $visibleId = ProjectComment::create([
                'community_project_id' => $project->id,
                'user_id' => $scn['resident']->id,
                'body' => 'Comentario visible',
                'hidden' => false,
            ])->id;

            $hiddenId = ProjectComment::create([
                'community_project_id' => $project->id,
                'user_id' => $scn['resident']->id,
                'body' => 'Comentario moderado',
                'hidden' => true,
            ])->id;
        });

        $residentIds = collect(
            $this->tenantRequest($scn['token'], $scn['tenant'])
                ->getJson('/api/project_comment')
                ->assertStatus(200)
                ->json('data')
        )->pluck('id');

        $this->assertTrue($residentIds->contains($visibleId));
        $this->assertFalse($residentIds->contains($hiddenId), '§11.8.8: moderated comments must not reach residents');

        $this->tenantRequest($scn['token'], $scn['tenant'])
            ->getJson("/api/project_comment/{$hiddenId}")
            ->assertStatus(404);

        $moderator = $this->makeUser($scn['tenant']);
        $moderatorIds = collect(
            $this->tenantRequest($this->adminToken($moderator), $scn['tenant'])
                ->getJson('/api/project_comment')
                ->assertStatus(200)
                ->json('data')
        )->pluck('id');

        $this->assertTrue($moderatorIds->contains($hiddenId));
    }

    // =========================================================================
    // Decline guards — projects-04
    // =========================================================================

    public function test_decline_blocked_on_mandatory_and_no_decline_projects(): void
    {
        $scn = $this->residentScenario();
        $admin = $this->makeUser($scn['tenant']);
        $adminToken = $this->adminToken($admin);

        $mandatory = $this->makeProject($scn['tenant'], [
            'participation_type_id' => ParticipationType::MANDATORY,
            'amount_mode_id' => AmountMode::FIXED_REQUIRED,
            'fixed_amount' => 50000,
            'min_amount' => null,
            'ideal_amount' => null,
            'target_type' => 'single_unit',
            'target_selector' => ['unit_id' => $scn['unit']->id],
        ]);

        $noDecline = $this->makeProject($scn['tenant'], ['allow_decline' => false]);
        $declinable = $this->makeProject($scn['tenant']);

        $contributionId = fn (CommunityProject $project) => $scn['tenant']->run(
            fn () => ProjectContribution::where('community_project_id', $project->id)
                ->where('unit_id', $scn['unit']->id)
                ->value('id')
        );

        $this->tenantRequest($adminToken, $scn['tenant'])
            ->postJson('/api/project-contribution/'.$contributionId($mandatory).'/decline')
            ->assertStatus(422);

        $this->tenantRequest($adminToken, $scn['tenant'])
            ->postJson('/api/project-contribution/'.$contributionId($noDecline).'/decline')
            ->assertStatus(422);

        $this->tenantRequest($adminToken, $scn['tenant'])
            ->postJson('/api/project-contribution/'.$contributionId($declinable).'/decline')
            ->assertStatus(200);
    }

    // =========================================================================
    // Cancel + treasurer edit rules — projects-12
    // =========================================================================

    public function test_cancel_requires_dedicated_ability_and_respects_fsm(): void
    {
        $scn = $this->residentScenario();

        $writer = $this->makeUser($scn['tenant']);
        $writerToken = $this->residentToken($writer, ['read.community_project', 'update.community_project']);

        $president = $this->makeUser($scn['tenant']);
        $presidentToken = $this->residentToken($president, ['read.community_project', 'update.community_project', 'cancel.community_project']);

        $project = $this->makeProject($scn['tenant']);

        // §11.2: a writer WITHOUT cancel.community_project (treasurer) cannot cancel.
        $this->tenantRequest($writerToken, $scn['tenant'])
            ->postJson("/api/community-project/{$project->id}/cancel")
            ->assertStatus(403);

        $this->tenantRequest($presidentToken, $scn['tenant'])
            ->postJson("/api/community-project/{$project->id}/cancel")
            ->assertStatus(200);

        $scn['tenant']->run(function () use ($project): void {
            $this->assertSame(
                CommunityProjectStatus::CANCELLED,
                CommunityProject::findOrFail($project->id)->community_project_status_id
            );
        });

        // FSM: executed projects cannot be cancelled.
        $executed = $this->makeProject($scn['tenant']);
        $scn['tenant']->run(function () use ($executed): void {
            app(ProjectActivationService::class)->execute($executed->refresh());
        });

        $this->tenantRequest($presidentToken, $scn['tenant'])
            ->postJson("/api/community-project/{$executed->id}/cancel")
            ->assertStatus(422);
    }

    public function test_writer_without_cancel_ability_edits_only_own_projects_without_money(): void
    {
        $scn = $this->residentScenario();

        $treasurer = $this->makeUser($scn['tenant']);
        $treasurerToken = $this->residentToken($treasurer, ['read.community_project', 'create.community_project', 'update.community_project']);

        $other = $this->makeUser($scn['tenant']);
        $otherToken = $this->residentToken($other, ['read.community_project', 'create.community_project', 'update.community_project']);

        // The treasurer creates a project over HTTP (stamps user_created_id).
        $created = $this->tenantRequest($treasurerToken, $scn['tenant'])
            ->postJson('/api/community_project', [
                'title' => 'Proyecto del tesorero',
                'community_project_category_id' => Catalog::id(CommunityProjectCategory::class, 'Obra'),
                'participation_type_id' => ParticipationType::VOLUNTARY,
                'amount_mode_id' => AmountMode::FREE,
                'target_type' => 'all',
                'goal_amount' => 100000,
            ])
            ->assertStatus(201)
            ->json('data');

        // Own project, no money yet → editable.
        $this->tenantRequest($treasurerToken, $scn['tenant'])
            ->patchJson("/api/community_project/{$created['id']}", ['description' => 'Ajuste propio'])
            ->assertStatus(200);

        // Another writer without cancel.community_project cannot edit it (§11.2 ⚠️).
        $this->tenantRequest($otherToken, $scn['tenant'])
            ->patchJson("/api/community_project/{$created['id']}", ['description' => 'Ajuste ajeno'])
            ->assertStatus(403);

        // Once money exists, even the creator cannot edit anymore.
        $scn['tenant']->run(function () use ($created, $scn): void {
            $project = CommunityProject::findOrFail($created['id']);
            app(ProjectActivationService::class)->activate($project);

            $contribution = ProjectContribution::where('community_project_id', $project->id)
                ->where('unit_id', $scn['unit']->id)
                ->firstOrFail();
            app(ContributionService::class)->contribute($contribution, 10000);
        });

        $this->tenantRequest($treasurerToken, $scn['tenant'])
            ->patchJson("/api/community_project/{$created['id']}", ['description' => 'Ya no se puede'])
            ->assertStatus(403);
    }

    // =========================================================================
    // Hidden projects never notify residents — projects-06
    // =========================================================================

    public function test_activating_hidden_project_does_not_notify_residents(): void
    {
        $scn = $this->residentScenario();

        $this->makeProject($scn['tenant'], ['visible_to_residents' => false]);

        $scn['tenant']->run(function (): void {
            $this->assertSame(
                0,
                Notification::where('title', 'Nuevo proyecto comunitario')->count(),
                '§11.8.7: hidden projects must not push activation notifications'
            );
        });

        $this->makeProject($scn['tenant']); // visible → notifies the titular

        $scn['tenant']->run(function (): void {
            $this->assertGreaterThan(
                0,
                Notification::where('title', 'Nuevo proyecto comunitario')->count(),
                'visible projects must keep notifying the target units'
            );
        });
    }
}
