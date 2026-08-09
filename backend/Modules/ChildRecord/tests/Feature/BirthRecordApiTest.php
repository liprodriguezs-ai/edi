<?php

declare(strict_types=1);

namespace Modules\ChildRecord\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\ChildRecord\Models\BirthRecord;
use Modules\ChildRecord\Models\Child;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * REQ-CH-007 coverage.
 */
final class BirthRecordApiTest extends TestCase
{
    use RefreshDatabase;
    use WithTenantSetup;

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    public function test_second_birth_record_rejected(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $user = $this->makeUser($tenant);
        $token = $user->createToken('test', ['*'])->plainTextToken;
        $child = $this->inTenant($tenant, fn () => Child::factory()->create());

        $this->inTenant($tenant, fn () => BirthRecord::factory()->create(['child_id' => $child->id]));

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/birth_record', ['child_id' => $child->id, 'birth_weight_grams' => 3000])
            ->assertStatus(422);
    }

    public function test_partial_capture_allowed_and_omitted_fields_stay_null(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $user = $this->makeUser($tenant);
        $token = $user->createToken('test', ['*'])->plainTextToken;
        $child = $this->inTenant($tenant, fn () => Child::factory()->create());

        $response = $this->tenantRequest($token, $tenant)
            ->postJson('/api/birth_record', ['child_id' => $child->id, 'birth_weight_grams' => 3100])
            ->assertCreated();

        $response->assertJsonPath('data.birth_weight_grams', 3100);
        $response->assertJsonPath('data.gestational_age_weeks', null);
        $response->assertJsonPath('data.had_pregnancy_complication', null);
    }

    public function test_risk_factor_derivability_preserved_as_integers(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $user = $this->makeUser($tenant);
        $token = $user->createToken('test', ['*'])->plainTextToken;
        $child = $this->inTenant($tenant, fn () => Child::factory()->create());

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/birth_record', [
                'child_id' => $child->id,
                'gestational_age_weeks' => 33,
                'birth_weight_grams' => 1400,
                'mother_age_at_birth_years' => 15,
            ])
            ->assertCreated();

        $tenant->run(function () use ($child): void {
            $record = BirthRecord::where('child_id', $child->id)->firstOrFail();
            $this->assertIsInt($record->gestational_age_weeks);
            $this->assertLessThan(34, $record->gestational_age_weeks);
            $this->assertIsInt($record->birth_weight_grams);
            $this->assertLessThanOrEqual(1500, $record->birth_weight_grams);
            $this->assertIsInt($record->mother_age_at_birth_years);
            $this->assertLessThan(16, $record->mother_age_at_birth_years);
        });
    }

    public function test_unknown_gestational_age_yields_null_not_derivable_false(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $child = $this->inTenant($tenant, fn () => Child::factory()->create());
        $record = $this->inTenant($tenant, fn () => BirthRecord::factory()->create([
            'child_id' => $child->id,
            'gestational_age_weeks' => null,
        ]));

        $tenant->run(function () use ($record): void {
            $this->assertNull($record->fresh()->gestational_age_weeks);
        });
    }

    #[DataProvider('outOfRangeProvider')]
    public function test_out_of_range_values_rejected(string $field, int $value): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $user = $this->makeUser($tenant);
        $token = $user->createToken('test', ['*'])->plainTextToken;
        $child = $this->inTenant($tenant, fn () => Child::factory()->create());

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/birth_record', ['child_id' => $child->id, $field => $value])
            ->assertStatus(422);
    }

    public static function outOfRangeProvider(): array
    {
        return [
            'gestational_age_weeks too low' => ['gestational_age_weeks', 10],
            'birth_weight_grams too high' => ['birth_weight_grams', 8000],
            'breastfeeding_months too high' => ['breastfeeding_months', 100],
        ];
    }
}
