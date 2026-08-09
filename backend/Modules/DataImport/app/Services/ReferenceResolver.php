<?php

namespace Modules\DataImport\Services;

use Modules\Collections\Models\PaymentMethod;
use Modules\Housing\Models\Section;
use Modules\Housing\Models\Tower;
use Modules\Housing\Models\UnitRelationship;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Modules\Treasury\Models\BankAccount;

/**
 * ReferenceResolver — resolves Spanish names/descriptions to model IDs.
 *
 * All tenant-scoped queries include ->where('tenant_id', tenant('id')) as
 * required by stancl-tenancy §8 (Rule::exists / manual queries on tenant tables
 * ignore TenantScope and must be scoped manually).
 *
 * Results are memoized per run so repeated references to the same name
 * only hit the DB once.
 */
class ReferenceResolver
{
    /**
     * Memoization caches keyed by catalog type.
     *
     * @var array<string, array<string, string|null>>
     */
    private array $cache = [];

    /**
     * Issues collected during resolution (warnings for unregistered values).
     *
     * @var list<array<string, mixed>>
     */
    private array $pendingIssues = [];

    // =========================================================================
    // Public resolution methods
    // =========================================================================

    /**
     * Resolve a unit_type name → UnitType.id.
     *
     * @return array{id: string|null, issues: list<array<string, mixed>>}
     */
    public function resolveUnitType(?string $name, int $rowNumber): array
    {
        if ($name === null || $name === '') {
            return ['id' => null, 'issues' => []];
        }

        $cacheKey = mb_strtolower(trim($name));
        $this->pendingIssues = [];

        if (! array_key_exists('unit_type:'.$cacheKey, $this->cache)) {
            $record = UnitType::query()
                ->where('tenant_id', tenant('id'))
                ->whereRaw('LOWER(name) = ?', [$cacheKey])
                ->first();

            $this->cache['unit_type:'.$cacheKey] = $record?->id;
        }

        $id = $this->cache['unit_type:'.$cacheKey];

        $issues = [];
        if ($id === null) {
            $issues[] = $this->buildIssue($rowNumber, 'tipo_unidad', 'warning',
                "El tipo de unidad '{$name}' no está registrado — se usará o creará al ejecutar.");
        }

        return ['id' => $id, 'issues' => $issues];
    }

    /**
     * Resolve a unit_status name → UnitStatus.id.
     *
     * @return array{id: string|null, issues: list<array<string, mixed>>}
     */
    public function resolveUnitStatus(?string $name, int $rowNumber): array
    {
        if ($name === null || $name === '') {
            return ['id' => null, 'issues' => []];
        }

        $cacheKey = mb_strtolower(trim($name));

        if (! array_key_exists('unit_status:'.$cacheKey, $this->cache)) {
            $record = UnitStatus::query()
                ->where('tenant_id', tenant('id'))
                ->whereRaw('LOWER(name) = ?', [$cacheKey])
                ->first();

            $this->cache['unit_status:'.$cacheKey] = $record?->id;
        }

        $id = $this->cache['unit_status:'.$cacheKey];

        $issues = [];
        if ($id === null) {
            $issues[] = $this->buildIssue($rowNumber, 'estado_unidad', 'warning',
                "El estado de unidad '{$name}' no está registrado — se usará el estado por defecto del condominio.");
        }

        return ['id' => $id, 'issues' => $issues];
    }

    /**
     * Resolve a tower name → Tower.id.
     *
     * @return array{id: string|null, issues: list<array<string, mixed>>}
     */
    public function resolveTower(?string $name, int $rowNumber): array
    {
        if ($name === null || $name === '') {
            return ['id' => null, 'issues' => []];
        }

        $cacheKey = mb_strtolower(trim($name));

        if (! array_key_exists('tower:'.$cacheKey, $this->cache)) {
            $record = Tower::query()
                ->where('tenant_id', tenant('id'))
                ->whereRaw('LOWER(name) = ?', [$cacheKey])
                ->first();

            $this->cache['tower:'.$cacheKey] = $record?->id;
        }

        $id = $this->cache['tower:'.$cacheKey];

        $issues = [];
        if ($id === null) {
            $issues[] = $this->buildIssue($rowNumber, 'torre', 'warning',
                "La torre '{$name}' no está registrada — la unidad se importará sin torre.");
        }

        return ['id' => $id, 'issues' => $issues];
    }

    /**
     * Resolve a section name → Section.id.
     *
     * @return array{id: string|null, issues: list<array<string, mixed>>}
     */
    public function resolveSection(?string $name, int $rowNumber): array
    {
        if ($name === null || $name === '') {
            return ['id' => null, 'issues' => []];
        }

        $cacheKey = mb_strtolower(trim($name));

        if (! array_key_exists('section:'.$cacheKey, $this->cache)) {
            $record = Section::query()
                ->where('tenant_id', tenant('id'))
                ->whereRaw('LOWER(name) = ?', [$cacheKey])
                ->first();

            $this->cache['section:'.$cacheKey] = $record?->id;
        }

        $id = $this->cache['section:'.$cacheKey];

        $issues = [];
        if ($id === null) {
            $issues[] = $this->buildIssue($rowNumber, 'seccion', 'warning',
                "La sección '{$name}' no está registrada — la unidad se importará sin sección.");
        }

        return ['id' => $id, 'issues' => $issues];
    }

