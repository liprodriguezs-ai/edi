<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

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
 * ESTADO: vacío a propósito. La plantilla traía 12 catálogos del dominio de
 * organizaciones (tipos de unidad, puestos de empleado, categorías de cargo…) que
 * se borraron junto con sus módulos. Los catálogos EDI —parentesco del cuidador,
 * escolaridad, ocupación, zonas corporales, movimientos de masaje, especialidades
 * de referencia— se agregan a CATALOGS a medida que se construyen sus módulos.
 * El mecanismo de siembra ya funciona: sólo hay que llenar el arreglo.
 */
class TenantCatalogSeeder extends Seeder
{
    /**
     * Defaults declarativos por catálogo. Columna especial:
     *  - key: fila de sistema (no borrable, invisible en UI, protegida por HasCatalogKey)
     *
     * @var array<class-string, array<int, array<string, mixed>>>
     */
    public const CATALOGS = [];

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
