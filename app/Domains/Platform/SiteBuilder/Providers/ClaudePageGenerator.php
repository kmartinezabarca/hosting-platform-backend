<?php

namespace App\Domains\Platform\SiteBuilder\Providers;

use App\Domains\Platform\SiteBuilder\Contracts\PageGeneratorProvider;
use App\Domains\Platform\SiteBuilder\Data\GeneratedPage;
use App\Domains\Platform\SiteBuilder\Data\PageSpec;
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

    private function systemPrompt(PageSpec $spec): string
    {
        return 'Eres un generador de páginas web para el panel de ROKE Industries. Devuelve '
            . 'ÚNICAMENTE un documento HTML completo y autocontenido: empieza en <!DOCTYPE html>, '
            . 'incluye <head> con <title> y TODO el CSS en un <style> inline (sin archivos externos). '
            . 'Sin explicaciones, sin markdown, sin ```. Diseño moderno, responsive y accesible. '
            . "Idioma del contenido: {$spec->locale}.";
    }

    private function userPrompt(PageSpec $spec): string
    {
        $parts = ["Crea una página web para: {$spec->prompt}"];
        if ($spec->siteName) {
            $parts[] = "Nombre del sitio: {$spec->siteName}";
        }
        if ($spec->palette !== []) {
            $parts[] = 'Colores de marca: ' . implode(', ', $spec->palette);
        }
        if ($spec->sections !== []) {
            $parts[] = 'Secciones a incluir: ' . implode(', ', $spec->sections);
        }

        return implode("\n", $parts);
    }

    /** Quita fences markdown (```html …```) si el modelo los agrega. */
    private function extractHtml(string $raw): string
    {
        $text = trim($raw);

        if (preg_match('/```(?:html)?\s*(.+?)```/is', $text, $m)) {
            return trim($m[1]);
        }

        return $text;
    }

    private function deriveTitle(string $html, PageSpec $spec): string
    {
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m) && trim($m[1]) !== '') {
            return trim(strip_tags($m[1]));
        }
        if (preg_match('/<h1[^>]*>(.*?)<\/h1>/is', $html, $m) && trim(strip_tags($m[1])) !== '') {
            return trim(strip_tags($m[1]));
        }

        return $spec->siteName ?? 'Página generada';
    }
}
