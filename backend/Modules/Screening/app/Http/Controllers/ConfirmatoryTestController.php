<?php

namespace Modules\Screening\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Modules\Instrument\Models\ResultColor;
use Modules\Screening\Exceptions\ScreeningDomainException;
use Modules\Screening\Http\Requests\ConfirmatoryTestCreateRequest;
use Modules\Screening\Http\Requests\ConfirmatoryTestUpdateRequest;
use Modules\Screening\Models\ConfirmatoryTest;
use Modules\Screening\Models\Screening;
use Modules\Screening\Models\ScreeningStatus;
use Orion\Http\Requests\Request;
use QCH\Core\Http\Controllers\Controller;

/**
 * REQ-SCR-014 — creatable only for a `completed`, red screening; at most one
 * non-deleted row per screening (DB partial unique).
 */
class ConfirmatoryTestController extends Controller
{
    protected $model = ConfirmatoryTest::class;

    protected $keyForPermission = 'confirmatory_test';

    public function includes(): array
    {
        return ['screening', 'child', 'confirmatory_test_result'];
    }

    public function filterableBy(): array
    {
        return ['screening_id', 'child_id'];
    }

    public function alwaysIncludes(): array
    {
        return $this->includes();
    }

    protected function performStore(Request $request, Model $entity, array $attributes): void
    {
        $validated = app(ConfirmatoryTestCreateRequest::class)->validated();

        $screening = Screening::findOrFail($validated['screening_id']);

        if ($screening->screening_status_id !== ScreeningStatus::COMPLETED
            || $screening->result_color_id !== ResultColor::RED) {
            throw ScreeningDomainException::confirmatoryNotApplicable();
        }

        $entity->forceFill([...$validated, 'child_id' => $screening->child_id]);
        $entity->save();
    }

    protected function performUpdate(Request $request, Model $entity, array $attributes): void
    {
        $validated = app(ConfirmatoryTestUpdateRequest::class)->validated();
        $entity->fill($validated);
        $entity->save();
    }
}
