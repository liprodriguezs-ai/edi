<?php

namespace Modules\User\Http\Requests;

use Orion\Http\Requests\Request;

class UserRequest extends Request
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function commonRules(): array
    {
        return [];
    }

    public function storeRules(): array
    {
        $conn = config('database.default', 'central');

        return [
            'email' => 'required|email|unique:'.$conn.'.user,email',
        ];
    }
}
