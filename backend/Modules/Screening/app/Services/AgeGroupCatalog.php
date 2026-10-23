<?php

declare(strict_types=1);

namespace Modules\Screening\Services;

use Illuminate\Support\Collection;
use Modules\Instrument\Models\AgeGroup;
use Modules\Instrument\Models\InstrumentVersion;

/**
 * The official age groups a tenant user may pick for a "Prueba EDI a
 * elección" (odd/tasks/edi-legacy-parity.md S2). Served by the Screening
 * module because the raw instrument catalog (/api/age_group) is a
 * platform-admin tool closed to organization roles.
 */
final class AgeGroupCatalog
{
    /** The only fields the picker and the calculator render. */
    public const COLUMNS = [
        'id', 'code', 'name', 'ordinal',
        'min_age_days', 'max_age_days', 'min_age_months', 'max_age_months',
        'materials_note',
    ];

    /**
     * Same version resolution as ScreeningApplicationService::createDraft: the
     * default published version, the one a new draft is frozen to.
     *
     * @return Collection<int, AgeGroup>
     */
    public function officialGroupsOfDefaultVersion(): Collection
    {
        $version = InstrumentVersion::query()
            ->where('is_default', true)
            ->whereNotNull('published_at')
            ->firstOrFail();

        return AgeGroup::query()
            ->where('instrument_version_id', $version->id)
            ->where('is_alternate', false)
            ->orderBy('ordinal')
            ->get(self::COLUMNS);
    }
}
