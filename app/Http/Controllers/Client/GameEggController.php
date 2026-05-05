<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\PterodactylEgg;
use App\Models\ServicePlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Expone el catálogo de juegos disponibles al cliente.
 *
 * El cliente llama a este endpoint después de seleccionar un plan para
 * mostrar la lista de juegos que puede elegir.
 */
class GameEggController extends Controller
{
    /**
     * GET /game-eggs?plan_uuid=xxx
     *
     * Lista los eggs disponibles, opcionalmente filtrados por plan.
     * Si el plan tiene `allowed_nest_ids`, solo devuelve eggs de esos nests.
     *
     * Respuesta agrupada por nest para facilitar la UI:
     * [
     *   { nest: "Minecraft", nest_id: 1, games: [ {id, name, description, icon_url}, ... ] },
     *   { nest: "Source Games", nest_id: 5, games: [ ... ] },
     * ]
     */
    public function index(Request $request): JsonResponse
    {
        $query = PterodactylEgg::active()->orderBy('sort_order')->orderBy('egg_name');

        // Filtrar por plan si se proporcionó
        if ($request->filled('plan_uuid')) {
            $plan = ServicePlan::where('uuid', $request->plan_uuid)
                ->where('is_active', true)
                ->first();

            if (! $plan) {
                return response()->json(['success' => false, 'message' => 'Plan no encontrado.'], 404);
            }

            // Si el plan tiene allowed_nest_ids, restringir a esos nests
            if (! empty($plan->allowed_nest_ids)) {
                $query->forNests($plan->allowed_nest_ids);
            }
        }

        $eggs = $query->get();

        // Agrupar por nest para facilitar la UI
        $grouped = $eggs
            ->groupBy('ptero_nest_id')
            ->map(function ($nestEggs) {
                $first = $nestEggs->first();
                return [
                    'nest_id'     => $first->ptero_nest_id,
                    'nest'        => $first->nest_name,
                    'games'       => $nestEggs->map->toClientArray()->values(),
                ];
            })
            ->values();

        return response()->json([
            'success' => true,
            'data'    => $grouped,
        ]);
    }

    /**
     * GET /game-eggs/{id}
     *
     * Detalles de un egg específico.
     */
    public function show(int $id): JsonResponse
    {
        $egg = PterodactylEgg::active()->find($id);

        if (! $egg) {
            return response()->json(['success' => false, 'message' => 'Juego no disponible.'], 404);
        }

        return response()->json([
            'success' => true,
            'data'    => $egg->toClientArray(),
        ]);
    }
}
