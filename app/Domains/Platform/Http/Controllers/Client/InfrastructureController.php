<?php

namespace App\Domains\Platform\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Domains\Platform\Models\SystemStatus;
use Illuminate\Http\JsonResponse;

class InfrastructureController extends Controller
{
    /**
     * GET /infrastructure
     * Topología de la red de ROKE Industries.
     *
     * Respuesta:
     *   regions       — array de nodos con id, name, country, x, y (layout), node_count,
     *                   avg_latency_ms, status (operational | degraded | outage)
     *   system_status — registros de system_status como referencia
     *   throughput    — array vacío (monitoreo de throughput no disponible aún)
     */
    public function index(): JsonResponse
    {
        $statuses = SystemStatus::all()->keyBy('service_name');

        $regions = collect(config('infrastructure.regions', []))
            ->map(function (array $region) use ($statuses): array {
                $statusKey    = $region['status_key'];
                $systemRecord = $statusKey ? $statuses->get($statusKey) : null;

                return [
                    'id'             => $region['id'],
                    'name'           => $region['name'],
                    'country'        => $region['country'],
                    'x'              => $region['x'],
                    'y'              => $region['y'],
                    'node_count'     => $region['node_count'],
                    'avg_latency_ms' => $region['avg_latency_ms'],
                    'status'         => $systemRecord?->status ?? 'operational',
                    'status_message' => $systemRecord?->message,
                ];
            })
            ->values();

        $systemOverview = $statuses->values()->map(fn ($s) => [
            'service_name' => $s->service_name,
            'status'       => $s->status,
            'message'      => $s->message,
            'last_updated' => optional($s->last_updated)->toISOString(),
        ]);

        return response()->json([
            'success' => true,
            'data'    => [
                'regions'       => $regions,
                'system_status' => $systemOverview,
                'throughput'    => [],          // no hay datos de throughput en tiempo real
            ],
        ]);
    }
}
