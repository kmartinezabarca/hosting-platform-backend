<?php

namespace App\Domains\Platform\SiteBuilder\Providers;

use App\Domains\Platform\SiteBuilder\Contracts\PageGeneratorProvider;
use App\Domains\Platform\SiteBuilder\Data\GeneratedPage;
use App\Domains\Platform\SiteBuilder\Data\PageSpec;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Proveedor self-hosted (Ollama) para DEV. Corre en el Ryzen (roke-ryzen-01),
 * NO en el Mac Mini. Sin API key — solo base_url.
 *
 * Falla RUIDOSO: si Ollama no responde o devuelve algo que no es HTML, lanza
 * excepción con contexto en vez de inventar una página (principio no-fake).
 */
class OllamaPageGenerator implements PageGeneratorProvider
{
    private string $baseUrl;
    private string $model;
    private int $timeout;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('page_generator.ollama.base_url', ''), '/');
        $this->model   = (string) config('page_generator.ollama.model', 'llama3.1');
        $this->timeout = (int) config('page_generator.timeout', 120);
    }

    public function name(): string
    {
        return 'ollama';
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl !== '';
    }

    public function generate(PageSpec $spec): GeneratedPage
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException(
                'OllamaPageGenerator sin configurar: define OLLAMA_BASE_URL (Ryzen) en el entorno.'
            );
        }

        $response = Http::baseUrl($this->baseUrl)
            ->timeout($this->timeout)
            ->acceptJson()
            ->post('/api/chat', [
                'model'    => $this->model,
                'stream'   => false,
                'messages' => [
                    ['role' => 'system', 'content' => $this->systemPrompt($spec)],
                    ['role' => 'user',   'content' => $this->userPrompt($spec)],
                ],
                'options'  => ['temperature' => 0.7],
            ]);

        if ($response->failed()) {
            Log::error('Ollama generate falló', [
                'model'  => $this->model,
                'status' => $response->status(),
                'body'   => mb_substr($response->body(), 0, 300),
            ]);
            throw new RuntimeException(
                "Ollama no pudo generar la página (HTTP {$response->status()}). Revisa que el "
                . "servicio esté arriba en el Ryzen y que el modelo «{$this->model}» exista."
            );
        }

        $raw = (string) ($response->json('message.content') ?? '');
        if (trim($raw) === '') {
            throw new RuntimeException('Ollama devolvió una respuesta vacía; no se generó la página.');
        }

        $html = $this->extractHtml($raw);
        if (! str_contains($html, '<')) {
            Log::error('Ollama devolvió contenido sin HTML', ['preview' => mb_substr($raw, 0, 200)]);
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
