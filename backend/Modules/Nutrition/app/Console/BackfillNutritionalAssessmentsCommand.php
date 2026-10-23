<?php

namespace Modules\Nutrition\Console;

use Illuminate\Console\Command;
use Modules\Nutrition\Models\NutritionalAssessment;
use Modules\Nutrition\Services\NutritionalAssessmentService;
use Modules\Screening\Models\Screening;
use Stancl\Tenancy\Database\TenantScope;

/**
 * Classifies the somatometry of every Prueba EDI saved before the Nutrition
 * module existed (including the legacy migration). Idempotent: it reuses the
 * same upsert the screening observer runs, keyed by screening_id.
 */
class BackfillNutritionalAssessmentsCommand extends Command
{
    protected $signature = 'nutrition:backfill';

    protected $description = 'Calcula el estado nutricional de las Pruebas EDI con peso o talla que aún no lo tienen';

    public function handle(NutritionalAssessmentService $service): int
    {
        $synced = 0;

        Screening::withoutGlobalScope(TenantScope::class)
            ->where(fn ($query) => $query->whereNotNull('weight_grams')->orWhereNotNull('height_mm'))
            ->whereNotIn('id', NutritionalAssessment::withoutGlobalScope(TenantScope::class)
                ->whereNotNull('screening_id')
                ->select('screening_id'))
            ->orderBy('id')
            ->chunkById(200, function ($screenings) use ($service, &$synced): void {
                foreach ($screenings as $screening) {
                    $service->syncFromScreening($screening);
                    $synced++;
                }
            });

        $this->info("Pruebas EDI clasificadas: {$synced}");

        return self::SUCCESS;
    }
}
