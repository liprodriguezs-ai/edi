<?php

namespace Modules\SubscriptionPlan\Http\Controllers;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Modules\SubscriptionPlan\Http\Requests\PlanModulePriceMatrixRequest;
use Modules\SubscriptionPlan\Models\BillableModule;
use Modules\SubscriptionPlan\Models\BillingPackage;
use Modules\SubscriptionPlan\Models\PlanModule;
use Modules\SubscriptionPlan\Models\PlanModulePrice;

/**
 * Super-admin editor of the billing v2 price MATRIX (plan_module_price +
 * plan_module) for one plan:
 *   - GET  billing/plan/{billingPackage}/price-matrix  read every module row (flags) × cell (amounts)
 *   - PUT  billing/plan/{billingPackage}/price-matrix  bulk upsert the LOCAL flags + cell data
 *
 * Central catalog — no tenancy. Custom (non-Orion) controller: it owns its
 * constructor and gate. The gate mirrors the billing_package CRUD entity
 * (read for GET, update for PUT); super-admin exclusive via the seeded matrix.
 *
 * v2 contract: is_free/trial_days are (plan, module) facts and live on the
 * module row (sourced from plan_module); cells carry ONLY unit_amount /
 * currency / is_active / stripe_price_id. The Stripe ids
 * (stripe_price_id / stripe_product_id) belong to billing:sync-stripe and are
 * NEVER touched here.
 */
class PlanModulePriceController extends Controller
{
    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            $user = auth()->guard('sanctum')->user();
            $ability = $request->isMethod('get') ? 'read.billing_package' : 'update.billing_package';

            if (! $user || ! ($user->tokenCan($ability) || $user->tokenCan('*'))) {
                throw new AuthorizationException('Unauthorized action.');
            }

            return $next($request);
        });
    }

    /**
     * The full matrix of a plan: every ACTIVE billable module (even those without
     * a cell yet, so the UI can create them) × interval, with the current cell or
     * null. is_free/trial_days are hoisted onto the module row (sourced from
     * plan_module; false/null when the module has no plan_module row yet).
     * stripe_price_id is exposed READ-ONLY so the UI can show whether a cell is
     * already synced to Stripe.
     */
    public function show(BillingPackage $billingPackage): JsonResponse
    {
        $modules = BillableModule::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $prices = PlanModulePrice::query()
            ->where('billing_package_id', $billingPackage->id)
            ->get()
            ->keyBy(fn (PlanModulePrice $price): string => $price->billable_module_id.'|'.$price->interval);

        $flags = PlanModule::query()
            ->where('billing_package_id', $billingPackage->id)
            ->get()
            ->keyBy('billable_module_id');

        $rows = $modules->map(function (BillableModule $module) use ($prices, $flags): array {
            $cells = [];

            foreach (PlanModulePrice::INTERVALS as $interval) {
                $price = $prices->get($module->id.'|'.$interval);

                $cells[$interval] = $price === null ? null : [
                    'id' => $price->id,
                    'unit_amount' => (int) $price->unit_amount,
                    'currency' => $price->currency,
                    'is_active' => (bool) $price->is_active,
                    // Read-only: filled by billing:sync-stripe, never by this editor.
                    'stripe_price_id' => $price->stripe_price_id,
                ];
            }

            $moduleFlags = $flags->get($module->id);

            return [
                'billable_module_id' => $module->id,
                'key' => $module->key,
                'name' => $module->name,
                'is_base' => (bool) $module->is_base,
                'is_free' => (bool) ($moduleFlags?->is_free ?? false),
                'trial_days' => $moduleFlags?->trial_days,
                'cells' => $cells,
            ];
        });

        return response()->json([
            'data' => [
                'plan' => [
                    'id' => $billingPackage->id,
                    'key' => $billingPackage->key,
                    'name' => $billingPackage->name,
                ],
                'intervals' => PlanModulePrice::INTERVALS,
                // Platform fallback applied when a module's trial_days is null,
                // so the editor can show what an empty field really means.
                'default_trial_days' => (int) config('billing.trial_days'),
                'modules' => $rows->values(),
            ],
        ]);
    }

    /**
     * Bulk upsert the plan's modules (one transaction): the (plan, module) flags
     * row first, then both interval cells. Matches on (plan, module) for flags
     * and (plan, module, interval) for cells, writing ONLY the local price data —
     * the Stripe ids are omitted, so they are preserved on update and left null
     * (DB default) on create; is_active is likewise left to the DB default /
     * existing value.
     */
    public function update(PlanModulePriceMatrixRequest $request, BillingPackage $billingPackage): JsonResponse
    {
        $modules = $request->validated('modules');

        DB::transaction(function () use ($modules, $billingPackage): void {
            foreach ($modules as $entry) {
                PlanModule::updateOrCreate(
                    [
                        'billing_package_id' => $billingPackage->id,
                        'billable_module_id' => $entry['billable_module_id'],
                    ],
                    [
                        'is_free' => (bool) ($entry['is_free'] ?? false),
                        'trial_days' => $entry['trial_days'] ?? null,
                    ],
                );

                foreach (PlanModulePrice::INTERVALS as $interval) {
                    $cell = $entry['cells'][$interval];

                    PlanModulePrice::updateOrCreate(
                        [
                            'billing_package_id' => $billingPackage->id,
                            'billable_module_id' => $entry['billable_module_id'],
                            'interval' => $interval,
                        ],
                        [
                            'unit_amount' => (int) $cell['unit_amount'],
                            'currency' => strtoupper($cell['currency'] ?? 'MXN'),
                        ],
                    );
                }
            }
        });

        return $this->show($billingPackage);
    }
}
