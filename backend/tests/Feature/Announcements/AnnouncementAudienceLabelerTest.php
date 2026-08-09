<?php

declare(strict_types=1);

namespace Tests\Feature\Announcements;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Announcements\Services\AnnouncementAudienceLabeler;
use Modules\Housing\Models\Section;
use Modules\Housing\Models\Tower;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitFeature;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Tests\Support\Catalog;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Unit-level coverage for AnnouncementAudienceLabeler (the audience humanizer that
 * feeds the "Enviado a: …" line of the announcement email).
 *
 * Exercises every target arm AND the "never blank" degrade contract: unresolved ids
 * fall back to the bare prefix; unknown / by_share targets fall back to
 * "Todo el condominio". Catalog scaffolding only — no HTTP.
 */
final class AnnouncementAudienceLabelerTest extends TestCase
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

    public function test_labels_cover_every_target_arm_and_degrade_to_fallback(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $labeler = app(AnnouncementAudienceLabeler::class);

            // --- Fixed-string arms (no data needed) ---
            $this->assertSame('Todo el condominio', $labeler->label('all', null));
            $this->assertSame('Solo la mesa directiva', $labeler->label('board', null));
            $this->assertSame('Unidades con vehículo', $labeler->label('with_vehicles', null));

            // --- Fallbacks: by_share + unknown type both degrade, never blank ---
            $this->assertSame('Todo el condominio', $labeler->label('by_share', null));
            $this->assertSame('Todo el condominio', $labeler->label('nonsense_type', ['foo' => 'bar']));

            // --- manual: N / 1 / empty ---
            $this->assertSame('2 unidades seleccionadas', $labeler->label('manual', ['unit_ids' => ['a', 'b']]));
            $this->assertSame('1 unidad seleccionada', $labeler->label('manual', ['unit_ids' => ['a']]));
            $this->assertSame('Unidades seleccionadas', $labeler->label('manual', ['unit_ids' => []]));
            $this->assertSame('Unidades seleccionadas', $labeler->label('manual', []));

            // --- by_floor: single / multi / empty ---
            $this->assertSame('Piso 5', $labeler->label('by_floor', ['floors' => ['5']]));
            $this->assertSame('Pisos 3, 5', $labeler->label('by_floor', ['floors' => ['3', '5']]));
            $this->assertSame('Por piso', $labeler->label('by_floor', ['floors' => []]));

            // --- by_type: id -> names, ordered by name (Zeta created first, Alfa second) ---
            $typeZeta = UnitType::create(['name' => 'Zeta', 'requires_floor' => false]);
            $typeAlfa = UnitType::create(['name' => 'Alfa', 'requires_floor' => false]);
            $this->assertSame(
                'Por tipo: Alfa, Zeta',
                $labeler->label('by_type', ['unit_type_ids' => [$typeZeta->id, $typeAlfa->id]]),
            );

            // --- by_tower / by_section / by_feature: id -> name ---
            $towerNorte = Tower::create(['name' => 'Norte', 'floors_count' => 3]);
            $this->assertSame('Por torre: Norte', $labeler->label('by_tower', ['tower_ids' => [$towerNorte->id]]));

            $section = Section::create(['name' => 'Ala Este']);
            $this->assertSame('Por sección: Ala Este', $labeler->label('by_section', ['section_ids' => [$section->id]]));

            $feature = UnitFeature::create(['name' => 'Alberca']);
            $this->assertSame('Por característica: Alberca', $labeler->label('by_feature', ['unit_feature_ids' => [$feature->id]]));

            // --- unresolved / empty ids degrade to the bare prefix (never blank) ---
            $this->assertSame('Por torre', $labeler->label('by_tower', ['tower_ids' => [(string) Str::uuid()]]));
            $this->assertSame('Por tipo', $labeler->label('by_type', ['unit_type_ids' => []]));

            // --- single_unit: the unit's full_identifier / null -> fallback ---
            $unit = Unit::create([
                'unit_type_id' => $typeAlfa->id,
                'number' => '23',
                'unit_status_id' => Catalog::id(UnitStatus::class, 'Activa'),
            ]);
            $label = $labeler->label('single_unit', ['unit_id' => $unit->id]);
            $this->assertSame($unit->full_identifier, $label);
            $this->assertNotSame('', $label);
            $this->assertSame('Una unidad', $labeler->label('single_unit', ['unit_id' => null]));
            $this->assertSame('Una unidad', $labeler->label('single_unit', []));
        });
    }
}
