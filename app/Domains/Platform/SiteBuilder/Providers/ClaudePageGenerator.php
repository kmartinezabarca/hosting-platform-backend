<?php

namespace App\Domains\Platform\SiteBuilder\Providers;

use App\Domains\Platform\SiteBuilder\Contracts\PageGeneratorProvider;
use App\Domains\Platform\SiteBuilder\Data\GeneratedPage;
use App\Domains\Platform\SiteBuilder\Data\PageSpec;
use App\Domains\Platform\SiteBuilder\Support\BuildsPageHtml;
use App\Support\Anthropic\AnthropicClient;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Proveedor de pago (Claude). REUTILIZA el cliente compartido
 * App\Support\Anthropic\AnthropicClient (la API key vive en config/anthropic.php,
 * nunca aquí). Misma interfaz que Ollama: intercambiable por env.
 *
 * Falla RUIDOSO: si la API no responde o no devuelve HTML, lanza excepción en
 * vez de inventar una página (principio no-fake).
 */
class ClaudePageGenerator implements PageGeneratorProvider
{
    use BuildsPageHtml;

    private string $model;
    private int $maxTokens;

    public function __construct(private readonly AnthropicClient $anthropic)
    {
        $this->model     = (string) config('page_generator.claude.model', 'claude-sonnet-4-6');
        $this->maxTokens = (int) config('page_generator.claude.max_tokens', 8000);
    }

    public function name(): string
    {
        return 'claude';
    }

    public function isConfigured(): bool
    {
        // La key (ANTHROPIC_API_KEY) la valida el cliente compartido.
        return $this->anthropic->isConfigured();
    }

    public function generate(PageSpec $spec): GeneratedPage
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException(
                'ClaudePageGenerator sin configurar: falta ANTHROPIC_API_KEY en el entorno.'
            );
        }

        try {
            $response = $this->anthropic->messages([
                'model'      => $this->model,
                'max_tokens' => $this->maxTokens,
                'system'     => $this->systemPrompt($spec),
                'messages'   => [
                    ['role' => 'user', 'content' => $this->userPrompt($spec)],
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('Claude generate falló', ['model' => $this->model, 'error' => $e->getMessage()]);
            throw new RuntimeException('Claude no pudo generar la página: ' . $e->getMessage(), previous: $e);
        }

        $raw = $this->anthropic->firstText($response) ?? '';
        if (trim($raw) === '') {
            throw new RuntimeException('Claude devolvió una respuesta vacía; no se generó la página.');
        }

        $html = $this->extractHtml($raw);
        if (! str_contains($html, '<')) {
            Log::error('Claude devolvió contenido sin HTML', ['preview' => mb_substr($raw, 0, 200)]);
            throw new RuntimeException('El modelo no devolvió HTML válido.');
        }

        $warnings = [];
        if (stripos($html, '</html>') === false) {
            $warnings[] = 'La respuesta podría estar incompleta (no se encontró </html>).';
        }

        return new GeneratedPage(
            html: $html,
            title: $this->deriveTitle($html, $spec),
            provider: $this->name(),
            model: $this->model,
            warnings: $warnings,
        );
    }
}
