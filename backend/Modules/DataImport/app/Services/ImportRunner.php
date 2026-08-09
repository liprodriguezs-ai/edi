<?php

namespace Modules\DataImport\Services;

use App\Models\Scopes\UserTenantScope;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Housing\Models\Tower;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitType;
use Modules\Housing\Models\UnitUser;
use Modules\SubscriptionPlan\Services\UnitLimitService;

/**
 * ImportRunner — orchestrates row-level DRY-RUN validation for the import preview.
 *
 * validate() iterates RowImporter::validate() over every row and aggregates
 * issues + preview counts. ZERO DB writes at any point in validate(). It backs the
 * synchronous POST /import/validate endpoint.
 *
 * NOTE (import-bulk-performance): execute() was RETIRED. Row execution now lives in
 * the chunked job engine: RunImportJob (coordinator) → Bus::batch of
 * ProcessImportChunkJob (per-row RowImporter::execute) → FinalizeImportJob
 * (terminal status + single ChargeStatusService aggregate recompute). The per-row
 * idempotency / FIFO / money contract in RowImporter::execute() is unchanged.
 */
class ImportRunner
{
    public function __construct(
        private readonly RowImporter $rowImporter,
        private readonly UnitLimitService $unitLimitService,
    ) {}

    // =========================================================================
    // validate() — pure dry-run, zero writes
    // =========================================================================

    /**
     * Validate all rows and return the preview response payload.
     *
     * @param  Collection<int, array<string, mixed>>  $rows  Keyed by 1-based row number.
     * @return array{
     *   total: int,
     *   units_to_create: int,
     *   residents_to_create: int,
     *   payments_to_register: int,
     *   blocked: int,
     *   rows: list<array{row: int, severity: string, column: string, message: string, general?: bool, count?: int}>
     * }
     */
    public function validate(Collection $rows): array
    {
        $allIssues = [];
        $blockedCount = 0;
        $validRowIdentities = [];

        foreach ($rows as $rowNumber => $rowData) {
            $issues = $this->rowImporter->validate($rowData, $rowNumber);

            $hasError = collect($issues)->contains('severity', 'error');

            if ($hasError) {
                $blockedCount++;
            } else {
                $validRowIdentities[] = [
                    'tipo_unidad' => $rowData['tipo_unidad'] ?? null,
                    'torre' => $rowData['torre'] ?? null,
                    'piso' => $rowData['piso'] ?? null,
                    'numero' => $rowData['numero'] ?? null,
                    'email' => $rowData['email'] ?? null,
                    'nombre' => $rowData['nombre'] ?? null,
                    'apellido_paterno' => $rowData['apellido_paterno'] ?? null,
                ];
            }

            array_push($allIssues, ...$issues);
        }

        $total = $rows->count();
        $validCount = $total - $blockedCount;

        $counters = $this->calculateDistinctCounters($validRowIdentities);

        // unit-limit-enforcement (REQ-05, REQ-10): reuse the already-computed
        // units_to_create counter — ZERO per-row queries. A file-level blocking
        // issue is appended BEFORE collapseGeneralIssues() runs, so it passes
        // through untouched (errors are never grouped, see that method).
        $unitLimitCheck = $this->unitLimitService->checkIncrease($counters['units_to_create']);

        if (! $unitLimitCheck->allowed) {
            $allIssues[] = [
                'row' => 0,
                'severity' => 'error',
                'column' => 'unidades',
                'general' => true,
                'message' => $this->unitLimitService->buildImportPreviewMessage($unitLimitCheck),
            ];
            $blockedCount++;
        }

        return [
            'total' => $total,
            'units_to_create' => $counters['units_to_create'],
            'residents_to_create' => $counters['residents_to_create'],
            'payments_to_register' => $validCount,
            'blocked' => $blockedCount,
            'rows' => $this->collapseGeneralIssues($allIssues),
        ];
    }

    // =========================================================================
    // Response assembly helpers
    // =========================================================================

