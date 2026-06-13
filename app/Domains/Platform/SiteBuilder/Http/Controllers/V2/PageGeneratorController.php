<?php

namespace App\Domains\Platform\SiteBuilder\Http\Controllers\V2;

use App\Domains\Platform\SiteBuilder\Contracts\PageGeneratorProvider;
use App\Domains\Platform\SiteBuilder\Data\PageSpec;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Generación de páginas con IA (SiteBuilder). v1 SÍNCRONO: valida, genera y
 * devuelve el HTML. El proveedor concreto (Ollama/Claude) lo resuelve el
 * contenedor por env — este controller no sabe cuál es.
 *
 * Encolar + persistir/desplegar la página llegará en un incremento posterior
 * (requiere decidir dónde viven las páginas: static site Coolify o file-manager).
 */
class PageGeneratorController extends Controller
{
    /**
     * POST /api/v2/site-builder/generate
     */
    public function generate(Request $request, PageGeneratorProvider $generator): JsonResponse
    {
        $validated = $request->validate([
            'prompt'      => ['required', 'string', 'max:4000'],
            'site_name'   => ['nullable', 'string', 'max:120'],
            'locale'      => ['nullable', 'string', 'max:10'],
            'palette'     => ['nullable', 'array', 'max:8'],
            'palette.*'   => ['string', 'max:9'],   // #RRGGBB
            'sections'    => ['nullable', 'array', 'max:20'],
            'sections.*'  => ['string', 'max:60'],
        ]);

        // Sin proveedor configurado → 503 claro, no un "éxito" vacío.
        if (! $generator->isConfigured()) {
            abort(503, 'El generador de páginas no está disponible (proveedor sin configurar).');
        }

        try {
            $page = $generator->generate(PageSpec::fromArray($validated));
        } catch (RuntimeException $e) {
            // El proveedor falló o devolvió algo inválido: se reporta tal cual,
            // nunca se inventa una página.
            abort(502, $e->getMessage());
        }

        return response()->json([
            'success' => true,
            'data'    => $page->toArray(),
        ]);
    }
}
