<?php

namespace App\Support\Anthropic;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Cliente compartido de la Messages API de Anthropic (núcleo App\Support —
 * lo usan Platform/Ai y, a futuro, el chat de Pet puede migrar aquí; hoy
 * Pet conserva su copia para no tocar su dominio).
 *
 * Soporta tool use: el payload acepta 'tools' y la respuesta puede traer
 * bloques tool_use que el AgentRunner ejecuta.
 */
class AnthropicClient
{
    public function isConfigured(): bool
    {
        return ! empty(config('anthropic.api_key'));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function messages(array $payload): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Anthropic API key not configured.');
        }

        $response = Http::baseUrl(config('anthropic.base_url'))
            ->timeout((int) config('anthropic.timeout', 60))
            ->withHeaders([
                'x-api-key'         => config('anthropic.api_key'),
                'anthropic-version' => config('anthropic.version', '2023-06-01'),
                'content-type'      => 'application/json',
            ])
            ->post('/v1/messages', $payload);

        if ($response->failed()) {
            throw new RuntimeException(
                'Anthropic API error (' . $response->status() . '): ' . substr($response->body(), 0, 500)
            );
        }

        return $response->json();
    }

    /** Primer bloque de texto de la respuesta. */
    public function firstText(array $response): ?string
    {
        foreach ($response['content'] ?? [] as $block) {
            if (($block['type'] ?? null) === 'text' && isset($block['text'])) {
                return $block['text'];
            }
        }

        return null;
    }

    /** @return array[] bloques tool_use de la respuesta */
    public function toolUses(array $response): array
    {
        return array_values(array_filter(
            $response['content'] ?? [],
            fn ($block) => ($block['type'] ?? null) === 'tool_use'
        ));
    }
}
