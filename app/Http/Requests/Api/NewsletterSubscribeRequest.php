<?php

namespace App\Http\Requests\Api;

use App\Rules\TurnstileToken;
use Illuminate\Foundation\Http\FormRequest;

class NewsletterSubscribeRequest extends FormRequest
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
            'email' => ['required', 'string', 'email', 'max:255'],
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
