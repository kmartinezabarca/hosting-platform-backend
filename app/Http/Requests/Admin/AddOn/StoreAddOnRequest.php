<?php

namespace App\Http\Requests\Admin\AddOn;

use Illuminate\Foundation\Http\FormRequest;

class StoreAddOnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'slug'        => ['required', 'string', 'max:100', 'unique:add_ons,slug'],
            'name'        => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'price'       => ['required', 'numeric', 'min:0'],
            'currency'    => ['required', 'string', 'size:3'],
            'is_active'   => ['boolean'],
            'metadata'    => ['nullable', 'array'],
            'service_plans' => ['sometimes', 'array'],
            'service_plans.*' => ['integer', 'exists:service_plans,id'], // o uuids si gustas
        ];
    }
}
