<?php

namespace App\Models;

use App\Casts\AppTimezoneCast;
use App\Models\Scopes\UserTenantScope;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Cashier\Billable;
use Laravel\Sanctum\HasApiTokens;
use Modules\Email\Services\SystemMailer;
use Modules\Notification\Models\Notification;
use Modules\Tenant\Models\Tenant;
use Modules\User\Emails\ResetPasswordMail;
use Modules\UserProfile\Models\UserProfile;
use QCH\Core\Models\GlobalObserver;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\Permission\Traits\HasRoles;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

#[ObservedBy([GlobalObserver::class])]
class User extends Authenticatable implements HasMedia
{
    use Billable;
    use CentralConnection;
    use HasApiTokens;
    use HasFactory;
    use HasRoles;
    use HasUuids;
    use InteractsWithMedia;
    use Notifiable;
    use SoftDeletes;

    protected array $guard_name = ['api'];

    protected $table = 'user';

    protected $appends = [
        'full_name',
        'profile_image_url',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'last_name',
        'second_last_name',
        'phone',
        'email',
        'password',
        'birthdate',
        'genre_id',
        'municipality_id',
        'state_id',
        'city',
        'verification_code', // se agg
        'email_verified_at', // se agg
        'code',
        'onboarding',
        'onboarding_completed_at',
        'address',
        'receive_newsletter',
        'token_sender',
        'update_password',
        'deleted_reason',
        'deleted_request_user_id',
        'user_register_status_id',
        'user_created_id',
        'user_updated_id',
        'user_deleted_id',
        'user_restored_id',
        'auth_active',
        'last_active_tenant_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'verification_code',
    ];

    /**
     * §18.2/§18.3 (global-07) — credentials and push tokens must never land in
     * the audit trail, not even hashed.
     *
     * @var list<string>
     */
    protected $auditExclude = [
        'password',
        'remember_token',
        'verification_code',
        'token_sender',
    ];

    /**
     * Determine if the user is an administrator.
     */
    protected function fullName(): Attribute
    {
        return new Attribute(
            get: fn () => $this->name.' '.$this->last_name.' '.($this->second_last_name ?? '')
        );
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'onboarding_completed_at' => 'datetime',
            'password' => 'hashed',
            'gps_latitude' => 'decimal:8',
            'gps_longitude' => 'decimal:8',
            'is_blacklisted' => 'boolean',
            'auth_active' => 'boolean',
            // Cashier's Billable generic-trial column — normalized to app
            // timezone, same reasoning as Subscription::casts(); see AppTimezoneCast.
            'trial_ends_at' => AppTimezoneCast::class,
        ];
    }
    // public function cash_register_session()
    // {
    //     return $this->hasMany(CashRegisterSession::class, 'user_id');
    // }

    public function notifications()
    {
        return $this->hasMany(Notification::class);
    }

    /**
     * Terms & Conditions acceptances recorded for this user (one per version).
     */
    public function termsAcceptances(): HasMany
    {
        return $this->hasMany(TermsAcceptance::class, 'user_id');
    }

    /**
     * The user's notification delivery preferences (central, one row per user).
     */
    public function notificationPreference(): HasOne
    {
        return $this->hasOne(NotificationPreference::class, 'user_id');
    }

    /**
     * Whether this user has accepted the current Terms & Conditions version.
     */
    public function hasAcceptedCurrentTerms(): bool
    {
        $version = (string) config('legal.terms_version', '1.0');

        return TermsAcceptance::hasAccepted($this->id, $version);
    }

    /**
     * Send a password reset notification to the user.
     *
     * @param  string  $token
     */
    public function sendPasswordResetNotification($token): void
    {
        $url = config('app.frontend_url').'/auth/reset-password/'.$token.'/'.urlencode($this->email);

        // Password reset is a security email: always delivered through the
        // canonical SystemMailer (Mail transport + email:: Blade template),
        // never the gated notification engine.
        app(SystemMailer::class)->send($this->email, new ResetPasswordMail($url, $this));
    }

    /**
     * @return TMedia|null
     */
    public function getIdMedia(string $collectionName = 'id'): ?Media
    {
        $media = $this->getMedia($collectionName);

        return $media->first();
    }

    public function getProfileImage(): ?Media
    {
        return $this->getMedia('profile_image')->sortByDesc('created_at')->first();
    }

    public function getProfileImageUrlAttribute(): ?string
    {
        return $this->getProfileImage()?->getFullUrl();
    }

    /**
     * Tenants the user belongs to (many-to-many via user_tenant pivot).
     */
    public function tenants()
    {
        return $this->belongsToMany(
            Tenant::class,
            'user_tenant',
            'user_id',
            'tenant_id'
        )->withTimestamps();
    }

    /**
     * The tenant the user last explicitly selected (multi-cluster support).
     * Central FK → tenant; no tenant scope needed (user is central).
     */
    public function lastActiveTenant()
    {
        return $this->belongsTo(Tenant::class, 'last_active_tenant_id');
    }

    /**
     * Per-cluster PII profiles (one per tenant the user belongs to).
     */
    public function profiles()
    {
        return $this->hasMany(UserProfile::class, 'user_id');
    }

    /**
     * Returns the UserProfile row for the currently initialized tenant, or null.
     * Only meaningful inside a tenant context (after initializeTenancy runs).
     */
    public function currentProfile(): ?UserProfile
    {
        if (! tenancy()->initialized || ! tenant()) {
            return null;
        }

        return $this->profiles()->where('tenant_id', tenant()->getTenantKey())->first();
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new UserTenantScope);
    }
}
