<?php

namespace Modules\Role\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use QCH\Core\Models\GlobalObserver;

// use Modules\Role\Database\Factories\RoleFactory;
#[ObservedBy([GlobalObserver::class])]
class Role extends \QCH\Core\Models\Role {}
