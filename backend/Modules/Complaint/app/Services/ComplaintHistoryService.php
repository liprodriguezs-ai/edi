<?php

namespace Modules\Complaint\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use Modules\Audit\Models\Audit;
use Modules\Complaint\Models\Complaint;
use Modules\Complaint\Models\ComplaintCategory;
use Modules\Complaint\Models\ComplaintPriority;
use Modules\Complaint\Models\ComplaintStatus;
use Modules\Housing\Models\Unit;

/**
 * Reads the change-history timeline for a Complaint from the existing
 * Laravel Auditing trail (owen-it, polymorphic via BaseModel — every model,
 * including Complaint, is already audited automatically). No new table, no
 * revived HistoryTicket: this service only READS `audits` and translates raw
 * diffs into human-readable entries for the detail page.
 *
 * Reuse check (per review instruction): `Modules\Audit\Http\Controllers\AuditController`
 * only exposes a generic Orion `audit` resource (event/user filters, no
 * auditable_id filter, no diff translation) and
 * `Modules\Reports\Services\ReportService::auditLog()` is a raw `DB::table('audits')`
 * query filtered by auditable_type only (no auditable_id, no translation) —
 * neither is reusable as-is for a single entity's translated timeline, so
 * this is a new, Complaint-specific service. Both existing readers are left
 * untouched.
 */
class ComplaintHistoryService
{
    /** Last N audits is enough for v1 (design decision, matches messages() thread cap style). */
    private const MAX_ENTRIES = 50;

    /**
     * Spanish labels for the ONLY fields ever surfaced in a diff. This is an
     * ALLOWLIST (not a denylist of "noise" columns): any changed column not
     * listed here — every BaseModel audit stamp (tenant_id, created_at,
     * updated_at, deleted_at, user_created_id, user_updated_id,
     * user_deleted_id, user_restored_id), plus the immutable case_number and
     * reporter_user_id (never written after registration) — is silently
     * omitted. This also fails safe for any future column added to the
     * table without updating this map.
     *
     * @var array<string, string>
     */
    private const FIELD_LABELS = [
        'title' => 'Título',
        'against_description' => 'Descripción',
        'complaint_category_id' => 'Categoría',
        'complaint_priority_id' => 'Prioridad',
        'complaint_status_id' => 'Estado',
        'assigned_user_id' => 'Asignado a',
        'against_unit_id' => 'Unidad señalada',
        'resolved_at' => 'Resuelta el',
    ];

    /** @var array<string, string> */
    private array $statusMap = [];

    /** @var array<string, string> */
    private array $categoryMap = [];

    /** @var array<string, string> */
    private array $priorityMap = [];

    /** @var array<string, string> */
    private array $userMap = [];

    /** @var array<string, string> */
    private array $unitMap = [];

    /**
     * @return array<int, array{id: string, event: string, user_name: string, created_at: ?string, changes: array<int, array{field: string, label: string, from: mixed, to: mixed}>}>
     */
    public function history(Complaint $complaint): array
    {
        // The audits table is CENTRAL (no BelongsToTenant/TenantScope) — mirrors
        // AuditController's manual tenant filter, defense-in-depth even though
        // auditable_id (a UUID) alone already can't collide across tenants.
        $audits = Audit::where('auditable_type', Complaint::class)
            ->where('auditable_id', $complaint->id)
            ->where('tenant_id', tenant('id'))
            ->with('user:id,name,last_name,second_last_name')
            ->orderByDesc('created_at')
            ->limit(self::MAX_ENTRIES)
            ->get();

        $this->buildLookupMaps($audits);

        return $audits->map(fn (Audit $audit): array => [
            'id' => $audit->id,
            'event' => $audit->event,
            'user_name' => $this->userDisplayName($audit->user),
            'created_at' => $audit->created_at?->toIso8601String(),
            // "created" is rendered by the frontend as a fixed "Incidencia
            // registrada" milestone — a full attribute diff at creation would
            // be noisy (every column changes from null) and adds no value.
            'changes' => $audit->event === 'created' ? [] : $this->diffChanges($audit),
        ])->all();
    }

