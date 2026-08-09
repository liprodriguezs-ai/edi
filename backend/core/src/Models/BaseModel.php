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

    public static function columns(Blueprint $table, bool $withTenant = false)
    {
        if (env('DB_CONNECTION') == 'sqlite') {
            $table->uuid('id');
        } else {
            if (env('DB_CONNECTION') === 'pgsql') {
                $table->uuid('id')->primary()->unique()->default(\DB::raw('(uuid_generate_v4())'));
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