    /**
     * Collapse general (catalog-reference) warnings that share the same
     * column + message into a single entry carrying a `count`. Errors and
     * per-row warnings (e.g. the email cross-tenant conflict) are returned
     * untouched, in their original row order.
     *
     * Grouping is keyed on `column|message`, NEVER on message text alone —
     * the 12 mandatory-field errors are byte-identical across rows and must
     * stay one entry per row (they are never marked `general` in the first
     * place, see ReferenceResolver::buildIssue()).
     *
     * @param  list<array{row: int, severity: string, column: string, message: string, general?: bool}>  $allIssues
     * @return list<array{row: int, severity: string, column: string, message: string, general?: bool, count?: int}>
     */
    private function collapseGeneralIssues(array $allIssues): array
    {
        $errors = [];
        $warnings = [];

        foreach ($allIssues as $issue) {
            if ($issue['severity'] === 'error') {
                $errors[] = $issue;

                continue;
            }

            $warnings[] = $issue;
        }

        $collapsedWarnings = [];
        $groupIndexByKey = [];

        foreach ($warnings as $warning) {
            if (empty($warning['general'])) {
                $collapsedWarnings[] = $warning;

                continue;
            }

            $key = $warning['column'].'|'.$warning['message'];

            if (! array_key_exists($key, $groupIndexByKey)) {
                $groupIndexByKey[$key] = count($collapsedWarnings);
                $warning['count'] = 1;
                $collapsedWarnings[] = $warning;

                continue;
            }

            $collapsedWarnings[$groupIndexByKey[$key]]['count']++;
        }

        return array_merge($errors, $collapsedWarnings);
    }

