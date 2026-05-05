<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SoftwareVersionService;
use Illuminate\Http\JsonResponse;

class SoftwareController extends Controller
{
    public function __construct(private readonly SoftwareVersionService $softwareVersionService) {}

    /**
     * GET /api/software/{identifier}/versions
     *
     * Devuelve las versiones disponibles de un software de servidor de Minecraft.
     *
     * Identificadores soportados: paper, velocity, folia, purpur, fabric, vanilla, bedrock.
     * Para proyectos extra de PaperMC se puede pasar el slug directamente (ej. "waterfall").
     *
     * Los resultados se cachean 24 horas automáticamente.
     */
    public function getVersions(string $identifier): JsonResponse
    {
        try {
            $result = $this->softwareVersionService->getVersions($identifier);

            return response()->json([
                'success'    => true,
                'data'       => $result['versions'],
                'identifier' => $result['identifier'],
                'cached'     => $result['cached'],
                'source'     => $result['source'],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener las versiones del software.',
                'debug'   => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }
}
