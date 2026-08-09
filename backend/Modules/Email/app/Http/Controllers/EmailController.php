<?php

namespace Modules\Email\Http\Controllers;

use Modules\Email\Models\Email;
use QCH\Core\Http\Controllers\Controller;

class EmailController extends Controller
{
    protected $model = Email::class;

    protected $keyForPermission = 'email';
}
