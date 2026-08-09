<?php

declare(strict_types=1);

namespace Modules\Collections\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Collections\Http\Controllers\Concerns\ScopesToResidentUnits;
use Modules\Collections\Services\DelinquencyRestrictionService;
use QCH\Core\Support\TenantMoney;

/**
 * GET /my-restrictions — the resident-facing counterpart of the delinquency
 * guard (design §6.2, R-DELQ-1/R-DELQ-6). No ability gate: the payload only
 * ever describes the CALLER'S own units (ScopesToResidentUnits::ownUnitIds),
 * never anyone else's — any authenticated tenant user may read it.
 *
 * Extends the plain Illuminate\Routing\Controller (sdd-verify WARNING), not
 * QCH\Core\Http\Controllers\Controller: this controller has zero Orion CRUD
 * routes and never sets $model/$keyForPermission — the Orion base class was
 * dead weight that misleadingly suggested this goes through Orion's ability
 * mapping. Matches ConfigurationController, this codebase's precedent for a
 * 100%-custom-action controller.
 */
class DelinquencyRestrictionController extends Controller
{
    use ScopesToResidentUnits;

    public function __construct(private readonly DelinquencyRestrictionService $service) {}

    public function myRestrictions(Request $request): JsonResponse
    {
        $units = array_map(
            function (array $unit): array {
                if (isset($unit['overdue_balance'])) {
                    $unit['overdue_balance'] = TenantMoney::make($unit['overdue_balance']);
                }

                return $unit;
            },
            $this->service->stateForUnits($this->ownUnitIds()),
        );

        return response()->json([
            'restricted' => in_array(true, array_column($units, 'restricted'), true),
            // Reuses the settings() memoization stateForUnits() already warmed above —
            // no extra Setting::getValue() SELECT (infra-cost finding).
            'notice' => $this->service->noticeText(),
            'units' => $units,
        ]);
    }
}