    /**
     * Compute the DISTINCT count of NEW units and NEW residents implied by the
     * error-free rows — not the valid-row count (see REQ-07).
     *
     * Exactly 6 constant, read-only queries regardless of row count:
     *  1. UnitType   — name → id map
     *  2. Tower      — name → id map
     *  3. Unit       — existing natural-key set (mirrors unit_identity_unique)
     *  4. User       — existing emails (case-insensitive)
     *  5. UnitUser   — links for the existing units actually referenced
     *  6. User       — linked residents' name/last_name for those links
     *
     * Resident resolution mirrors RowImporter::resolveOrCreateResident()'s
     * TWO-STEP CASCADE exactly (RowImporter.php:452-484): email lookup first,
     * then — REGARDLESS of whether the row has an email — a fallback by
     * (unit + nombre + apellido_paterno) via unit_user links. Concretely: an
     * email that resolves to an existing account is never new; everything
     * else (no email at all, OR an email that does NOT match any existing
     * account) falls through to the SAME unit+name fallback that a no-email
     * row uses. Query 4 first resolves which of the collected emails exist;
     * the fallthrough decision then happens in memory (no extra queries),
     * feeding the same rowsByExistingUnitId bucket that queries 5–6 resolve.
     *
     * @param  list<array<string, mixed>>  $validRowIdentities
     * @return array{units_to_create: int, residents_to_create: int}
     */
    private function calculateDistinctCounters(array $validRowIdentities): array
    {
        if (empty($validRowIdentities)) {
            return ['units_to_create' => 0, 'residents_to_create' => 0];
        }

        // Queries 1–2: constant reads for the two optional unit-identity catalogs.
        $unitTypeMap = UnitType::where('tenant_id', tenant('id'))
            ->get(['id', 'name'])
            ->mapWithKeys(fn ($type) => [mb_strtolower($type->name) => $type->id]);

        $towerMap = Tower::where('tenant_id', tenant('id'))
            ->get(['id', 'name'])
            ->mapWithKeys(fn ($tower) => [mb_strtolower($tower->name) => $tower->id]);

        // Query 3: existing units' natural keys (mirrors unit_identity_unique:
        // unit_type_id + COALESCE(tower_id,'') + COALESCE(floor,'') + COALESCE(number,'')).
        $existingUnits = Unit::where('tenant_id', tenant('id'))
            ->get(['id', 'unit_type_id', 'tower_id', 'floor', 'number']);

        $unitIdByKey = [];
        foreach ($existingUnits as $existingUnit) {
            $key = $this->buildUnitDbKey(
                $existingUnit->unit_type_id,
                $existingUnit->tower_id,
                $existingUnit->floor,
                $existingUnit->number
            );
            $unitIdByKey[$key] = $existingUnit->id;
        }

        $newUnitKeys = [];
        $rowResidentContexts = [];
        $emailsSeen = [];

        // Pass 1: resolve each row's unit key/id and collect FULL resident
        // context (email, nombre, apellido_paterno, unit key/id). Nothing is
        // decided "new" for residents yet — an email row must still be able
        // to fall through to the unit+name fallback below.
        foreach ($validRowIdentities as $identity) {
            $typeName = mb_strtolower(trim((string) ($identity['tipo_unidad'] ?? '')));
            $typeComponent = $unitTypeMap[$typeName] ?? ('new:'.$typeName);

            $towerName = ! empty($identity['torre']) ? mb_strtolower(trim((string) $identity['torre'])) : null;
            $towerComponent = $towerName !== null ? ($towerMap[$towerName] ?? '') : '';

            // NO trim on floor/number — mirrors RowImporter::resolveOrCreateUnit() exactly.
            $floorComponent = isset($identity['piso']) && $identity['piso'] !== '' ? (string) $identity['piso'] : '';
            $numberComponent = (string) ($identity['numero'] ?? '');

            $unitKey = $typeComponent.'|'.$towerComponent.'|'.$floorComponent.'|'.$numberComponent;
            $existingUnitId = $unitIdByKey[$unitKey] ?? null;

            if ($existingUnitId === null) {
                $newUnitKeys[$unitKey] = true;
            }

            $email = ! empty($identity['email']) ? mb_strtolower(trim((string) $identity['email'])) : null;
            $nombre = trim((string) ($identity['nombre'] ?? ''));
            $apellidoPaterno = trim((string) ($identity['apellido_paterno'] ?? ''));

            if ($email !== null) {
                $emailsSeen[$email] = true;
            }

            $rowResidentContexts[] = [
                'email' => $email,
                'nombre' => $nombre,
                'apellido_paterno' => $apellidoPaterno,
                'unit_key' => $unitKey,
                'existing_unit_id' => $existingUnitId,
            ];
        }

        // Query 4: existing emails, case-insensitive (emails are stored verbatim —
        // see RowImporter::resolveOrCreateResident()'s casing comment).
        $existingEmails = collect();
        if (! empty($emailsSeen)) {
            $existingEmails = User::withoutGlobalScope(UserTenantScope::class)
                ->whereIn(DB::raw('LOWER(email)'), array_keys($emailsSeen))
                ->pluck('email')
                ->map(fn ($email) => mb_strtolower($email))
                ->flip();
        }

        $newResidentKeys = [];
        $rowsByExistingUnitId = [];

        // Decision pass (in memory, ZERO extra queries): mirrors execute()'s
        // two-step cascade exactly.
        foreach ($rowResidentContexts as $context) {
            if ($context['email'] !== null && $existingEmails->has($context['email'])) {
                // Email resolved to an existing account — never new,
                // regardless of the row's name fields.
                continue;
            }

            // Fallthrough: no email at all, OR an email present that did NOT
            // match any existing account — same unit+name fallback either way.
            $nameKey = $context['unit_key'].'|'.$context['nombre'].'|'.$context['apellido_paterno'];

            if ($context['existing_unit_id'] === null) {
                // A resident on a not-yet-created unit can never match an
                // existing unit_user link — always new.
                $newResidentKeys[$nameKey] = true;

                continue;
            }

            $rowsByExistingUnitId[$context['existing_unit_id']][] = [
                'key' => $nameKey,
                'nombre' => $context['nombre'],
                'apellido_paterno' => $context['apellido_paterno'],
            ];
        }

        // Queries 5–6: unit_user links + linked residents' names, scoped ONLY to
        // the existing units actually referenced by a fallthrough row.
        if (! empty($rowsByExistingUnitId)) {
            $unitIds = array_keys($rowsByExistingUnitId);

            $links = UnitUser::where('tenant_id', tenant('id'))
                ->whereIn('unit_id', $unitIds)
                ->get(['unit_id', 'user_id']);

            $userIdsByUnitId = [];
            foreach ($links as $link) {
                $userIdsByUnitId[$link->unit_id][] = $link->user_id;
            }

            $allLinkedUserIds = collect($userIdsByUnitId)->flatten()->unique()->values()->all();

            $linkedUsersById = ! empty($allLinkedUserIds)
                ? User::withoutGlobalScope(UserTenantScope::class)
                    ->whereIn('id', $allLinkedUserIds)
                    ->get(['id', 'name', 'last_name'])
                    ->keyBy('id')
                : collect();

            foreach ($rowsByExistingUnitId as $unitId => $residentRows) {
                $linkedNamePairs = [];
                foreach ($userIdsByUnitId[$unitId] ?? [] as $userId) {
                    $linkedUser = $linkedUsersById->get($userId);
                    if ($linkedUser !== null) {
                        $linkedNamePairs[$linkedUser->name.'|'.$linkedUser->last_name] = true;
                    }
                }

                foreach ($residentRows as $residentRow) {
                    $namePair = $residentRow['nombre'].'|'.$residentRow['apellido_paterno'];
                    if (! array_key_exists($namePair, $linkedNamePairs)) {
                        $newResidentKeys[$residentRow['key']] = true;
                    }
                }
            }
        }

        return [
            'units_to_create' => count($newUnitKeys),
            'residents_to_create' => count($newResidentKeys),
        ];
    }

    /**
     * Build the natural-key string for an existing Unit row, mirroring the
     * unit_identity_unique index's COALESCE semantics exactly.
     */
    private function buildUnitDbKey(string $unitTypeId, ?string $towerId, ?string $floor, ?string $number): string
    {
        return $unitTypeId.'|'.($towerId ?? '').'|'.($floor ?? '').'|'.($number ?? '');
    }
}
