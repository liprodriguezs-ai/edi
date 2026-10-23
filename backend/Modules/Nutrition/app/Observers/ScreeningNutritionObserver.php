<?php

namespace Modules\Nutrition\Observers;

use Modules\Nutrition\Services\NutritionalAssessmentService;
use Modules\Screening\Models\Screening;

/**
 * S5 -- the Prueba EDI somatometry feeds the child's nutritional history.
 * Registered by NutritionServiceProvider, so the Screening module does not
 * depend on Nutrition.
 */
class ScreeningNutritionObserver
{
    public function __construct(private readonly NutritionalAssessmentService $service) {}

    public function created(Screening $screening): void
    {
        if ($screening->weight_grams !== null || $screening->height_mm !== null) {
            $this->service->syncFromScreening($screening);
        }
    }

    public function updated(Screening $screening): void
    {
        if ($screening->wasChanged(['weight_grams', 'height_mm'])) {
            $this->service->syncFromScreening($screening);
        }
    }

    public function deleted(Screening $screening): void
    {
        $this->service->deleteForScreening($screening);
    }
}
