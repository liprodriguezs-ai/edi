<?php

namespace Modules\System\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Modules\Module\Models\Module;
use Modules\System\Database\Factories\SystemFactory;
use Modules\Tenant\Models\Tenant;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

#[ObservedBy([GlobalObserver::class])]
class System extends BaseModel
{
    use CentralConnection;
    use HasFactory;

    protected $table = 'system';

    protected static function newFactory(): Factory
    {
        return SystemFactory::new();
    }

    protected $fillable = [
        'name',
        'slug',
        'description',
        'icon',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function modules(): BelongsToMany
    {
        return $this->belongsToMany(Module::class, 'module_system')
            ->withPivot('sort_order', 'group_label', 'group_icon')
            ->orderByPivot('sort_order');
    }

    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class, 'system_tenant')
            ->withPivot('is_addon')
            ->withTimestamps();
    }
}