    /**
     * Resolve a payment_method name → PaymentMethod.id.
     * Default when not found: PaymentMethod::CASH.
     *
     * @return array{id: string, issues: list<array<string, mixed>>}
     */
    public function resolvePaymentMethod(?string $name, int $rowNumber): array
    {
        if ($name === null || $name === '') {
            return ['id' => PaymentMethod::CASH, 'issues' => []];
        }

        $cacheKey = mb_strtolower(trim($name));

        if (! array_key_exists('payment_method:'.$cacheKey, $this->cache)) {
            // PaymentMethod is a CENTRAL catalog (no tenant_id column).
            $record = PaymentMethod::query()
                ->whereRaw('LOWER(name) = ?', [$cacheKey])
                ->first();

            $this->cache['payment_method:'.$cacheKey] = $record?->id;
        }

        $id = $this->cache['payment_method:'.$cacheKey];

        $issues = [];
        if ($id === null) {
            $issues[] = $this->buildIssue($rowNumber, 'metodo_pago', 'warning',
                "El método de pago '{$name}' no está registrado — se usará Efectivo por defecto.");

            return ['id' => PaymentMethod::CASH, 'issues' => $issues];
        }

        return ['id' => $id, 'issues' => $issues];
    }

    /**
     * Resolve a bank_account name → BankAccount.id.
     * Not found → returns null + warning (auto-create happens in execute phase).
     *
     * @return array{id: string|null, issues: list<array<string, mixed>>}
     */
    public function resolveBankAccount(?string $name, int $rowNumber): array
    {
        if ($name === null || $name === '') {
            return ['id' => null, 'issues' => []];
        }

        $cacheKey = mb_strtolower(trim($name));

        if (! array_key_exists('bank_account:'.$cacheKey, $this->cache)) {
            $record = BankAccount::query()
                ->where('tenant_id', tenant('id'))
                ->whereRaw('LOWER(name) = ?', [$cacheKey])
                ->first();

            $this->cache['bank_account:'.$cacheKey] = $record?->id;
        }

        $id = $this->cache['bank_account:'.$cacheKey];

        $issues = [];
        if ($id === null) {
            $issues[] = $this->buildIssue($rowNumber, 'cuenta_destino', 'warning',
                "La cuenta '{$name}' no existe — se creará automáticamente al ejecutar.");
        }

        return ['id' => $id, 'issues' => $issues];
    }

    /**
     * Resolve UnitRelationship by name, default to OWNER when blank.
     *
     * @return array{id: string, issues: list<array<string, mixed>>}
     */
    public function resolveUnitRelationship(?string $name, int $rowNumber): array
    {
        if ($name === null || $name === '') {
            return ['id' => UnitRelationship::OWNER, 'issues' => []];
        }

        $cacheKey = mb_strtolower(trim($name));

        if (! array_key_exists('unit_relationship:'.$cacheKey, $this->cache)) {
            // UnitRelationship is CENTRAL (no tenant_id).
            $record = UnitRelationship::query()
                ->whereRaw('LOWER(name) = ?', [$cacheKey])
                ->first();

            $this->cache['unit_relationship:'.$cacheKey] = $record?->id;
        }

        $id = $this->cache['unit_relationship:'.$cacheKey];

        $issues = [];
        if ($id === null) {
            $issues[] = $this->buildIssue($rowNumber, 'relacion', 'warning',
                "La relación '{$name}' no está registrada — se usará 'Propietario' por defecto.");

            return ['id' => UnitRelationship::OWNER, 'issues' => $issues];
        }

        return ['id' => $id, 'issues' => $issues];
    }

    /**
     * Bust the memoization cache. Call between import runs if needed.
     */
    public function clearCache(): void
    {
        $this->cache = [];
    }

    /**
     * Inject a resolved ID into the memoization cache.
     *
     * Used by RowImporter after auto-creating an entity so subsequent rows
     * referencing the same name get the newly created ID without a DB hit.
     */
    public function setCacheEntry(string $key, string $id): void
    {
        $this->cache[$key] = $id;
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Build a single Issue array.
     *
     * All issues built here are catalog-reference warnings: memoized per
     * catalog VALUE (not per row), so they are structurally "general" —
     * they apply once regardless of how many rows reference that same
     * value. Marked at creation time (never inferred from message text)
     * so ImportRunner::validate() can collapse them safely.
     *
     * @return array{row: int, severity: string, column: string, message: string, general: bool}
     */
    private function buildIssue(int $row, string $column, string $severity, string $message): array
    {
        return [
            'row' => $row,
            'severity' => $severity,
            'column' => $column,
            'message' => $message,
            'general' => true,
        ];
    }
}
