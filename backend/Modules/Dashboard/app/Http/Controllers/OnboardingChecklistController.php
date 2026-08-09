<?php

namespace Modules\Dashboard\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Dashboard\Services\OnboardingChecklistService;

class OnboardingChecklistController extends Controller
{
    public function index(Request $request, OnboardingChecklistService $service): JsonResponse
    {
        return response()->json([
            'data' => $service->checklist($request->user()),
        ]);
    }
}
