<?php

namespace Modules\Dashboard\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\Dashboard\Services\DashboardService;

class DashboardController extends Controller
{
    public function summary(DashboardService $service): JsonResponse
    {
        return response()->json([
            'data' => $service->summary(),
        ]);
    }
}
