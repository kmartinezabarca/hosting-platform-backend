<?php

namespace App\Domains\Platform\SiteBuilder\Http\Controllers\V2;

use App\Domains\Platform\SiteBuilder\Contracts\PageGeneratorProvider;
use App\Domains\Platform\SiteBuilder\Data\PageSpec;
use App\Domains\Platform\SiteBuilder\Models\GeneratedPage;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Generación de páginas con IA (SiteBuilder). v1 SÍNCRONO: valida, genera,
 * GUARDA el resultado y lo devuelve. El proveedor concreto (Ollama/Claude) lo
 * resuelve el contenedor por env — este controller no sabe cuál es.
 *
 * El HTML queda persistido (GeneratedPage) para previsualizar/descargar; el
 * despliegue (a *.roke.app o a un hosting existente) llega en fases posteriores.
 */
class PageGeneratorController extends Controller
{
    /**
     * POST /api/v2/site-builder/generate — genera, guarda y devuelve la página.
     */
    public function generate(Request $request, PageGeneratorProvider $generator): JsonResponse
    {
        $validated = $request->validate([
            'prompt'     => ['required', 'string', 'max:4000'],
            'site_name'  => ['nullable', 'string', 'max:120'],
            'locale'     => ['nullable', 'string', 'max:10'],
            'palette'    => ['nullable', 'array', 'max:8'],
            'palette.*'  => ['string', 'max:9'],
            'sections'   => ['nullable', 'array', 'max:20'],
            'sections.*' => ['string', 'max:60'],
        ]);

        if (! $generator->isConfigured()) {
            abort(503, 'El generador de páginas no está disponible (proveedor sin configurar).');
        }

        try {
            $page = $generator->generate(PageSpec::fromArray($validated));
        } catch (RuntimeException $e) {
            abort(502, $e->getMessage());
        }

        $record = GeneratedPage::create([
            'user_id'   => $request->user()->id,
            'prompt'    => $validated['prompt'],
            'site_name' => $validated['site_name'] ?? null,
            'locale'    => $validated['locale'] ?? 'es',
            'spec'      => [
                'palette'  => $validated['palette'] ?? [],
                'sections' => $validated['sections'] ?? [],
            ],
            'status'    => 'ready',
            'title'     => $page->title,
            'html'      => $page->html,
            'provider'  => $page->provider,
            'model'     => $page->model,
            'warnings'  => $page->warnings,
        ]);

        return response()->json([
            'success' => true,
            'data'    => $this->transform($record, withHtml: true),
        ], 201);
    }

    /**
     * GET /api/v2/site-builder/pages — historial del usuario (sin el HTML).
     */
    public function index(Request $request): JsonResponse
    {
        $pages = GeneratedPage::where('user_id', $request->user()->id)
            ->latest()
            ->limit(50)
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $pages->map(fn (GeneratedPage $p) => $this->transform($p)),
        ]);
    }

    /**
     * GET /api/v2/site-builder/pages/{page} — una página con su HTML (preview).
     */
    public function show(Request $request, GeneratedPage $page): JsonResponse
    {
        $this->authorizeOwner($request, $page);

        return response()->json([
            'success' => true,
            'data'    => $this->transform($page, withHtml: true),
        ]);
    }

    /**
     * DELETE /api/v2/site-builder/pages/{page}
     */
    public function destroy(Request $request, GeneratedPage $page): JsonResponse
    {
        $this->authorizeOwner($request, $page);

        $page->delete();

        return response()->json(['success' => true]);
    }

    private function authorizeOwner(Request $request, GeneratedPage $page): void
    {
        abort_unless((int) $page->user_id === (int) $request->user()->id, 403);
    }

    private function transform(GeneratedPage $page, bool $withHtml = false): array
    {
        $data = [
            'uuid'       => $page->uuid,
            'title'      => $page->title,
            'site_name'  => $page->site_name,
            'status'     => $page->status,
            'provider'   => $page->provider,
            'model'      => $page->model,
            'warnings'   => $page->warnings ?? [],
            'created_at' => $page->created_at,
        ];

        if ($withHtml) {
            $data['html'] = $page->html;
        }

        return $data;
    }
}
