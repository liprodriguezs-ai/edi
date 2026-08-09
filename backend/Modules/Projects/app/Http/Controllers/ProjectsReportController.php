<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Projects\Models\CommunityProject;
use Modules\Projects\Services\ProjectsReportService;
use QCH\Core\Http\Controllers\Controller;

/**
 * Read-only projects funding-summary report endpoint.
 *
 * Authorization: read.report_projects against CommunityProject::class.
 */
class ProjectsReportController extends Controller
{
    public function __construct(private readonly ProjectsReportService $service) {}

    /**
     * GET /projects/funding-summary
     * Permission: read.report_projects
     *
     * Returns per-project goal vs raised breakdown, totals, and by-status
     * grouping. All monetary fields in minor units (_minor suffix).
     */
    public function fundingSummary(Request $request): JsonResponse
    {
        $this->authorize('read.report_projects', CommunityProject::class);

        return response()->json($this->service->fundingSummary());
    }
}
