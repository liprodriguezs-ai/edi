<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\ChildRecord\Models\CaregiverRelationship;
use Modules\ChildRecord\Models\EducationLevel;
use Modules\ChildRecord\Models\Occupation;
use Modules\FollowUp\Models\ReferralSpecialty;
use Modules\Stimulation\Models\BodyZone;
use Modules\Stimulation\Models\DidacticMaterial;
use Modules\Stimulation\Models\MassageMovement;
use Modules\Stimulation\Models\Song;
use Modules\Stimulation\Models\StimulationDynamic;
use Modules\Stimulation\Models\StimulationGroupType;

/**
 * Carga inicial de los catálogos AUTOADMINISTRABLES (tenant-scoped).
 *
 * Corre DENTRO de tenancy (TenantCreated → tenants:seed → TenantDatabaseSeeder),
 * así BelongsToTenant estampa el tenant_id solo. Es idempotente: las filas con
 * `key` (de sistema) upsertean por key — sobreviven renombres del admin; las
 * demás upsertean por name.
 *
 * El admin de la organización puede renombrar, recolorear, desactivar, reordenar
 * o agregar filas a gusto; las filas de sistema (key) no pueden borrarse
 * (HasCatalogKey). Los catálogos con FSM/comportamiento NO viven aquí — son
 * centrales y los siembra CatalogSeeder una sola vez.
 *
 * ESTADO: los catálogos ChildRecord (parentesco del cuidador, escolaridad,
 * ocupación) fueron los primeros en llenar CATALOGS. Los siguientes catálogos
 * EDI —zonas corporales, movimientos de masaje, especialidades de referencia—
 * se agregan a medida que se construyen sus módulos.
 */
class TenantCatalogSeeder extends Seeder
{
    /**
     * Defaults declarativos por catálogo. Columna especial:
     *  - key: fila de sistema (no borrable, invisible en UI, protegida por HasCatalogKey)
     *
     * @var array<class-string, array<int, array<string, mixed>>>
     */
    public const CATALOGS = [
        CaregiverRelationship::class => [
            ['name' => 'Madre'],
            ['name' => 'Padre'],
            ['name' => 'Abuela'],
            ['name' => 'Abuelo'],
            ['name' => 'Tutor legal'],
            ['name' => 'Otro'],
        ],
        EducationLevel::class => [
            ['name' => 'Ninguna'],
            ['name' => 'Primaria incompleta'],
            ['name' => 'Primaria'],
            ['name' => 'Secundaria'],
            ['name' => 'Bachillerato'],
            ['name' => 'Licenciatura'],
            ['name' => 'Posgrado'],
        ],
        Occupation::class => [
            ['name' => 'Hogar'],
            ['name' => 'Empleada/o'],
            ['name' => 'Comerciante'],
            ['name' => 'Campo'],
            ['name' => 'Estudiante'],
            ['name' => 'Desempleada/o'],
            ['name' => 'Otra'],
        ],

        // Stimulation (edi-stimulation-sessions). didactic_material y song
        // arrancan vacíos a propósito (design §8 Q2): son catálogos de
        // sugerencia, el admin los llena; el niño de estimulación es texto
        // libre, nunca un FK a estos catálogos.
        StimulationDynamic::class => [
            ['name' => 'Mamá/bebé'],
            ['name' => 'Bebé/bebé'],
            ['name' => 'Grupal'],
            ['name' => 'Circuito'],
        ],
        BodyZone::class => [
            ['name' => 'Cuello'],
            ['name' => 'Hombro/brazo'],
            ['name' => 'Mano'],
            ['name' => 'Tronco'],
            ['name' => 'Pierna'],
            ['name' => 'Pie'],
            ['name' => 'Cara'],
            ['name' => 'Pecho y abdomen'],
            ['name' => 'Espalda'],
        ],
        MassageMovement::class => [
            ['name' => 'Flexión'],
            ['name' => 'Extensión'],
            ['name' => 'Circunducción'],
            ['name' => 'Media circunducción'],
            ['name' => 'Arriba-abajo'],
            ['name' => 'Abducción-aducción'],
            ['name' => 'Amasamiento de dedos'],
        ],
        DidacticMaterial::class => [],
        Song::class => [],
        StimulationGroupType::class => [
            ['name' => 'Grupo abierto'],
        ],

        // FollowUp (edi-followup-and-referral D6): especialidades de
        // referencia, pura clasificación sin lógica de negocio.
        ReferralSpecialty::class => [
            ['name' => 'Neurología pediátrica'],
            ['name' => 'Audiología'],
            ['name' => 'Oftalmología'],
            ['name' => 'Terapia de lenguaje'],
            ['name' => 'Terapia física'],
            ['name' => 'Psicología'],
        ],
    ];

    public function run(): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        foreach (self::CATALOGS as $model => $rows) {
            foreach ($rows as $row) {
                $match = isset($row['key'])
                    ? ['key' => $row['key']]
                    : ['name' => $row['name']];

                // firstOrCreate: la carga inicial JAMÁS sobreescribe lo que el
                // admin de la organización ya personalizó (renombres, colores, orden).
                $model::firstOrCreate($match, $row + ['is_active' => true]);
            }
        }
    }
}
