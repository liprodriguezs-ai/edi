<?php

namespace QCH\Core\Models;

use App\Models\Scopes\UserTenantScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Class BaseModel.
 */
abstract class BaseModel extends Model implements Auditable, ReportModel
{
    use HasUuids;
    use \OwenIt\Auditing\Auditable;
    use SoftDeletes;

    // esta clase hace que el registro no se elimine realmente de la bd cuando haces delete, solo lo deja de tomar en cuenta como existente, reg su eliminacion
    use WithJoin;

    public function reportColumns()
    {
        return [
            'Nombre' => 'name',
            'F. creación' => function ($result) {
                return $result->created_at ? $result->created_at->format('d/m/Y') : '';
            },
            'F. actualización' => function ($result) {
                return $result->updated_at ? $result->updated_at->format('d/m/Y') : '';
            },
        ];
    }

    /**
     * Civil dates (`date` / `immutable_date` casts: birth_date, visit_date,
     * due_date, ...) leave the API as `Y-m-d`, never as a midnight-UTC instant:
     * a client converting `2020-05-10T00:00:00Z` to a negative-offset zone would
     * render the day before. Instants keep Laravel's ISO-8601 UTC (`...Z`).
     * Only serialization changes; reading the attribute still yields a Carbon.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<int, string>  $mutatedAttributes
     * @return array<string, mixed>
     */
    protected function addCastAttributesToArray(array $attributes, array $mutatedAttributes)
    {
        $attributes = parent::addCastAttributesToArray($attributes, $mutatedAttributes);

        foreach ($this->getCasts() as $key => $cast) {
            if (! in_array($cast, ['date', 'immutable_date'], true)
                || ! array_key_exists($key, $attributes)
                || in_array($key, $mutatedAttributes, true)) {
                continue;
            }

            $attributes[$key] = $this->getAttributeValue($key)?->format('Y-m-d');
        }

        return $attributes;
    }

    public static function columns(Blueprint $table, bool $withTenant = false)
    {
        if (env('DB_CONNECTION') == 'sqlite') {
            $table->uuid('id');
        } else {
            if (env('DB_CONNECTION') === 'pgsql') {
                $table->uuid('id')->primary()->unique()->default(\DB::raw('(uuid_generate_v7())'));
            } else {
                $table->uuid('id')->primary()->unique()->default(\DB::raw('(UUID())'));
            }
        }

        if ($withTenant) {
            $table->foreignUuid('tenant_id')->references('id')->on('tenant')->onDelete('cascade');
            $table->index('tenant_id');
        }

        $table->timestamp('created_at')->useCurrent();
        $table->timestamp('updated_at')->nullable();
        $table->softDeletes();
        $table->uuid('user_created_id')->nullable();
        $table->uuid('user_updated_id')->nullable();
        $table->uuid('user_deleted_id')->nullable();
        $table->uuid('user_restored_id')->nullable();
    }

    public function user_created(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_created_id')
            ->withoutGlobalScope(UserTenantScope::class)
            ->withTrashed();
    }
}
