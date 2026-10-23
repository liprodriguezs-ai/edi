<?php

namespace Modules\Stimulation\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;
use Modules\Stimulation\Http\Requests\StimulationGroupMemberCreateRequest;
use Modules\Stimulation\Http\Requests\StimulationGroupMemberUpdateRequest;
use Modules\Stimulation\Models\StimulationGroupMember;
use Orion\Http\Requests\Request;
use QCH\Core\Http\Controllers\Controller;
use QCH\Core\Support\TenantTime;

/**
 * D10 — stimulation_group_member is a sub-resource of the group (clinic
 * child_caregiver precedent) and authorizes under the SAME permission as
 * `stimulation_group`.
 */
class StimulationGroupMemberController extends Controller
{
    protected $model = StimulationGroupMember::class;

    protected $keyForPermission = 'stimulation_group';

    public function includes(): array
    {
        return ['stimulation_group', 'child', 'default_attendance_payment_status'];
    }

    public function filterableBy(): array
    {
        return ['stimulation_group_id', 'child_id'];
    }

    protected function performStore(Request $request, Model $entity, array $attributes): void
    {
        $validated = app(StimulationGroupMemberCreateRequest::class)->validated();
        $validated['enrolled_at'] ??= TenantTime::today();
        $entity->fill($validated);
        $this->saveOrConflict($entity);
    }

    protected function performUpdate(Request $request, Model $entity, array $attributes): void
    {
        $validated = app(StimulationGroupMemberUpdateRequest::class)->validated();
        $entity->fill($validated);
        $this->saveOrConflict($entity);
    }

    /**
     * REQ-SG-002 — the FormRequest's per-tenant Rule::unique pre-check can be
     * beaten by a genuine race; the DB partial unique index is the real
     * backstop, mapped to a friendly 422 instead of a raw 500.
     */
    private function saveOrConflict(Model $entity): void
    {
        try {
            $entity->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'child_id' => ['Este niño ya está inscrito en el grupo.'],
            ]);
        }
    }
}
