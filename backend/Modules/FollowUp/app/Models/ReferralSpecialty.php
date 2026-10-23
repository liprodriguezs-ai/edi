<?php

namespace Modules\FollowUp\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\FollowUp\Database\Factories\ReferralSpecialtyFactory;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * Tenant-scoped, admin-editable specialty catalog (design D6). Pure
 * classification, no branching logic.
 */
#[ObservedBy([GlobalObserver::class])]
class ReferralSpecialty extends BaseModel
{
    use BelongsToTenant;
    use HasFactory;

    protected $table = 'referral_specialty';

    protected $fillable = [
        'name',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    protected static function newFactory(): ReferralSpecialtyFactory
    {
        return ReferralSpecialtyFactory::new();
    }
}
