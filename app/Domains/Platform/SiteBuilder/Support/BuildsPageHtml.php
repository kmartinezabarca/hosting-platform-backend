<?php

namespace App\Domains\Platform\SiteBuilder\Support;

use App\Domains\Platform\SiteBuilder\Data\PageSpec;

/**
 * Lógica común a los providers de generación de páginas: construcción del
 * prompt y parseo del HTML devuelto por el modelo. Cada provider se queda solo
 * con su transporte (HTTP a Ollama / cliente Anthropic); esto evita duplicar
 * el prompt y la extracción en cada implementación.
 */
trait BuildsPageHtml
{
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
