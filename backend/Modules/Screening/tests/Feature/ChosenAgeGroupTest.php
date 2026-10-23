<?php

declare(strict_types=1);

namespace Modules\Screening\Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Instrument\Models\AgeGroup;
use Modules\Instrument\Models\InstrumentVersion;
use Modules\Screening\Models\Screening;
use Modules\Screening\Models\ScreeningScoreRun;
use Modules\Screening\Models\ScreeningStatus;
use Modules\Screening\Tests\Support\WithScreeningFixtures;
use Modules\Tenant\Models\Tenant;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * S2 (odd/tasks/edi-legacy-parity.md) — "Prueba EDI a elección": the evaluator
 * picks the group to apply instead of the age-derived one. The server persists
 * the choice, scores with THAT group's rules and keeps it on rescore.
 */
final class ChosenAgeGroupTest extends TestCase
{
    use RefreshDatabase;
    use WithScreeningFixtures;
    use WithTenantSetup;

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    private function groupId(InstrumentVersion $version, string $code): string
    {
        return AgeGroup::where('instrument_version_id', $version->id)->where('code', $code)->value('id');
    }

    /**
     * @return array{0: Tenant, 1: string}
     */
    private function adminTenant(): array
    {
        $this->seedPlatformRegistry();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        return [$tenant, $this->administratorToken($tenant)];
    }

    public function test_chosen_group_is_persisted_with_its_mode_and_the_chronological_group_still_resolved(): void
    {
        [$tenant, $token] = $this->adminTenant();
        $version = $this->defaultVersion();

        // 400 days -> G8 by age; the evaluator chooses G10.
        $fx = $this->inTenant($tenant, fn () => $this->makeChildVisit(400));

        $response = $this->tenantRequest($token, $tenant)
            ->postJson('/api/screening', [
                'visit_id' => $fx['visit']->id,
                'age_group_id' => $this->groupId($version, 'G10'),
            ])
            ->assertCreated();

        $response->assertJsonPath('data.age_group_selection_mode', Screening::SELECTION_MODE_CHOSEN);
        $response->assertJsonPath('data.applied_age_group_id', $this->groupId($version, 'G10'));
        $response->assertJsonPath('data.age_group_id', $this->groupId($version, 'G8'));
        $response->assertJsonPath('data.applied_age_group.code', 'G10');
        $response->assertJsonPath('item_bank.applied_age_group_code', 'G10');
        $response->assertJsonPath('data.chronological_age_days', 400);

        $this->inTenant($tenant, function () use ($fx) {
            $screening = Screening::where('visit_id', $fx['visit']->id)->firstOrFail();
            $this->assertSame(Screening::SELECTION_MODE_CHOSEN, $screening->age_group_selection_mode);
        });
    }

