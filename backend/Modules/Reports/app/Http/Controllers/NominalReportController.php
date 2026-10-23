<?php

namespace Modules\Reports\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Modules\Screening\Models\Screening;
use Modules\Screening\Models\ScreeningStatus;
use QCH\Core\Http\Controllers\Controller;

/**
 * PII-bearing nominal listing (design D8, REQ-RPT-006). A Reports-owned
 * Orion resource over the Screening model, gated by `nominal_report` --
 * DIFFERENT from `read.screening` (Screening CRUD stays under
 * `/api/screening`). Only `index`/`search`/`show` are registered; this is a
 * read-only reporting surface, never a write path.
 */
class NominalReportController extends Controller
{
    protected $model = Screening::class;

    protected $keyForPermission = 'nominal_report';

    /**
     * Base query is restricted to the counted set (REQ-RPT-002/006):
     * non-invalidated screenings with a result. Result-less drafts never
     * appear in the nominal listing.
     */
    public function newModelQuery(): Builder
    {
        return Screening::query()
            ->join('visit', 'visit.id', '=', 'screening.visit_id')
            ->whereNull('visit.deleted_at')
            ->where('screening.screening_status_id', '!=', ScreeningStatus::INVALIDATED)
            ->whereNotNull('screening.result_color_id')
            ->select('screening.*');
    }

    public function includes(): array
    {
        return [
            'child', 'child.sex',
            'visit', 'visit.health_center', 'visit.practitioner', 'visit.practitioner.user',
            'applied_age_group', 'result_color', 'situation',
            'area_results', 'area_results.development_area', 'area_results.result_color',
        ];
    }

    public function alwaysIncludes(): array
    {
        return $this->includes();
    }

    public function filterableBy(): array
    {
        return [
            'visit.visit_date',
            'visit.health_center_id',
            'result_color_id',
            'situation_id',
            'applied_age_group_id',
        ];
    }

    public function searchableBy(): array
    {
        return ['child.first_name', 'child.last_name_paternal', 'child.last_name_maternal', 'child.curp'];
    }

    public function sortableBy(): array
    {
        return ['visit.visit_date'];
    }
}
