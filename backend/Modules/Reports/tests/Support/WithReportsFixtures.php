<?php

declare(strict_types=1);

namespace Modules\Reports\Tests\Support;

use App\Models\Role;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\ChildRecord\Models\Child;
use Modules\ChildRecord\Models\Visit;
use Modules\Clinic\Database\Factories\HealthCenterFactory;
use Modules\Clinic\Models\HealthCenter;
use Modules\Clinic\Models\PractitionerProfile;
use Modules\Instrument\Models\AgeGroup;
use Modules\Instrument\Models\InstrumentVersion;
use Modules\Instrument\Models\ResultColor;
use Modules\Instrument\Models\ScreeningItem;
use Modules\Instrument\Models\ScreeningItemOption;
use Modules\Screening\Tests\Support\WithScreeningFixtures;
use Modules\Tenant\Models\Tenant;
use Tests\Support\WithTenantSetup;

/**
 * Shared fixtures for edi-reports-and-indicators feature tests: reuses the
 * Screening module's fixtures (platform seeding, child/visit builders, G10
 * answer sets) and adds the full apply-answer-score-complete API flow so
 * snapshot/indicator tests can build REAL scored screenings, not hand-forged
 * rows.
 */
trait WithReportsFixtures
{
    use WithScreeningFixtures;
    use WithTenantSetup;

    /**
     * Administrator token for a tenant, plus the tenant itself.
     *
     * @return array{0: Tenant, 1: string}
     */
    protected function administeredTenant(string $name = 'Test Organization'): array
    {
        $tenant = $this->makeTenant($name);
        $this->seedTenant($tenant);

        return [$tenant, $this->administratorToken($tenant)];
    }

    /**
     * Full apply-answer-score-complete flow via the real API, returning the
     * completed screening's id.
     *
     * @param  array<int, array{screening_item_id: string, screening_item_option_id: string}>  $answers
     */
    protected function applyAndComplete(string $token, Tenant $tenant, Visit $visit, array $answers): string
    {
        $screeningId = $this->tenantRequest($token, $tenant)
            ->postJson('/api/screening', ['visit_id' => $visit->id])
            ->assertCreated()
            ->json('data.id');

        $this->tenantRequest($token, $tenant)
            ->putJson("/api/screening/{$screeningId}/answer", ['answers' => $answers])
            ->assertOk();

        $this->tenantRequest($token, $tenant)
            ->postJson("/api/screening/{$screeningId}/score")
            ->assertOk();

        $this->tenantRequest($token, $tenant)
            ->postJson("/api/screening/{$screeningId}/complete")
            ->assertOk();

        return $screeningId;
    }

    /**
     * Creates a Visit for an EXISTING child at a given visit_date (unlike
     * WithScreeningFixtures::makeChildVisit, which always dates today).
     *
     * NOTE: the child's birth_date must already be set relative to
     * $visitDate for the applied age group to match what the caller
     * expects (see makeChildVisitAt for a same-group-guaranteed builder).
     */
    protected function makeVisitFor(Child $child, string $visitDate): Visit
    {
        $healthCenter = $child->health_center;
        $practitioner = PractitionerProfile::factory()->create([
            'health_center_id' => $healthCenter->id,
        ]);

        return Visit::factory()->create([
            'child_id' => $child->id,
            'health_center_id' => $healthCenter->id,
            'practitioner_id' => $practitioner->id,
            'visit_date' => $visitDate,
        ]);
    }

    /**
     * Builds a Child + Visit whose chronological age at $visitDate is
     * exactly $ageDays -- unlike WithScreeningFixtures::makeChildVisit
     * (always ages relative to TODAY, then dating the visit elsewhere would
     * silently shift the resolved age group and break "reactivos no
     * pertenecen al grupo").
     *
     * @return array{child: Child, visit: Visit, healthCenter: HealthCenter}
     */
    protected function makeChildVisitAt(int $ageDays, string $visitDate): array
    {
        $healthCenter = HealthCenter::factory()->create([
            'municipality_id' => HealthCenterFactory::defaultMunicipality()->id,
        ]);

        $birthDate = Carbon::parse($visitDate)->subDays($ageDays)->startOfDay();

        $child = Child::factory()->create([
            'health_center_id' => $healthCenter->id,
            'birth_date' => $birthDate->format('Y-m-d'),
        ]);

        $visit = $this->makeVisitFor($child, $visitDate);

        return ['child' => $child, 'visit' => $visit, 'healthCenter' => $healthCenter];
    }

