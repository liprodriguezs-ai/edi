<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\ChildRecord\Models\CaregiverRelationship;
use Modules\ChildRecord\Models\EducationLevel;
use Modules\ChildRecord\Models\Occupation;

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
