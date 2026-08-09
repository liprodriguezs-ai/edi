<?php

namespace App\Models;

use App\Models\Scopes\UserTenantScope;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * TermsAcceptance — a user's acceptance of a Terms & Conditions version.
 *
 * Central model (no tenant scope): T&C acceptance is a platform-level legal record
 * keyed by (user, version). Re-accepting the same version is idempotent thanks to the
 * unique(user_id, version) index; a new version inserts a new row.
 */
#[ObservedBy([GlobalObserver::class])]
class TermsAcceptance extends BaseModel
{
    use CentralConnection;
    use HasFactory;

    protected $table = 'terms_acceptance';

    protected $fillable = [
        'user_id',
        'version',
        'accepted_at',
        'ip',
    ];

    protected function casts(): array
    {
        return [
            'accepted_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')
            ->withoutGlobalScope(UserTenantScope::class);
    }

    /**
     * Whether the given user has accepted the supplied T&C version.
     */
    public static function hasAccepted(string $userId, string $version): bool
    {
        return static::query()
            ->where('user_id', $userId)
            ->where('version', $version)
            ->exists();
    }

    /**
     * Record (idempotently) the user's acceptance of a version. Re-accepting the
     * same version refreshes accepted_at/ip; a new version inserts a new row.
     */
    public static function record(string $userId, string $version, ?string $ip = null): self
    {
        return static::updateOrCreate(
            ['user_id' => $userId, 'version' => $version],
            ['accepted_at' => now(), 'ip' => $ip],
        );
    }
}
