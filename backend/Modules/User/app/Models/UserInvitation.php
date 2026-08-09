<?php

namespace Modules\User\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;
use Modules\Tenant\Models\Tenant;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

class UserInvitation extends Model implements Auditable
{
    use AuditableTrait;
    use CentralConnection;
    use HasUuids;

    /** Default invitation lifetime, in days, when no per-config override is set. */
    public const TTL_DAYS = 7;

    // UUID primary key (the table predates BaseModel; without this the int-cast on
    // an incrementing key would mangle the uuid in route parameters).
    protected $keyType = 'string';

    public $incrementing = false;

    protected $table = 'user_invitation';

    protected $fillable = [
        'token',
        'token_hash',
        'tenant_id',
        'inviteable_type',
        'inviteable_id',
        'unit_relationship_id',
        'email',
        'invited_by_user_id',
        'expires_at',
        'accepted_at',
        'revoked_at',
    ];

    /** Never audit the secret, not even hashed. */
    protected $auditExclude = ['token', 'token_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function inviteable(): MorphTo
    {
        return $this->morphTo('inviteable');
    }

    public function isExpired(): bool
    {
        if ($this->expires_at === null) {
            return false;
        }

        return $this->expires_at->isPast();
    }

    /** A usable invitation: not yet accepted, not revoked, and not expired. */
    public function isValid(): bool
    {
        return $this->accepted_at === null
            && $this->revoked_at === null
            && ! $this->isExpired();
    }

    /** Number of days an invitation stays valid (config override, else TTL_DAYS). */
    public static function ttlDays(): int
    {
        return (int) config('user.invitation_ttl_days', self::TTL_DAYS);
    }

    public static function generateUniqueToken(): string
    {
        do {
            $token = Str::random(64);
        } while (self::where('token_hash', hash('sha256', $token))->exists());

        return $token;
    }
}
