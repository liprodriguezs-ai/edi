<?php

namespace Modules\Module\Models;

use App\Models\Permission;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\Module\Database\Factories\ModuleFactory;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;

#[ObservedBy([GlobalObserver::class])]
class Module extends BaseModel
{
    use HasFactory;

    protected $table = 'module';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'name',
        'description',
        'icon',
        'active',
        'group',
        'section_menu',
        'route',
        'custom',
        'permission_entity',
        'visible',
    ];

    public function permissions()
    {
        return $this->hasMany(Permission::class);
    }

    protected static function newFactory(): ModuleFactory
    {
        return ModuleFactory::new();
    }
}
