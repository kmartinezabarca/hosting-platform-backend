<?php

namespace App\Domains\Platform\SiteBuilder\Data;

/**
 * Resultado del generador: una página HTML autocontenida (CSS inline, 1 archivo)
 * lista para desplegar como static site o escribir al file-manager del hosting.
 * Inmutable. Incluye qué proveedor/modelo la generó (telemetría) y avisos.
 */
final class GeneratedPage
{
    /**
     * @param string   $html     Documento HTML completo y autocontenido.
     * @param string   $title    Título de la página (para <title>/listados).
     * @param string   $provider Proveedor que la generó: 'ollama' | 'claude' | …
     * @param string   $model    Modelo concreto usado (p.ej. 'llama3.1', 'claude-…').
     * @param string[] $warnings Avisos no fatales (p.ej. el modelo recortó contenido).
     */
    public function __construct(
        public readonly string $html,
        public readonly string $title,
        public readonly string $provider,
        public readonly string $model,
        public readonly array $warnings = [],
    ) {
    }

    public function toArray(): array
    {
        return [
            'html'     => $this->html,
            'title'    => $this->title,
            'provider' => $this->provider,
            'model'    => $this->model,
            'warnings' => $this->warnings,
        ];
    }
}
