<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Convierte 12 catálogos de etiquetas (sin lógica de negocio, o con lógica
 * expresable como columna) de CENTRALES a TENANT-SCOPED, para que cada clúster
 * los administre. Los catálogos con FSM/comportamiento (charge_status, etc.)
 * permanecen centrales y NO se tocan aquí.
 *
 * Por cada catálogo:
 *  1. Agrega tenant_id (nullable durante el backfill) y columnas de
 *     comportamiento (key / grants_role / is_billable / requires_floor).
 *  2. Clona cada fila global una vez por tenant (UUID nuevo) y remapea las FKs
 *     de las tablas de negocio del tenant (incluye target_selector JSON de
 *     charge_batch para unit_type).
 *  3. Borra las filas globales y endurece: NOT NULL + FK + index +
 *     unique(tenant_id, name), soltando el unique global de name si existía.
 *
 * down() solo revierte la ESTRUCTURA (las filas quedan duplicadas por tenant);
 * los datos no son reversibles a su forma central original.
 */
return new class extends Migration
{
    /**
     * @var array<string, array{refs: array<int, array{0: string, 1: string}>, columns?: array<string, array{type: string, default?: mixed}>, values?: array<string, array<string, mixed>>}>
     */
    private array $catalogs = [
        'unit_type' => [
            'refs' => [['unit', 'unit_type_id']],
            'columns' => ['requires_floor' => ['type' => 'boolean', 'default' => false]],
            // UnitType::APARTMENT — exige torre/piso en las validaciones.
            'values' => ['ceba0094-cb8a-4ce9-aa32-9297e3d52aa8' => ['requires_floor' => true]],
        ],
        'unit_status' => [
            'refs' => [['unit', 'unit_status_id']],
            'columns' => ['is_billable' => ['type' => 'boolean', 'default' => true]],
            // UnitStatus::INACTIVE — excluida de la generación de cargos.
            'values' => ['7092fd4e-e073-4485-a501-0f1511178c8a' => ['is_billable' => false]],
        ],
        'employee_position' => [
            'refs' => [['collaborator', 'employee_position_id']],
            'columns' => ['grants_role' => ['type' => 'string', 'default' => null]],
            'values' => [
                // EmployeePosition::SECURITY_GUARD / ::ADMINISTRATOR — rol Kivo7
                // que otorga el alta del empleado; null = operations_staff.
                '5160f007-cd62-459e-9a89-aa749a8cd718' => ['grants_role' => 'security'],
                '1a3dbb90-5d50-401f-886b-af40154e0dff' => ['grants_role' => 'administrator'],
            ],
        ],
        'pay_frequency' => [
            'refs' => [['collaborator', 'pay_frequency_id']],
        ],
        'employee_shift' => [
            'refs' => [['collaborator', 'employee_shift_id']],
        ],
        'bank_account_purpose' => [
            'refs' => [['bank_account', 'bank_account_purpose_id']],
        ],
        'charge_concept_category' => [
            'refs' => [['charge_concept', 'charge_concept_category_id']],
            'columns' => ['key' => ['type' => 'string', 'default' => null]],
            // ChargeConceptCategory::PROJECT — categoría default de conceptos
            // auto-creados al activar proyectos obligatorios.
            'values' => ['01c5d822-fa5c-43b8-bf62-ca39d857d4e6' => ['key' => 'project']],
        ],
        'community_project_category' => [
            'refs' => [['community_project', 'community_project_category_id']],
        ],
        'provider_type' => [
            'refs' => [['provider', 'provider_type_id']],
        ],
        'provider_service_type' => [
            'refs' => [['provider', 'provider_service_type_id']],
        ],
        'visitor_motive' => [
            'refs' => [['visitor', 'visitor_motive_id'], ['access_log', 'visitor_motive_id']],
        ],
        'announcement_type' => [
            'refs' => [['announcement', 'announcement_type_id']],
            'columns' => ['key' => ['type' => 'string', 'default' => null]],
            'values' => [
                // AnnouncementType::GENERAL / ::FINANCIAL — defaults de los avisos
                // auto-creados (BoardService / ReportBoardPublisher).
                '5d7211b9-3001-4836-80e4-f3ac9becc5fb' => ['key' => 'general'],
                '9099c181-8844-4c42-942d-fa60a2380649' => ['key' => 'financial'],
            ],
        ],
    ];

    public function up(): void
    {
        // 1. Estructura: tenant_id nullable + columnas de comportamiento, y
        //    soltar el unique global de name (constraint o índice, según cómo
        //    se haya creado).
        foreach ($this->catalogs as $table => $config) {
            Schema::table($table, function (Blueprint $blueprint) use ($table, $config) {
                if (! Schema::hasColumn($table, 'tenant_id')) {
                    $blueprint->uuid('tenant_id')->nullable()->after('id');
                }

                foreach ($config['columns'] ?? [] as $column => $definition) {
                    if (Schema::hasColumn($table, $column)) {
                        continue;
                    }

                    if ($definition['type'] === 'boolean') {
                        $blueprint->boolean($column)->default($definition['default']);
                    } else {
                        $blueprint->string($column)->nullable();
                    }
                }
            });

            DB::statement(sprintf('ALTER TABLE "%s" DROP CONSTRAINT IF EXISTS "%s_name_unique"', $table, $table));
            DB::statement(sprintf('DROP INDEX IF EXISTS "%s_name_unique"', $table));
        }

        // 2. Backfill: clonar filas globales por tenant y remapear FKs.
        $tenantIds = DB::table('tenant')->pluck('id');

        foreach ($this->catalogs as $table => $config) {
            $globalRows = DB::table($table)->whereNull('tenant_id')->get();

            if ($globalRows->isEmpty()) {
                continue;
            }

            foreach ($tenantIds as $tenantId) {
                $idMap = [];

                foreach ($globalRows as $row) {
                    $clone = (array) $row;
                    $clone['id'] = (string) Str::uuid();
                    $clone['tenant_id'] = $tenantId;

                    foreach ($config['values'][$row->id] ?? [] as $column => $value) {
                        $clone[$column] = $value;
                    }

                    DB::table($table)->insert($clone);
                    $idMap[$row->id] = $clone['id'];
                }

                foreach ($config['refs'] as [$refTable, $refColumn]) {
                    foreach ($idMap as $oldId => $newId) {
                        DB::table($refTable)
                            ->where('tenant_id', $tenantId)
                            ->where($refColumn, $oldId)
                            ->update([$refColumn => $newId]);
                    }
                }

                if ($table === 'unit_type') {
                    $this->remapChargeBatchSelectors($tenantId, $idMap);
                }
            }

            DB::table($table)->whereNull('tenant_id')->delete();
        }

        // 3. Endurecer: NOT NULL + FK + index + unique(tenant_id, name),
        //    espejo de BaseModel::columns($table, true).
        foreach (array_keys($this->catalogs) as $table) {
            DB::statement(sprintf('ALTER TABLE "%s" ALTER COLUMN "tenant_id" SET NOT NULL', $table));

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->foreign('tenant_id')->references('id')->on('tenant')->cascadeOnDelete();
                $blueprint->index('tenant_id');
                $blueprint->unique(['tenant_id', 'name']);
            });
        }
    }

    public function down(): void
    {
        foreach ($this->catalogs as $table => $config) {
            Schema::table($table, function (Blueprint $blueprint) use ($config) {
                $blueprint->dropForeign(['tenant_id']);
                $blueprint->dropUnique(['tenant_id', 'name']);
                $blueprint->dropIndex(['tenant_id']);
                $blueprint->dropColumn(array_merge(['tenant_id'], array_keys($config['columns'] ?? [])));
            });
        }
    }

    /**
     * Los lotes by_type guardan unit_type_ids dentro del JSON target_selector;
     * remapea esos ids a los clones del tenant.
     */
    private function remapChargeBatchSelectors(string $tenantId, array $idMap): void
    {
        $batches = DB::table('charge_batch')
            ->where('tenant_id', $tenantId)
            ->where('target_type', 'by_type')
            ->whereNotNull('target_selector')
            ->get(['id', 'target_selector']);

        foreach ($batches as $batch) {
            $selector = json_decode($batch->target_selector, true);

            if (! is_array($selector) || empty($selector['unit_type_ids'])) {
                continue;
            }

            $selector['unit_type_ids'] = array_map(
                static fn (string $id): string => $idMap[$id] ?? $id,
                $selector['unit_type_ids']
            );

            DB::table('charge_batch')
                ->where('id', $batch->id)
                ->update(['target_selector' => json_encode($selector)]);
        }
    }
};
