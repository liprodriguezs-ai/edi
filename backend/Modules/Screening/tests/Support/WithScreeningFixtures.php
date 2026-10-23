<?php

declare(strict_types=1);

namespace Modules\Screening\Tests\Support;

use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\ModuleTableSeeder;
use Database\Seeders\PermissionsTableSeeder;
use Database\Seeders\RolePermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Database\Seeders\SystemTableSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\ChildRecord\Models\BirthRecord;
use Modules\ChildRecord\Models\Child;
use Modules\ChildRecord\Models\Visit;
use Modules\Clinic\Database\Factories\HealthCenterFactory;
use Modules\Clinic\Models\HealthCenter;
use Modules\Clinic\Models\PractitionerProfile;
use Modules\Instrument\Database\Seeders\InstrumentSeeder;
use Modules\Instrument\Models\AgeGroup;
use Modules\Instrument\Models\InstrumentVersion;
use Modules\Instrument\Models\RiskFactor;
use Modules\Instrument\Models\ScreeningItem;
use Modules\Instrument\Models\ScreeningItemOption;
use Modules\Tenant\Models\Tenant;
use QCH\Core\Models\Role;

/**
 * Shared fixtures for edi-screening-application feature tests: platform
 * registration (once, central), tenant-scoped child/visit clinical
 * fixtures, and item-bank lookups by group/item code so answer payloads
 * are built from the REAL seeded content (edi-2022-2ed, the published
 * default) instead of hand-guessed UUIDs.
 */
trait WithScreeningFixtures
{
    protected function seedPlatformRegistry(): void
    {
        (new CatalogSeeder)->run();
        (new InstrumentSeeder)->run();
        Artisan::call('db:seed', ['--class' => ModuleTableSeeder::class, '--force' => true]);
        Artisan::call('db:seed', ['--class' => SystemTableSeeder::class, '--force' => true]);
        Artisan::call('db:seed', ['--class' => PermissionsTableSeeder::class, '--force' => true]);
        Artisan::call('db:seed', ['--class' => RolesTableSeeder::class, '--force' => true]);
        Artisan::call('db:seed', ['--class' => RolePermissionTableSeeder::class, '--force' => true]);
    }

    protected function defaultVersion(): InstrumentVersion
    {
        return InstrumentVersion::where('is_default', true)->whereNotNull('published_at')->firstOrFail();
    }

    /**
     * Builds a Child + Visit whose chronological age (calendar mode, the
     * default version) is exactly $ageDays at visit_date = today.
     *
     * @return array{child: Child, visit: Visit, healthCenter: HealthCenter, practitioner: PractitionerProfile, birthRecord: ?BirthRecord}
     */
    protected function makeChildVisit(int $ageDays, ?array $birthRecordAttributes = null, array $childAttributes = []): array
    {
        $healthCenter = HealthCenter::factory()->create([
            'municipality_id' => HealthCenterFactory::defaultMunicipality()->id,
        ]);

        $birthDate = now()->subDays($ageDays)->startOfDay();

        $child = Child::factory()->create([
            'health_center_id' => $healthCenter->id,
            'birth_date' => $birthDate->format('Y-m-d'),
            ...$childAttributes,
        ]);

        $birthRecord = null;
        if ($birthRecordAttributes !== null) {
            $birthRecord = BirthRecord::factory()->create([...$birthRecordAttributes, 'child_id' => $child->id]);
        }

        $practitioner = PractitionerProfile::factory()->create(['health_center_id' => $healthCenter->id]);

        $visit = Visit::factory()->create([
            'child_id' => $child->id,
            'health_center_id' => $healthCenter->id,
            'practitioner_id' => $practitioner->id,
            'visit_date' => now()->format('Y-m-d'),
        ]);

        return compact('child', 'visit', 'healthCenter', 'practitioner', 'birthRecord');
    }

