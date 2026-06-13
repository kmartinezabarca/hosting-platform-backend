<?php

namespace App\Domains\Platform\SiteBuilder\Data;

/**
 * Entrada para el generador de páginas: lo que el usuario describe del sitio.
 * Inmutable. `prompt` es lo único obligatorio; el resto son pistas opcionales
 * que el proveedor usa si las soporta.
 */
final class PageSpec
{
    /**
     * @param string        $prompt    Descripción libre del sitio/página que quiere el usuario.
     * @param string|null   $siteName  Nombre del sitio/negocio (para títulos y encabezados).
     * @param string        $locale    Idioma del contenido generado (es | en | …).
     * @param string[]      $palette   Colores de marca en hex (#RRGGBB), opcional.
     * @param string[]      $sections  Secciones sugeridas (hero, servicios, contacto…), opcional.
     */
    public function __construct(
        public readonly string $prompt,
        public readonly ?string $siteName = null,
        public readonly string $locale = 'es',
        public readonly array $palette = [],
        public readonly array $sections = [],
    ) {
    }

    /**
     * Construye desde datos validados (p.ej. el request del panel).
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            prompt: (string) ($data['prompt'] ?? ''),
            siteName: $data['site_name'] ?? null,
            locale: (string) ($data['locale'] ?? 'es'),
            palette: array_values(array_filter((array) ($data['palette'] ?? []))),
            sections: array_values(array_filter((array) ($data['sections'] ?? []))),
        );
    }

    /** Serialización segura para logs/telemetría (sin secretos; el prompt es del usuario). */
    public function toArray(): array
    {
        return [
            'prompt'    => $this->prompt,
            'site_name' => $this->siteName,
            'locale'    => $this->locale,
            'palette'   => $this->palette,
            'sections'  => $this->sections,
        ];
    }
}
