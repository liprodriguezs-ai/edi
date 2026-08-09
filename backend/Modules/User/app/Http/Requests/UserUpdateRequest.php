<?php

namespace Modules\User\Http\Requests;

use Orion\Http\Requests\Request;

class UserUpdateRequest extends Request
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $conn = config('database.default', 'central');
        $id = $this->route('id');

        return [
            'email' => 'sometimes|email|unique:'.$conn.'.user,email,'.$id.',id',
        ];
    }

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }
}
