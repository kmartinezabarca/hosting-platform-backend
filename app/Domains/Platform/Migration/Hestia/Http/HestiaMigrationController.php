<?php

namespace App\Domains\Platform\Migration\Hestia\Http;

use App\Domains\Platform\Migration\Hestia\HestiaBackupParser;
use App\Domains\Platform\Migration\Hestia\MigrationPlanner;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Importador asistido de HestiaCP (mes 3). Esta etapa solo PLANIFICA: recibe el
 * export JSON de los comandos `v-list-*` de HestiaCP y devuelve qué recursos del
 * plano de cómputo se crearían. No ejecuta la migración (eso llega después, con
 * confirmación del usuario y refinamiento del agente de IA).
 */
class HestiaMigrationController extends Controller
{
    public function __construct(
        private readonly HestiaBackupParser $parser,
        private readonly MigrationPlanner $planner,
    ) {
    }

    /**
     * POST /api/v2/migrations/hestia/plan
     *
     * Body: { web_domains: {<dominio>: {...}}, databases: {<nombre>: {...}} }
     * (la salida cruda de `v-list-web-domains` / `v-list-databases ... json`).
     */
    public function plan(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'web_domains' => ['sometimes', 'array'],
            'databases'   => ['sometimes', 'array'],
        ]);

        if (empty($validated['web_domains']) && empty($validated['databases'])) {
            return response()->json([
                'success' => false,
                'message' => 'Envía web_domains y/o databases del export de HestiaCP.',
            ], 422);
        }

        $parsed = $this->parser->parse($validated);
        $plan   = $this->planner->plan($parsed);

        return response()->json(['success' => true, 'data' => $plan]);
    }
}
