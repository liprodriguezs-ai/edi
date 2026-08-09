<?php

declare(strict_types=1);

namespace Modules\Collections\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Collections\Models\Charge;
use Modules\Collections\Services\CollectionsReportService;
use QCH\Core\Http\Controllers\Controller;

/**
 * Read-only collections aging report endpoint.
 *
 * Authorization: read.report_collections against Charge::class.
 */
class CollectionsReportController extends Controller
{
    public function __construct(private readonly CollectionsReportService $service) {}

    /**
     * GET /collections/aging
     * Permission: read.report_collections
     *
     * Returns delinquency aging buckets, collection rate, top debtors, and
     * by-status breakdown. All monetary fields in minor units (_minor suffix).
     */
    public function aging(Request $request): JsonResponse
    {
        $this->authorize('read.report_collections', Charge::class);

        return response()->json($this->service->aging());
    }
}
