<?php

namespace App\Http\Requests\Client;

use App\Rules\TurnstileToken;
use Illuminate\Foundation\Http\FormRequest;

class BlogCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('author_email')) {
            $this->merge([
                'author_email' => strtolower(trim((string) $this->input('author_email'))),
            ]);
        }
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'author_name' => ['required', 'string', 'min:2', 'max:120'],
            'author_email' => ['required', 'string', 'email', 'max:255'],
            'content' => ['required', 'string', 'min:3', 'max:3000'],
            // Honeypot: campo oculto que un humano nunca llena. Si trae algo, es bot.
            'website' => ['nullable', 'prohibited'],
            'cf-turnstile-response' => ['bail', 'required', 'string', new TurnstileToken($this)],
        ];
    }

    public function messages(): array
    {
        return [
            'website.prohibited' => 'No se pudo procesar el comentario.',
            'cf-turnstile-response.required' => 'Verificacion anti-bot fallida, recarga e intenta de nuevo.',
        ];
    }
}
