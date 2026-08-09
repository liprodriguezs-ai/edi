<?php

namespace Modules\Complaint\Support;

use Modules\Housing\Models\UnitUser;
use Modules\Setting\Models\Setting;

/**
 * Shared complaint-manager access rules (design ADR-4/ADR-5). Single source of
 * truth for the manager-role-keys config and the unit-linkage exclusion set,
 * consumed by ComplaintService and ComplaintMessageService — previously each
 * service carried its own byte-identical copy of both (review finding W1).
 */
final class ComplaintAccess
{
    /** Fallback default mirrored from the module-config manifest (design §10), used
     *  when no per-tenant Setting row exists yet (e.g. before module-config seeds it). */
    private const DEFAULT_MANAGER_ROLE_KEYS = ['president', 'treasurer', 'secretary', 'vocal', 'oversight_committee', 'administrator'];

    /**
     * @return array<int, string>
     */
    public static function managerRoleKeys(): array
    {
        $configured = Setting::getValue('complaint.manager_role_keys');

        return is_array($configured) && $configured !== [] ? $configured : self::DEFAULT_MANAGER_ROLE_KEYS;
    }

    /**
     * Confidential exclusion (ADR-4, spec "Confidential Exclusion by Unit
     * Linkage"): the active unit_user user ids of $unitId. Empty when $unitId
     * is null — no automatic exclusion applies.
     *
     * @return array<int, string>
     */
    public static function excludedUserIds(?string $unitId): array
    {
        if ($unitId === null) {
            return [];
        }

        return UnitUser::where('unit_id', $unitId)
            ->whereNull('end_date')
            ->pluck('user_id')
            ->unique()
            ->values()
            ->all();
    }
}
