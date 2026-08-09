<?php

namespace Modules\UserProfile\Models;

use App\Models\Scopes\UserTenantScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\UserProfile\Database\Factories\UserProfileFactory;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

#[ObservedBy([GlobalObserver::class])]
class UserProfile extends BaseModel
{
    use BelongsToTenant;
    use HasFactory;

    protected $table = 'user_profile';

    protected $fillable = [
        'user_id',
        'rfc',
        'curp',
        'birthdate',
        'address',
        'emergency_contact_name',
        'emergency_contact_phone',
        'notes',
        'personal_qr_token',
        'personal_qr_expires_at',
    ];

    /**
     * Plan 048: personal_qr_token is a LIVE entry credential. Keep it out of any
     * default serialization — it is only ever returned via the dedicated
     * /access/my-qr array, never an Orion model payload.
     *
     * @var list<string>
     */
    protected $hidden = [
        'personal_qr_token',
    ];

    /**
     * §18.3 (global-07) — PII values must not be recorded verbatim in the
     * audit trail; the audit keeps WHO changed the profile and WHEN, never
     * the sensitive payload.
     *
     * @var list<string>
     */
    protected $auditExclude = [
        'rfc',
        'curp',
        'address',
        'emergency_contact_name',
        'emergency_contact_phone',
        'notes',
        // Plan 048: live rotating credential — never record it verbatim in audit.
        'personal_qr_token',
    ];

    protected function casts(): array
    {
        return [
            'birthdate' => 'date',
            'personal_qr_expires_at' => 'datetime',
        ];
    }

    /**
     * The user this profile belongs to (central table — bypass UserTenantScope to avoid cross-join issues).
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')
            ->withoutGlobalScope(UserTenantScope::class);
    }

    protected static function newFactory(): UserProfileFactory
    {
        return UserProfileFactory::new();
    }
}
