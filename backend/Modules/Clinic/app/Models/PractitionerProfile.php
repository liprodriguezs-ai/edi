<?php

namespace Modules\Clinic\Models;

use App\Models\Scopes\UserTenantScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Clinic\Database\Factories\PractitionerProfileFactory;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

#[ObservedBy([GlobalObserver::class])]
class PractitionerProfile extends BaseModel
{
    use BelongsToTenant;
    use HasFactory;

    protected $table = 'practitioner_profile';

    protected $fillable = [
        'user_id',
        'license_number',
        'specialty',
        'health_center_id',
    ];

    /**
     * The practitioner's identity: their NAME lives here, not on the profile row,
     * so every "Profesional" dropdown and column has to eager-load this.
     *
     * @Relation
     *
     * That annotation is not decoration. The /selects query parser calls
     * isRelation(), which reads this docblock and rejects anything without
     * `@Relation` as an unknown relation -- even one the model plainly declares,
     * and even one explicitly allow-listed. Without it the practitioner catalog
     * came back empty and its dropdown was unusable.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')
            ->withoutGlobalScope(UserTenantScope::class);
    }

    public function health_center(): BelongsTo
    {
        return $this->belongsTo(HealthCenter::class, 'health_center_id');
    }

    protected static function newFactory(): PractitionerProfileFactory
    {
        return PractitionerProfileFactory::new();
    }
}
