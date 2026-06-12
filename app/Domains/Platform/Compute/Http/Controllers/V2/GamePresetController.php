<?php

namespace App\Domains\Platform\Compute\Http\Controllers\V2;

use App\Domains\Platform\Compute\Games\GamePresetCatalog;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class GamePresetController extends Controller
{
    /**
     * GET /api/v2/game-presets — catálogo de servidores de juego con specs
     * recomendadas y si ya están disponibles para aprovisionar.
     */
    public function index(GamePresetCatalog $catalog): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $catalog->all(),
        ]);
    }
}
