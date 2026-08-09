<?php

namespace Modules\Country\Http\Controllers;

use Modules\Country\Models\Country;
use QCH\Core\Http\Controllers\Controller;

class CountryController extends Controller
{
    protected $model = Country::class;

    protected $keyForPermission = 'country';
}
