<?php

namespace App\Http\Requests\Api;

use App\Rules\TurnstileToken;
use Illuminate\Foundation\Http\FormRequest;

class ContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge([
                'email' => strtolower(trim((string) $this->input('email'))),
            ]);
        }
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'company' => ['nullable', 'string', 'max:255'],
            'service' => ['nullable', 'string', 'max:255'],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
            'cf-turnstile-response' => ['bail', 'required', 'string', new TurnstileToken($this)],
        ];
    }

    public function messages(): array
    {
        return [
            'cf-turnstile-response.required' => 'Verificacion anti-bot fallida, recarga e intenta de nuevo.',
        ];
    }
}
