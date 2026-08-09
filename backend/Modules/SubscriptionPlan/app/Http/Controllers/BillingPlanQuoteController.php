<?php

namespace Modules\SubscriptionPlan\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use InvalidArgumentException;
use Modules\SubscriptionPlan\Exceptions\CheckoutUnavailableException;
use Modules\SubscriptionPlan\Http\Requests\BillingPlanQuoteRequest;
use Modules\SubscriptionPlan\Services\PlanPricingService;

/**
 * Public, unauthenticated quoter for the billing v2 plan×module matrix.
 *
 * ADDITIVE (S2): mirrors BillingQuoteController but reads the new
 * plan_module_price matrix via PlanPricingService instead of the legacy banded
 * PricingService. Runs in parallel to the legacy /billing/quote route — neither
 * touches the other.
 *
 *  - POST /api/billing/plan-quote   → quoteByPlan (plan_key + modules + interval)
 *  - GET  /api/billing/plan-catalog → planCatalog (public plans matrix)
 *
 * PlanPricingService is the server-side authority: client-supplied amounts are
 * ignored. Domain validation failures (missing base module, unknown plan, module
 * not offered by the plan) surface as 422.
 *
 * quote() calls quoteByPlan(..., publicOnly: true): a hidden (is_public=false)
 * or retired (is_active=false) plan 422s with the SAME "Plan inválido" message
 * as an unknown key, so an anonymous caller can never confirm a negotiated
 * plan's existence or read its pricing by guessing its key.
 */
class BillingPlanQuoteController extends Controller
{
    public function quote(BillingPlanQuoteRequest $request, PlanPricingService $planPricingService): JsonResponse
    {
        $validated = $request->validated();

        try {
            $quote = $planPricingService->quoteByPlan(
                $validated['plan_key'],
                // Absent → the plan's full priced module set (modules are no longer
                // a user-facing choice).
                $validated['modules'] ?? [],
                $validated['interval'],
                publicOnly: true,
            );
        } catch (CheckoutUnavailableException $e) {
            // A consultative plan cannot be self-quoted; `code` lets the frontends
            // swap the quoter for the "Contáctanos" card without parsing Spanish.
            return response()->json(['message' => $e->getMessage(), 'code' => $e->reason()], 422);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($quote);
    }

    public function catalog(PlanPricingService $planPricingService): JsonResponse
    {
        return response()->json($planPricingService->planCatalog());
    }
}