    public function test_chosen_mode_records_the_group_the_corrected_age_would_have_applied(): void
    {
        [$tenant, $token] = $this->adminTenant();
        $version = $this->defaultVersion();

        // 100 days born at 32 weeks: chronological G3, corrected 44 days -> G1.
        $fx = $this->inTenant($tenant, fn () => $this->makeChildVisit(100, ['gestational_age_weeks' => 32]));

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/screening', [
                'visit_id' => $fx['visit']->id,
                'age_group_id' => $this->groupId($version, 'G10'),
            ])
            ->assertCreated()
            ->assertJsonPath('data.age_group.code', 'G3')
            ->assertJsonPath('data.age_derived_age_group_id', $this->groupId($version, 'G1'))
            ->assertJsonPath('data.age_derived_age_group.code', 'G1')
            ->assertJsonPath('data.applied_age_group.code', 'G10');
    }

    public function test_chosen_mode_for_a_term_child_records_the_chronological_group_as_age_derived(): void
    {
        [$tenant, $token] = $this->adminTenant();
        $version = $this->defaultVersion();
        $fx = $this->inTenant($tenant, fn () => $this->makeChildVisit(400));

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/screening', [
                'visit_id' => $fx['visit']->id,
                'age_group_id' => $this->groupId($version, 'G10'),
            ])
            ->assertCreated()
            ->assertJsonPath('data.age_derived_age_group_id', $this->groupId($version, 'G8'));
    }

    public function test_age_mode_records_the_applied_group_as_age_derived(): void
    {
        [$tenant, $token] = $this->adminTenant();
        $version = $this->defaultVersion();
        $fx = $this->inTenant($tenant, fn () => $this->makeChildVisit(100, ['gestational_age_weeks' => 32]));

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/screening', ['visit_id' => $fx['visit']->id])
            ->assertCreated()
            ->assertJsonPath('data.applied_age_group_id', $this->groupId($version, 'G1'))
            ->assertJsonPath('data.age_derived_age_group_id', $this->groupId($version, 'G1'));
    }

    public function test_first_score_uses_the_chosen_group_rules_and_rescore_keeps_it(): void
    {
        [$tenant, $token] = $this->adminTenant();
        $version = $this->defaultVersion();

        $fx = $this->inTenant($tenant, fn () => $this->makeChildVisit(400));

        $screeningId = $this->tenantRequest($token, $tenant)
            ->postJson('/api/screening', [
                'visit_id' => $fx['visit']->id,
                'age_group_id' => $this->groupId($version, 'G10'),
            ])
            ->assertCreated()
            ->json('data.id');

        $this->tenantRequest($token, $tenant)
            ->putJson("/api/screening/{$screeningId}/answer", ['answers' => $this->g10RedAnswers($version)])
            ->assertOk();

        // Before the fix this hit the date tripwire (G8 by age != stored G10) -> 500.
        $this->tenantRequest($token, $tenant)
            ->postJson("/api/screening/{$screeningId}/score")
            ->assertOk()
            ->assertJsonPath('data.screening_status_id', ScreeningStatus::SCORED)
            ->assertJsonPath('data.result_color.key', 'red')
            ->assertJsonPath('data.applied_age_group.code', 'G10');

        $this->tenantRequest($token, $tenant)
            ->postJson("/api/screening/{$screeningId}/rescore", ['authoritative' => true, 'reason' => 'Revisión'])
            ->assertOk()
            ->assertJsonPath('data.applied_age_group.code', 'G10')
            ->assertJsonPath('data.age_group_selection_mode', Screening::SELECTION_MODE_CHOSEN);

        $this->inTenant($tenant, function () use ($screeningId) {
            $runs = ScreeningScoreRun::where('screening_id', $screeningId)->orderBy('created_at')->get();
            $this->assertCount(2, $runs);
            foreach ($runs as $run) {
                $this->assertSame('G10', $run->input_payload['applied_age_group_code']);
            }
        });
    }

    public function test_document_shows_the_group_number_and_the_chosen_mode(): void
    {
        [$tenant, $token] = $this->adminTenant();
        $version = $this->defaultVersion();
        $fx = $this->inTenant($tenant, fn () => $this->makeChildVisit(400));

        $screeningId = $this->tenantRequest($token, $tenant)
            ->postJson('/api/screening', [
                'visit_id' => $fx['visit']->id,
                'age_group_id' => $this->groupId($version, 'G10'),
            ])
            ->assertCreated()
            ->json('data.id');
        $this->tenantRequest($token, $tenant)
            ->putJson("/api/screening/{$screeningId}/answer", ['answers' => $this->g10GreenAnswers($version)])
            ->assertOk();
        $this->tenantRequest($token, $tenant)->postJson("/api/screening/{$screeningId}/score")->assertOk();

        $this->tenantRequest($token, $tenant)->get("/api/screening/{$screeningId}/document")->assertOk();

        $html = $this->inTenant($tenant, function () use ($screeningId) {
            $screening = Screening::with(['child.sex', 'visit.health_center', 'visit.practitioner.user', 'instrument_version',
                'applied_age_group', 'result_color', 'global_result_message', 'situation', 'screening_status',
                'area_results.development_area', 'area_results.result_color'])->findOrFail($screeningId);

            return view('screening::document', ['screening' => $screening])->render();
        });

        $this->assertStringContainsString('Grupo: 10 — GRUPO 10 (19 a 24 meses)', $html);
        $this->assertStringContainsString('Prueba EDI a elección', $html);
    }

    public function test_alternate_group_is_rejected(): void
    {
        [$tenant, $token] = $this->adminTenant();
        $version = $this->defaultVersion();
        $fx = $this->inTenant($tenant, fn () => $this->makeChildVisit(400));

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/screening', [
                'visit_id' => $fx['visit']->id,
                'age_group_id' => $this->groupId($version, 'G9A'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['age_group_id']);

        $this->inTenant($tenant, fn () => $this->assertSame(0, Screening::count()));
    }

    public function test_group_of_another_instrument_version_is_rejected(): void
    {
        [$tenant, $token] = $this->adminTenant();
        $otherVersion = InstrumentVersion::where('is_default', false)->firstOrFail();
        $fx = $this->inTenant($tenant, fn () => $this->makeChildVisit(400));

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/screening', [
                'visit_id' => $fx['visit']->id,
                'age_group_id' => $this->groupId($otherVersion, 'G10'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['age_group_id']);
    }

    /**
     * Assumption (S2): the chosen group does not lift the 1-71 month window of
     * the test; the child's real age is still validated.
     */
    public function test_chosen_group_still_refuses_an_out_of_range_child(): void
    {
        [$tenant, $token] = $this->adminTenant();
        $version = $this->defaultVersion();
        $fx = $this->inTenant($tenant, fn () => $this->makeChildVisit(2200));

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/screening', [
                'visit_id' => $fx['visit']->id,
                'age_group_id' => $this->groupId($version, 'G15'),
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'SCREENING_NOT_APPLICABLE');
    }

    public function test_without_a_chosen_group_the_mode_is_age(): void
    {
        [$tenant, $token] = $this->adminTenant();
        $fx = $this->inTenant($tenant, fn () => $this->makeChildVisit(570));

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/screening', ['visit_id' => $fx['visit']->id])
            ->assertCreated()
            ->assertJsonPath('data.age_group_selection_mode', Screening::SELECTION_MODE_AGE)
            ->assertJsonPath('data.applied_age_group.code', 'G10');
    }

    public function test_age_groups_endpoint_lists_the_15_official_groups_of_the_default_version(): void
    {
        [$tenantA] = $this->twoTenants();
        $this->seedPlatformRegistry();

        $role = Role::create(['name' => 'applier-'.Str::random(8), 'guard_name' => 'api']);
        $role->givePermissionTo(Permission::findOrCreate('create.screening', 'api'));
        $user = $this->makeUser($tenantA);
        $user->assignRole($role->name);
        $token = $user->createToken('applier', ['create.screening'])->plainTextToken;

        $response = $this->tenantRequest($token, $tenantA)
            ->getJson('/api/screening/age-groups')
            ->assertOk();

        $groups = $response->json('data');
        $this->assertCount(15, $groups);
        $this->assertSame('G1', $groups[0]['code']);
        $this->assertSame('G15', $groups[14]['code']);
        $this->assertSame(1, $groups[0]['ordinal']);
        $this->assertSame(570, $groups[9]['min_age_days']);
        $this->assertSame(19, $groups[9]['min_age_months']);
        $this->assertSame(24, $groups[9]['max_age_months']);
        $this->assertArrayHasKey('materials_note', $groups[0]);
        $this->assertArrayHasKey('name', $groups[0]);
    }

    public function test_age_groups_endpoint_requires_create_screening(): void
    {
        [$tenantA] = $this->twoTenants();
        $this->seedPlatformRegistry();

        $user = $this->makeUser($tenantA);
        $token = $user->createToken('nobody', [])->plainTextToken;

        $this->tenantRequest($token, $tenantA)
            ->getJson('/api/screening/age-groups')
            ->assertStatus(403);
    }
}