    /**
     * Role holding ONLY the given permission names -- for boundary tests
     * that need a user who is NOT the blanket administrator (REQ-RPT-006/008
     * scenarios: "a user holding ONLY read.screening_indicator").
     *
     * @param  string[]  $permissionNames
     */
    protected function tokenWithOnly(Tenant $tenant, array $permissionNames): string
    {
        $roleName = 'reports-test-role-'.Str::random(8);

        // QCH\Core\Models\Role (used elsewhere in this trait for its slug
        // constants) is a plain read-model with NO Spatie behavior --
        // givePermissionTo() lives on App\Models\Role (extends
        // Spatie\Permission\Models\Role), the model $user->assignRole()
        // actually resolves against.
        $role = Role::create([
            'name' => $roleName,
            'guard_name' => 'api',
        ]);

        $role->givePermissionTo($permissionNames);

        $user = $this->makeUser($tenant);
        $user->assignRole($roleName);

        return $user->createToken('scoped', $permissionNames)->plainTextToken;
    }

    protected function defaultInstrumentVersion(): InstrumentVersion
    {
        return $this->defaultVersion();
    }

    /**
     * Token carrying exactly these Sanctum ABILITIES (no role assignment) --
     * for `/api/customReport` boundary tests, whose permission check is
     * `$user->tokenCan($ability)` (ReportController::checkPermission), NOT
     * the Spatie role-based `permission` route middleware.
     *
     * @param  string[]  $abilities
     */
    protected function tokenWithAbilities(Tenant $tenant, array $abilities): string
    {
        $user = $this->makeUser($tenant);

        return $user->createToken('export-scoped', $abilities)->plainTextToken;
    }

    /**
     * G10 answers scoring a clean YELLOW global result (MG axis gets exactly
     * one red + one yellow + one own item, not all-yellow so it never
     * triggers the alternate gate; every other area stays green).
     *
     * @return array<int, array{screening_item_id: string, screening_item_option_id: string}>
     */
    protected function g10YellowAnswers(InstrumentVersion $version): array
    {
        return [
            $this->answerByColor($version, 'G10', 'MG10.1', 'yellow'),
            $this->answerByColor($version, 'G10', 'MG10.2', 'yellow'),
            $this->answerFor($version, 'G10', 'MG10.3', 'SI'),
            $this->answerFor($version, 'G10', 'MF10.1', 'SI'),
            $this->answerFor($version, 'G10', 'MF10.2', 'SI'),
            $this->answerFor($version, 'G10', 'MF10.3', 'SI'),
            $this->answerFor($version, 'G10', 'LE10.1', 'SI'),
            $this->answerFor($version, 'G10', 'LE10.2', 'SI'),
            $this->answerFor($version, 'G10', 'LE10.3', 'SI'),
            $this->answerFor($version, 'G10', 'SO10.1', 'SI'),
            $this->answerFor($version, 'G10', 'SO10.2', 'SI'),
            $this->answerFor($version, 'G10', 'SO10.3', 'SI'),
            $this->answerFor($version, 'G10', 'EN10.1', 'NO'),
            $this->answerFor($version, 'G10', 'EN10.2', 'NO'),
            $this->answerFor($version, 'G10', 'EN10.3', 'NO'),
            $this->answerFor($version, 'G10', 'ALA10.1', 'NO'),
            $this->answerFor($version, 'G10', 'ALA10.2', 'NO'),
            $this->answerFor($version, 'G10', 'ALE10.1', 'NO'),
            $this->answerFor($version, 'G10', 'ALE10.2', 'NO'),
            $this->answerFor($version, 'G10', 'ALE10.3', 'NO'),
        ];
    }

    /**
     * Resolves the option of $itemCode whose result_color matches $colorKey
     * (green/yellow/red), independent of the item's specific label text.
     *
     * @return array{screening_item_id: string, screening_item_option_id: string}
     */
    protected function answerByColor(InstrumentVersion $version, string $groupCode, string $itemCode, string $colorKey): array
    {
        $group = AgeGroup::where('instrument_version_id', $version->id)->where('code', $groupCode)->firstOrFail();
        $item = ScreeningItem::where('age_group_id', $group->id)->where('code', $itemCode)->firstOrFail();

        $colorId = ResultColor::where('key', $colorKey)->value('id');

        $option = ScreeningItemOption::where('screening_item_id', $item->id)
            ->where('result_color_id', $colorId)
            ->firstOrFail();

        return ['screening_item_id' => $item->id, 'screening_item_option_id' => $option->id];
    }
}
