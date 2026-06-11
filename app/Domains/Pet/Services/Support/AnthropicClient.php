<?php

namespace App\Domains\Pet\Services\Support;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Cliente mínimo de la Messages API de Anthropic (Claude) vía HTTP server-side.
 *
 * El proyecto Laravel no incluye el SDK oficial de PHP; usamos el cliente HTTP
 * de Laravel (Guzzle, ya presente) contra /v1/messages — es el camino "raw HTTP"
 * idiomático aquí. La llave nunca sale del backend.
 */
class AnthropicClient
{
    public function isConfigured(): bool
    {
        return ! empty(config('anthropic.api_key'));
    }

    /**
     * Llama a la Messages API y devuelve el JSON decodificado.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws RuntimeException si la API responde con error o no hay llave.
     */
    public function messages(array $payload): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Anthropic API key not configured.');
        }

        $response = Http::baseUrl(config('anthropic.base_url'))
            ->timeout((int) config('anthropic.timeout', 30))
            ->withHeaders([
                'x-api-key'         => config('anthropic.api_key'),
                'anthropic-version' => config('anthropic.version', '2023-06-01'),
                'content-type'      => 'application/json',
            ])
            ->post('/v1/messages', $payload);

        if ($response->failed()) {
            throw new RuntimeException(
                'Anthropic API error (' . $response->status() . '): ' . $response->body()
            );
        }

        return $response->json();
    }

    /**
     * Extrae el primer bloque de texto de la respuesta (donde viaja el JSON
     * estructurado cuando se usa output_config.format).
     */
    public function firstText(array $response): ?string
    {
        foreach ($response['content'] ?? [] as $block) {
            if (($block['type'] ?? null) === 'text' && isset($block['text'])) {
                return $block['text'];
            }
        }

        return null;
    }
}
