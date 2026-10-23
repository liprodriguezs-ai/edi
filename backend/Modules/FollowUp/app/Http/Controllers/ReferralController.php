<?php

namespace Modules\FollowUp\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Modules\FollowUp\Http\Requests\ReferralCreateRequest;
use Modules\FollowUp\Http\Requests\ReferralUpdateRequest;
use Modules\FollowUp\Models\Referral;
use Modules\FollowUp\Services\ReferralService;
use Orion\Http\Requests\Request;
use QCH\Core\Http\Controllers\Controller;

/**
 * REQ-FUP-007 -- N referrals per follow-up, gated to type specialist_referral.
 */
class ReferralController extends Controller
{
    protected $model = Referral::class;

    protected $keyForPermission = 'referral';

    public function includes(): array
    {
        return ['follow_up', 'referral_specialty'];
    }

    public function filterableBy(): array
    {
        return ['follow_up_id'];
    }

    public function alwaysIncludes(): array
    {
        return $this->includes();
    }

    protected function performStore(Request $request, Model $entity, array $attributes): void
    {
        $validated = app(ReferralCreateRequest::class)->validated();

        /** @var Referral $entity */
        app(ReferralService::class)->create($entity, $validated);
    }

    protected function performUpdate(Request $request, Model $entity, array $attributes): void
    {
        $validated = app(ReferralUpdateRequest::class)->validated();
        $entity->fill($validated);
        $entity->save();
    }
}
