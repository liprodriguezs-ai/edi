<?php

namespace Modules\Board\Models;

use App\Models\Scopes\UserTenantScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Board\Database\Factories\BoardMemberFactory;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

#[ObservedBy([GlobalObserver::class])]
class BoardMember extends BaseModel
{
    use BelongsToTenant;
    use HasFactory;

    protected $table = 'board_member';

    protected $fillable = [
        'board_period_id',
        'user_id',
        'board_role_id',
        'unit_id',
    ];

    /**
     * The central User — bypass UserTenantScope so the relation resolves cross-model.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')
            ->withoutGlobalScope(UserTenantScope::class);
    }

    public function board_role(): BelongsTo
    {
        return $this->belongsTo(BoardRole::class, 'board_role_id');
    }

    public function board_period(): BelongsTo
    {
        return $this->belongsTo(BoardPeriod::class, 'board_period_id');
    }

    protected static function newFactory(): BoardMemberFactory
    {
        return BoardMemberFactory::new();
    }
}
