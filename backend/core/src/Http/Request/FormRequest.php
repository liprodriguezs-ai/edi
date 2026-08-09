<?php

namespace QCH\Core\Http\Request;

use Illuminate\Foundation\Http\FormRequest as FormRequestBase;
use Illuminate\Http\Request;

class FormRequest extends FormRequestBase
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        $rules = collect($this->params)->pluck('rules', 'name')->all();
        switch ($this->method()) {
            case 'GET':
            case 'DELETE':
                $rules = [];
                break;
            case 'PUT':
                $rules = collect($this->params)
                    ->whereIn('name', array_keys(Request::all()))
                    ->whereIn('method', [null, 'PUT'])
                    ->pluck('rules', 'name')
                    ->all();
                break;
            case 'POST':
                $rules = collect($this->params)
                // ->whereIn('name', array_keys(Request::all()))
                // ->whereIn('method', [null, 'POST'])
                    ->pluck('rules', 'name')
                    ->all();
                break;
            default:
                break;
        }

        return $rules;
    }

    /**
     * Set custom messages for validator errors.
     *
     * @return array
     */
    public function messages()
    {
        return [];
    }

    /**
     * Set custom attributes for validator errors.
     *
     * @return array
     */
    public function attributes()
    {
        return collect($this->params)->pluck('attribute', 'name')->all();
    }
}
