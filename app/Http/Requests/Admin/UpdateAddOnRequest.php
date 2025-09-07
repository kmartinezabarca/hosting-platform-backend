<?php

// app/Http/Requests/Admin/UpdateAddOnRequest.php
namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAddOnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $uuid = $this->route('uuid');
        return [
            'slug'        => ['sometimes', 'string', 'max:100', Rule::unique('add_ons', 'slug')->ignore(fn() => \App\Models\AddOn::where('uuid', $uuid)->value('id'))],
            'name'        => ['sometimes', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'price'       => ['sometimes', 'numeric', 'min:0'],
            'currency'    => ['sometimes', 'string', 'size:3'],
            'is_active'   => ['sometimes', 'boolean'],
            'metadata'    => ['nullable', 'array'],
            'service_plans' => ['sometimes', 'array'],
            'service_plans.*' => ['integer', 'exists:service_plans,id'],
        ];
    }
}
