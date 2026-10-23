<?php

namespace Modules\Nutrition\Services;

use Illuminate\Validation\ValidationException;
use Modules\ChildRecord\Models\Child;
use Modules\ChildRecord\Models\Sex;
use Modules\ChildRecord\Models\Visit;
use Modules\Nutrition\Models\NutritionalAssessment;
use Modules\Nutrition\Models\NutritionalStatus;
use Modules\Screening\Models\Screening;
use Stancl\Tenancy\Database\TenantScope;

/**
 * S5 -- business rules of nutritional_assessment. The age and the three
 * statuses are derived here from the child's birth date and sex and the
 * measures; nothing status-like is ever taken from the client.
 */
class NutritionalAssessmentService
{
    private const SEX_KEYS = [
        Sex::MALE => 'male',
        Sex::FEMALE => 'female',
    ];

    public function __construct(private readonly NutritionalStatusClassifier $classifier) {}

    /**
     * Server-computed classification without persisting anything.
     *
     * @return array{age_months: int, weight_for_height_status: ?NutritionalStatus, weight_for_age_status: ?NutritionalStatus, height_for_age_status: ?NutritionalStatus}
     */
    public function preview(Child $child, string $measuredOn, ?int $weightGrams, ?int $heightMm): array
    {
        $this->assertMeasuredAfterBirth($child, $measuredOn);
        [$ageMonths, $keys] = $this->evaluate($child, $measuredOn, $weightGrams, $heightMm);

        $statuses = NutritionalStatus::query()
            ->whereIn('id', array_filter(array_map(NutritionalStatus::idForKey(...), $keys)))
            ->get(['id', 'key', 'name'])
            ->keyBy('key');

        return [
            'age_months' => $ageMonths,
            'weight_for_height_status' => $keys['weight_for_height'] === null ? null : $statuses->get($keys['weight_for_height']),
            'weight_for_age_status' => $keys['weight_for_age'] === null ? null : $statuses->get($keys['weight_for_age']),
            'height_for_age_status' => $keys['height_for_age'] === null ? null : $statuses->get($keys['height_for_age']),
        ];
    }

    /**
     * Writes the measures plus the derived age/statuses onto $assessment
     * (not saved). Throws a 422 when the measurement predates the birth or
     * carries no measure at all.
     */
    public function fill(NutritionalAssessment $assessment, Child $child, string $measuredOn, ?int $weightGrams, ?int $heightMm): void
    {
        if ($weightGrams === null && $heightMm === null) {
            throw ValidationException::withMessages([
                'weight_grams' => 'Captura al menos el peso o la talla.',
            ]);
        }

        $this->assertMeasuredAfterBirth($child, $measuredOn);
        $this->apply($assessment, $child, $measuredOn, $weightGrams, $heightMm);
    }

    /**
     * Keeps the assessment linked to a screening (Prueba EDI) in sync with its
     * somatometry. Runs from the Screening observer, so it filters by the
     * screening's tenant explicitly instead of trusting the ambient scope.
     */
    public function syncFromScreening(Screening $screening): void
    {
        $existing = NutritionalAssessment::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $screening->tenant_id)
            ->where('screening_id', $screening->id)
            ->first();

        if ($screening->weight_grams === null && $screening->height_mm === null) {
            $existing?->delete();

            return;
        }

        $child = Child::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $screening->tenant_id)
            ->find($screening->child_id);

        $visitDate = Visit::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $screening->tenant_id)
            ->whereKey($screening->visit_id)
            ->value('visit_date');

        if ($child === null || $visitDate === null) {
            return;
        }

        $assessment = $existing ?? new NutritionalAssessment;
        $assessment->forceFill([
            'tenant_id' => $screening->tenant_id,
            'screening_id' => $screening->id,
        ]);
        $this->apply($assessment, $child, substr((string) $visitDate, 0, 10), $screening->weight_grams, $screening->height_mm);
        $assessment->save();
    }

    public function deleteForScreening(Screening $screening): void
    {
        NutritionalAssessment::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $screening->tenant_id)
            ->where('screening_id', $screening->id)
            ->get()
            ->each->delete();
    }

    private function apply(NutritionalAssessment $assessment, Child $child, string $measuredOn, ?int $weightGrams, ?int $heightMm): void
    {
        [$ageMonths, $keys] = $this->evaluate($child, $measuredOn, $weightGrams, $heightMm);

        $assessment->forceFill([
            'child_id' => $child->id,
            'measured_on' => $measuredOn,
            'age_months' => $ageMonths,
            'weight_grams' => $weightGrams,
            'height_mm' => $heightMm,
            'weight_for_height_status_id' => NutritionalStatus::idForKey($keys['weight_for_height']),
            'weight_for_age_status_id' => NutritionalStatus::idForKey($keys['weight_for_age']),
            'height_for_age_status_id' => NutritionalStatus::idForKey($keys['height_for_age']),
        ]);
    }

    /**
     * @return array{0: int, 1: array{weight_for_height: ?string, weight_for_age: ?string, height_for_age: ?string}}
     */
    private function evaluate(Child $child, string $measuredOn, ?int $weightGrams, ?int $heightMm): array
    {
        $ageMonths = max(0, NutritionalStatusClassifier::completedMonths($child->birth_date->toDateString(), $measuredOn));
        $sexKey = self::SEX_KEYS[$child->sex_id] ?? '';

        return [$ageMonths, $this->classifier->classify($sexKey, $ageMonths, $weightGrams, $heightMm)];
    }

    private function assertMeasuredAfterBirth(Child $child, string $measuredOn): void
    {
        if ($measuredOn < $child->birth_date->toDateString()) {
            throw ValidationException::withMessages([
                'measured_on' => 'La fecha de medición no puede ser anterior a la fecha de nacimiento.',
            ]);
        }
    }
}