    /**
     * Collect every catalog/user/unit id referenced across ALL audits' old
     * AND new values in ONE pass, then resolve each lookup table in a single
     * whereIn() query (zero N+1 regardless of how many audits/fields touch
     * the same or different ids).
     *
     * @param  Collection<int, Audit>  $audits
     */
    private function buildLookupMaps(Collection $audits): void
    {
        $statusIds = [];
        $categoryIds = [];
        $priorityIds = [];
        $userIds = [];
        $unitIds = [];

        foreach ($audits as $audit) {
            foreach ([$this->decodeValues($audit->old_values), $this->decodeValues($audit->new_values)] as $values) {
                if (! empty($values['complaint_status_id'])) {
                    $statusIds[] = $values['complaint_status_id'];
                }
                if (! empty($values['complaint_category_id'])) {
                    $categoryIds[] = $values['complaint_category_id'];
                }
                if (! empty($values['complaint_priority_id'])) {
                    $priorityIds[] = $values['complaint_priority_id'];
                }
                if (! empty($values['assigned_user_id'])) {
                    $userIds[] = $values['assigned_user_id'];
                }
                if (! empty($values['against_unit_id'])) {
                    $unitIds[] = $values['against_unit_id'];
                }
            }
        }

        $this->statusMap = ComplaintStatus::whereIn('id', array_unique($statusIds))->pluck('name', 'id')->all();
        $this->categoryMap = ComplaintCategory::whereIn('id', array_unique($categoryIds))->pluck('name', 'id')->all();
        $this->priorityMap = ComplaintPriority::whereIn('id', array_unique($priorityIds))->pluck('name', 'id')->all();

        $this->userMap = User::whereIn('id', array_unique($userIds))
            ->get(['id', 'name', 'last_name', 'second_last_name'])
            ->mapWithKeys(fn (User $user): array => [$user->id => $this->userDisplayName($user)])
            ->all();

        // full_identifier is a computed accessor (Unit::fullIdentifier) that reads
        // unitType/tower — eager load both so it never lazy-loads per unit.
        $this->unitMap = Unit::with(['unitType', 'tower'])
            ->whereIn('id', array_unique($unitIds))
            ->get()
            ->mapWithKeys(fn (Unit $unit): array => [$unit->id => $unit->full_identifier])
            ->all();
    }

    /**
     * @return array<int, array{field: string, label: string, from: mixed, to: mixed}>
     */
    private function diffChanges(Audit $audit): array
    {
        $old = $this->decodeValues($audit->old_values);
        $new = $this->decodeValues($audit->new_values);
        $fields = array_unique([...array_keys($old), ...array_keys($new)]);

        $changes = [];
        foreach ($fields as $field) {
            if (! isset(self::FIELD_LABELS[$field])) {
                continue;
            }

            $changes[] = [
                'field' => $field,
                'label' => self::FIELD_LABELS[$field],
                'from' => $this->resolveValue($field, $old[$field] ?? null),
                'to' => $this->resolveValue($field, $new[$field] ?? null),
            ];
        }

        return $changes;
    }

    private function resolveValue(string $field, mixed $value): mixed
    {
        if ($value === null) {
            return match ($field) {
                'assigned_user_id' => 'Sin asignar',
                'against_unit_id' => 'Sin unidad',
                default => null,
            };
        }

        return match ($field) {
            'complaint_status_id' => $this->statusMap[$value] ?? 'Desconocido',
            'complaint_category_id' => $this->categoryMap[$value] ?? 'Desconocido',
            'complaint_priority_id' => $this->priorityMap[$value] ?? 'Desconocido',
            'assigned_user_id' => $this->userMap[$value] ?? 'Desconocido',
            'against_unit_id' => $this->unitMap[$value] ?? 'Desconocido',
            // title, against_description, resolved_at: plain passthrough — dates
            // stay ISO, the frontend formats them (displayFormatDate convention).
            default => $value,
        };
    }

    /**
     * old_values/new_values SHOULD already be PHP arrays after the model's
     * 'array' cast — but a pre-existing bug in QCH\Core\Models\Audit::save()
     * DOUBLE-JSON-ENCODES them whenever the audited mutation happens inside a
     * DB::transaction() (the deferred DB::afterCommit() path re-serializes an
     * attribute that was already serialized once by the cast), which is the
     * norm across this codebase's service layer — every ComplaintService
     * mutation is an example. When that happens, the cast leaves a JSON
     * STRING sitting where an array was expected. This mirrors the exact
     * same defensive re-decode `AuditResource::decodeJsonAttribute()` already
     * applies for the generic /api/audit endpoint (not a new workaround, an
     * established one) — the root cause was reported separately rather than
     * fixed here (touches a shared core model used by every audited model
     * platform-wide, out of scope for this feature).
     *
     * @return array<string, mixed>
     */
    private function decodeValues(mixed $values): array
    {
        if (is_array($values)) {
            return $values;
        }

        if (is_string($values)) {
            $decoded = json_decode($values, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private function userDisplayName(?User $user): string
    {
        if ($user === null) {
            return 'Sistema';
        }

        return trim($user->name.' '.$user->last_name.' '.($user->second_last_name ?? ''));
    }
}
