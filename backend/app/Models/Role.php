<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Spatie\Permission\Models\Role as SpatieRole;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

class Role extends SpatieRole
{
    use CentralConnection;
    use HasFactory;
    use HasUuids;

    protected $primaryKey = 'uuid';
}