    /**
     * @return array{screening_item_id: string, screening_item_option_id: string}
     */
    protected function answerFor(InstrumentVersion $version, string $groupCode, string $itemCode, string $optionLabel): array
    {
        $group = AgeGroup::where('instrument_version_id', $version->id)->where('code', $groupCode)->firstOrFail();
        $item = ScreeningItem::where('age_group_id', $group->id)->where('code', $itemCode)->firstOrFail();
        $option = ScreeningItemOption::where('screening_item_id', $item->id)->where('label', $optionLabel)->firstOrFail();

        return ['screening_item_id' => $item->id, 'screening_item_option_id' => $option->id];
    }

    /**
     * All own G10 items answered green/no-alert, except EN10.1 = SI (red) so
     * the global result is deterministically red without ever triggering
     * the alternate gate (only ONE red area, no all-yellow axis).
     *
     * @return array<int, array{screening_item_id: string, screening_item_option_id: string}>
     */
    protected function g10RedAnswers(InstrumentVersion $version): array
    {
        return [
            $this->answerFor($version, 'G10', 'MG10.1', 'SI'),
            $this->answerFor($version, 'G10', 'MG10.2', 'SI'),
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
            $this->answerFor($version, 'G10', 'EN10.1', 'SI'),
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
     * All G10 own items answered to their green option -- a clean green
     * global result with no alternate trigger.
     *
     * @return array<int, array{screening_item_id: string, screening_item_option_id: string}>
     */
    protected function g10GreenAnswers(InstrumentVersion $version): array
    {
        return [
            $this->answerFor($version, 'G10', 'MG10.1', 'SI'),
            $this->answerFor($version, 'G10', 'MG10.2', 'SI'),
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
     * Same as g10RedAnswers() but MF own items are all 'NO' (yellow),
     * triggering the alternate gate for the MF axis (REQ-SCR-005).
     *
     * @return array<int, array{screening_item_id: string, screening_item_option_id: string}>
     */
    protected function g10AllYellowMfAnswers(InstrumentVersion $version): array
    {
        return [
            $this->answerFor($version, 'G10', 'MG10.1', 'SI'),
            $this->answerFor($version, 'G10', 'MG10.2', 'SI'),
            $this->answerFor($version, 'G10', 'MG10.3', 'SI'),
            $this->answerFor($version, 'G10', 'MF10.1', 'NO'),
            $this->answerFor($version, 'G10', 'MF10.2', 'NO'),
            $this->answerFor($version, 'G10', 'MF10.3', 'NO'),
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
     * @return array<int, array{screening_item_id: string, screening_item_option_id: string}>
     */
    protected function g9aMfAlternateAnswers(InstrumentVersion $version): array
    {
        return [
            $this->answerFor($version, 'G9A', 'MF9.1', 'SI'),
            $this->answerFor($version, 'G9A', 'MF9.2', 'SI'),
            $this->answerFor($version, 'G9A', 'MF9.3', 'SI'),
        ];
    }

    protected function riskFactorId(InstrumentVersion $version, string $code): string
    {
        return RiskFactor::where('instrument_version_id', $version->id)
            ->where('code', $code)
            ->value('id');
    }

    protected function centralUser(): User
    {
        return User::create([
            'id' => (string) Str::uuid7(),
            'name' => 'Test '.Str::random(5),
            'email' => Str::random(10).'@test.com',
            'password' => Hash::make('secret1234'),
            'auth_active' => true,
        ]);
    }

    /**
     * Administrator token for the given tenant, carrying its REAL
     * permission set (not a wildcard) — required for permission-boundary
     * assertions (rescore.screening etc).
     */
    protected function administratorToken(Tenant $tenant): string
    {
        $user = $this->makeUser($tenant);
        $user->assignRole(Role::SLUG_ADMINISTRATOR);

        return $user->createToken('admin', $user->getPermissionsViaRoles()->pluck('name')->toArray())->plainTextToken;
    }
}
