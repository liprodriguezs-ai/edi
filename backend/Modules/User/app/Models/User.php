<?php

namespace Modules\User\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail; // para la verificacion  de email con un codigo

class User extends \App\Models\User implements MustVerifyEmail {}
