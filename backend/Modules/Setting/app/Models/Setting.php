<?php

namespace Modules\Setting\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\UniqueConstraintViolationException;
use InvalidArgumentException;
use Modules\Company\Models\Company;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

#[ObservedBy([GlobalObserver::class])]
class Setting extends BaseModel
{
    use BelongsToTenant;
    use HasFactory;

    protected $table = 'setting';

    public const TYPE_BOOLEAN = 'boolean';

    public const TYPE_STRING = 'string';

    public const TYPE_INTEGER = 'integer';

    public const TYPE_JSON = 'json';

    protected $fillable = ['name', 'description', 'slug', 'value', 'type'];

    /**
     * Permanent write-time invariant: the decoded `value` (after the `json`
     * cast) must match the shape its declared `type` promises. This is the
     * ONLY thing that stops a writer that still passes a pre-serialized
     * string (e.g. `'true'`, `'[3,1,0]'`) from silently double-encoding —
     * the cast would otherwise happily json_encode() an already-serialized
     * string into a JSON string literal, and every strict `=== true`/`===
     * false` feature gate downstream would flip to always-false with no
     * crash. Every Eloquent write funnels through `saving` (including
     * `putValue()`), so this single hook covers `create()`, `firstOrCreate()`,
     * `updateOrCreate()`, and `fill()->save()` alike. It does NOT probe
     * `type=string` values for JSON-ness — `json_encode('foo')` is also a
     * string, so that direction is undecidable and deliberately left alone
     * (see design D2 — cosmetic-only risk, covered by round-trip tests).
     */
    protected static function booted(): void
    {
        static::saving(function (self $setting): void {
            $type = $setting->type ?? self::TYPE_STRING;
            $value = $setting->value;

            if ($value === null) {
                return;
            }

            $isValid = match ($type) {
                self::TYPE_BOOLEAN => is_bool($value),
                self::TYPE_INTEGER => is_int($value),
                self::TYPE_JSON => is_array($value),
                default => is_string($value),
            };

            if (! $isValid) {
                throw new InvalidArgumentException(sprintf(
                    "Setting '%s': type '%s' rejects a %s value.",
                    $setting->slug,
                    $type,
                    get_debug_type($value)
                ));
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'tenant_id');
    }

    /**
     * Resolve a single setting's value by slug for the current tenant.
     *
     * Returns the type-cast value (see getResolvedValueAttribute) or null when the
     * setting row is absent for this tenant — callers fall back to their default.
     */
    public static function getValue(string $slug): mixed
    {
        return static::query()->where('slug', $slug)->first()?->resolved_value;
    }

    /**
     * Write a setting value for the current tenant, trash-aware.
     *
     * A soft-deleted row STILL occupies the (tenant_id, slug) unique key — the index
     * is not partial — but the SoftDeletes global scope hides it from a plain
     * updateOrCreate(), which then attempts an INSERT and dies with
     * "duplicate key value violates unique constraint setting_tenant_id_slug_unique".
     * So we look WITH trashed, restore when needed, and only insert when the slug is
     * genuinely absent. The catch covers the concurrent-writer race (two saves landing
     * between our read and our write).
     *
     * @param  mixed  $value  Native PHP value matching $type (bool/int/array/string/null) — the
     *                        `json` cast serializes it; do NOT pre-serialize (json_encode/(string) cast)
     *                        before calling this, the write-time guard rejects that.
     * @param  string  $type  One of the TYPE_* constants; drives the write-time type guard.
     */
    public static function putValue(string $slug, mixed $value, string $type): static
    {
        $payload = [
            'name' => $slug,
            'slug' => $slug,
            'value' => $value,
            'type' => $type,
        ];

        $existing = static::withTrashed()->where('slug', $slug)->first();

        if ($existing === null) {
            try {
                return static::create($payload);
            } catch (UniqueConstraintViolationException) {
                $existing = static::withTrashed()->where('slug', $slug)->firstOrFail();
            }
        }

        if ($existing->trashed()) {
            $existing->restore();
        }

        $existing->fill($payload)->save();

        return $existing;
    }

    /**
     * Valor ya casteado según el tipo del setting.
     *
     * `value` is natively decoded by the `json` cast; the only remaining
     * special case is NULL under a boolean setting, which resolves to
     * `false` (never `null`) — matches the resolved contract callers relied
     * on before this cast existed.
     */
    public function getResolvedValueAttribute(): mixed
    {
        return $this->value ?? ($this->type === self::TYPE_BOOLEAN ? false : null);
    }

    protected function casts(): array
    {
        return [
            'value' => 'json',
        ];
    }
}
