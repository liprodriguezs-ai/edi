<?php

namespace Modules\FollowUp\Http\Controllers;

use Modules\FollowUp\Models\ReferralSpecialty;
use QCH\Core\Http\Controllers\Controller;

/**
 * Tenant catalog CRUD (design D6/D13) — pure classification, no branching
 * logic, admin-editable. Mirrors CaregiverRelationshipController.
 */
class ReferralSpecialtyController extends Controller
{
    protected $model = ReferralSpecialty::class;

    protected $keyForPermission = 'referral_specialty';

    public function sortableBy(): array
    {
        return ['name', 'is_active', 'created_at'];
    }

    public function searchableBy(): array
    {
        return ['name'];
    }

    public function filterableBy(): array
    {
        return ['is_active'];
    }
}
