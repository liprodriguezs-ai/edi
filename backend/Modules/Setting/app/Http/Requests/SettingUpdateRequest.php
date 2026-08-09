<?php

namespace Modules\Setting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Setting\Models\Setting;
use Modules\Setting\Rules\SettingValueRule;

class SettingUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $settingId = $this->route('setting') ?? $this->route('id');

        // `type` is `sometimes` here — when the client omits it (updating only
        // `value`), fall back to the routed setting's CURRENT type so the
        // value rule still validates against the right shape.
        $currentSetting = $settingId instanceof Setting ? $settingId : Setting::withTrashed()->find($settingId);
        $type = (string) $this->input('type', $currentSetting?->type ?? Setting::TYPE_STRING);

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
            'slug' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('setting', 'slug')->where('tenant_id', tenant('id'))->ignore($settingId)],
            'value' => ['nullable', new SettingValueRule($type)],
            'type' => ['sometimes', 'required', 'string', 'in:boolean,string,integer,json'],
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
