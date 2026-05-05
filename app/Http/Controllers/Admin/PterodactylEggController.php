<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PterodactylEgg;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

/**
 * Administración del catálogo de eggs/juegos.
 *
 * El admin puede:
 *   - Ver todos los eggs (activos e inactivos)
 *   - Activar / desactivar eggs
 *   - Personalizar nombre e ícono para el cliente
 *   - Forzar una re-sincronización desde Pterodactyl
 */
class PterodactylEggController extends Controller
{
    /**
     * GET /admin/pterodactyl/eggs
     */
    public function index(Request $request): JsonResponse
    {
        $query = PterodactylEgg::query()->orderBy('sort_order')->orderBy('nest_name')->orderBy('egg_name');

        if ($request->boolean('active_only')) {
            $query->active();
        }

        if ($request->filled('nest_id')) {
            $query->where('ptero_nest_id', $request->integer('nest_id'));
        }

        $eggs = $query->get()->map(fn($egg) => [
            'id'           => $egg->id,
            'ptero_nest_id'=> $egg->ptero_nest_id,
            'ptero_egg_id' => $egg->ptero_egg_id,
            'nest_name'    => $egg->nest_name,
            'egg_name'     => $egg->egg_name,
            'display_name' => $egg->display_name,
            'description'  => $egg->egg_description,
            'docker_image' => $egg->docker_image,
            'icon_url'     => $egg->icon_url,
            'is_active'    => $egg->is_active,
            'sort_order'   => $egg->sort_order,
            'synced_at'    => $egg->synced_at?->toIsoString(),
            'services_count' => $egg->services()->count(),
        ]);

        return response()->json(['success' => true, 'data' => $eggs]);
    }

    /**
     * PATCH /admin/pterodactyl/eggs/{id}
     *
     * Actualiza solo los campos que el admin puede controlar.
     * No modifica datos técnicos (docker_image, startup, variables) — esos
     * vienen de la sincronización con Pterodactyl.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $egg = PterodactylEgg::findOrFail($id);

        $validated = $request->validate([
            'is_active'    => 'sometimes|boolean',
            'display_name' => 'sometimes|nullable|string|max:100',
            'icon_url'     => 'sometimes|nullable|url|max:500',
            'sort_order'   => 'sometimes|integer|min:0|max:9999',
        ]);

        $egg->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Egg actualizado.',
            'data'    => $egg->fresh(),
        ]);
    }

    /**
     * POST /admin/pterodactyl/eggs/sync
     *
     * Fuerza una re-sincronización inmediata con Pterodactyl.
     * Devuelve inmediatamente — el proceso corre en background.
     */
    public function sync(Request $request): JsonResponse
    {
        $nestId          = $request->integer('nest_id', 0) ?: null;
        $disableMissing  = $request->boolean('disable_missing', false);

        $args = [];
        if ($nestId) {
            $args['--nest'] = $nestId;
        }
        if ($disableMissing) {
            $args['--disable-missing'] = true;
        }

        // Correr en background para no bloquear la respuesta
        Artisan::queue('pterodactyl:sync-eggs', $args);

        return response()->json([
            'success' => true,
            'message' => 'Sincronización iniciada en background. Los eggs estarán disponibles en unos segundos.',
        ]);
    }

    /**
     * POST /admin/pterodactyl/eggs/{id}/toggle
     *
     * Activa o desactiva un egg.
     */
    public function toggle(int $id): JsonResponse
    {
        $egg = PterodactylEgg::findOrFail($id);
        $egg->update(['is_active' => ! $egg->is_active]);

        return response()->json([
            'success'   => true,
            'is_active' => $egg->is_active,
            'message'   => $egg->is_active ? 'Egg activado.' : 'Egg desactivado.',
        ]);
    }
}
