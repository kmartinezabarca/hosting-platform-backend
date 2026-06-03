<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Throwable;

class TurnstileToken implements ValidationRule
{
    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public function __construct(private readonly ?Request $request = null)
    {
    }

    /**
     * Run the validation rule.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $token = is_string($value) ? trim($value) : '';
        $secret = config('services.turnstile.secret');
        $secret = is_string($secret) ? trim($secret) : '';

        if ($token === '' || $secret === '') {
            $fail($this->message());

            return;
        }

        try {
            $payload = [
                'secret' => $secret,
                'response' => $token,
            ];

            $ip = $this->request?->ip() ?? request()->ip();

            if ($ip) {
                $payload['remoteip'] = $ip;
            }

            $response = Http::asForm()
                ->timeout(5)
                ->connectTimeout(3)
                ->post(self::VERIFY_URL, $payload);

            if (! $response->ok() || $response->json('success') !== true) {
                $fail($this->message());
            }
        } catch (Throwable) {
            $fail($this->message());
        }
    }

    private function message(): string
    {
        return 'Verificacion anti-bot fallida, recarga e intenta de nuevo.';
    }
}
