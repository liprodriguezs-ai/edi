<?php

namespace QCH\Core\Http\Controllers;

use Dingo\Api\Auth\Auth;
use Dingo\Api\Dispatcher;
use Dingo\Api\Http\Response\Factory;
use Illuminate\Auth\GenericUser;
use Illuminate\Database\Eloquent\Model;

/**
 * @property Dispatcher $api
 * @property GenericUser|Model $user
 * @property Auth $auth
 * @property Factory $response
 *
 * @method void errorNotFound($field, $value)
 * @method void success($array)
 */
trait Helpers {}
