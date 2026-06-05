<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $userId = $this->route('id') ?? $this->route('user');

        return [
            'first_name'  => ['sometimes', 'string', 'max:100'],
            'last_name'   => ['sometimes', 'string', 'max:100'],
            'email'       => ['sometimes', 'email', 'max:255', Rule::unique('users')->ignore($userId)],
            'password'    => ['sometimes', 'string', 'min:8'],
            'role'        => ['sometimes', Rule::in(['admin', 'support', 'client'])],
            'status'      => ['sometimes', Rule::in(['active', 'suspended', 'pending_verification', 'banned'])],
            'phone'       => ['nullable', 'string', 'max:20'],
            'address'     => ['nullable', 'string', 'max:500'],
            'city'        => ['nullable', 'string', 'max:100'],
            'state'       => ['nullable', 'string', 'max:100'],
            'country'     => ['nullable', 'string', 'size:2'],
            'postal_code' => ['nullable', 'string', 'max:20'],
        ];
    }
}
