<?php

namespace Modules\Nutrition\Providers;

use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Modules\Nutrition\Console\BackfillNutritionalAssessmentsCommand;
use Modules\Nutrition\Models\NutritionalAssessment;
use Modules\Nutrition\Observers\ScreeningNutritionObserver;
use Modules\Screening\Models\Screening;

/**
 * Nutritional status module (S5). Besides its own routes, it keeps the
 * screening somatometry in sync with a linked nutritional_assessment by
 * observing Screening from HERE -- the Screening module stays unaware of
 * nutrition.
 */
class NutritionServiceProvider extends ServiceProvider
{
    protected string $name = 'Nutrition';

    protected string $nameLower = 'nutrition';

    public function boot(): void
    {
        $this->mergeConfigFrom(module_path($this->name, 'config/config.php'), $this->nameLower);

        Screening::observe(ScreeningNutritionObserver::class);

        $this->commands([BackfillNutritionalAssessmentsCommand::class]);

        // The Prueba EDI document shows the nutritional status of its own
        // somatometry. Injected from here so Screening never depends on Nutrition.
        View::composer('screening::document', function (\Illuminate\View\View $view): void {
            $screening = $view->getData()['screening'] ?? null;
            $view->with('nutritionalAssessment', $screening instanceof Screening
                ? NutritionalAssessment::query()
                    ->with(['weight_for_height_status', 'weight_for_age_status', 'height_for_age_status'])
                    ->where('screening_id', $screening->id)
                    ->first()
                : null);
        });
    }

    public function register(): void
    {
        $this->app->register(RouteServiceProvider::class);
    }

    public function provides(): array
    {
        return [];
    }
}
