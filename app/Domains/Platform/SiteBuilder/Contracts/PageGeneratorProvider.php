<?php

namespace App\Domains\Platform\SiteBuilder\Contracts;

use App\Domains\Platform\SiteBuilder\Data\GeneratedPage;
use App\Domains\Platform\SiteBuilder\Data\PageSpec;

/**
 * Generador de páginas con IA (mes 3). Contrato AGNÓSTICO de proveedor: la
 * lógica de negocio depende solo de esta interfaz, nunca de Ollama/Claude/etc.
 *
 * Implementaciones intercambiables (OllamaPageGenerator self-hosted en dev,
 * ClaudePageGenerator de pago) se eligen por config (`PAGE_GENERATOR_DRIVER`);
 * cambiar de proveedor NO debe tocar lógica, solo el .env.
 */
interface PageGeneratorProvider
{
    /**
     * Genera una página a partir de la descripción del usuario.
     *
     * @throws \RuntimeException si el proveedor no está disponible o responde
     *         algo inválido — falla ruidoso, nunca devuelve un HTML inventado.
     */
    public function generate(PageSpec $spec): GeneratedPage;

    /**
     * ¿El proveedor tiene la config mínima para operar? (base_url/api_key según
     * el caso). Permite fallar con un mensaje claro antes de intentar generar.
     */
    public function isConfigured(): bool;

    /** Identificador del proveedor para logs/telemetría: 'ollama' | 'claude' | … */
    public function name(): string;
}
