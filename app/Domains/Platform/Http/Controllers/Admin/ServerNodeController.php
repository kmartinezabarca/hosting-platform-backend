<?php

namespace App\Domains\Platform\Http\Controllers\Admin;

use App\Domains\Platform\Models\ServerNode;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

/**
 * Administración del catálogo de nodos de infraestructura (server_nodes).
 *
 * El admin puede:
 *   - Listar los nodos disponibles (para el <select> de aprovisionamiento)
 *   - Ajustar status, capacidad (max_services) y prioridad
 *   - Forzar una re-sincronización desde Pterodactyl
 */
class ServerNodeController extends Controller
{
    /**
     * GET /admin/server-nodes
     *
     * Lista los nodos. Acepta ?type=pterodactyl y ?available_only=1 para el select.
     */
    public function index(Request $request): JsonResponse
    {
        $query = ServerNode::query()->orderByDesc('priority')->orderBy('name');

        if ($request->filled('type')) {
            $query->where('node_type', $request->string('type'));
        }

        if ($request->boolean('available_only')) {
            $query->available();
        }

        $defaultNode = config('pterodactyl.default_node');

        $nodes = $query->get()->map(fn (ServerNode $node) => [
            'id'                  => $node->id,
            'uuid'                => $node->uuid,
            'name'                => $node->name,
            'hostname'            => $node->hostname,
            'ip_address'          => $node->ip_address,
            'location'            => $node->location,
            'node_type'           => $node->node_type,
            'status'              => $node->status,
            'max_services'        => $node->max_services,
            'current_services'    => $node->current_services,
            'has_capacity'        => $node->hasCapacity(),
            'pterodactyl_node_id' => $node->pterodactyl_node_id,
            'priority'            => $node->priority,
            'is_default'          => $defaultNode !== null
                && (int) $defaultNode === (int) $node->pterodactyl_node_id,
            'specifications'      => $node->specifications,
        ]);

        return response()->json(['success' => true, 'data' => $nodes]);
    }

    /**
     * PATCH /admin/server-nodes/{id}
     *
     * Actualiza los campos administrables manualmente. Los campos técnicos
     * (hostname, ip, specs) los maneja la sincronización con Pterodactyl.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $node = ServerNode::findOrFail($id);

        $validated = $request->validate([
            'status'       => ['sometimes', 'in:active,maintenance,offline'],
            'max_services' => ['sometimes', 'integer', 'min:0'],
            'priority'     => ['sometimes', 'integer'],
            'location'     => ['sometimes', 'string', 'max:255'],
        ]);

        $node->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Nodo actualizado.',
            'data'    => $node->fresh(),
        ]);
    }

    /**
     * POST /admin/server-nodes/sync
     *
     * Fuerza una re-sincronización inmediata de nodos desde Pterodactyl.
     */
    public function sync(Request $request): JsonResponse
    {
        $args = [];
        if ($request->boolean('prune')) {
            $args['--prune'] = true;
        }

        Artisan::queue('pterodactyl:sync-nodes', $args);

        return response()->json([
            'success' => true,
            'message' => 'Sincronización de nodos iniciada en background.',
        ]);
    }
}
