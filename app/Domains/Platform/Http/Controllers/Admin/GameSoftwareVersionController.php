<?php

namespace App\Domains\Platform\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Domains\Platform\Models\GameSoftwareVersion;
use App\Domains\Platform\Services\Minecraft\MinecraftVersionService;
use App\Domains\Platform\Services\SoftwareVersionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * CRUD de versiones de software de servidores de juego.
 *
 * GET    /admin/game-versions                → index (lista + filtros + stats)
 * POST   /admin/game-versions                → store (crear versión)
 * PUT    /admin/game-versions/{id}           → update (editar notas/estado/recomendada)
 * DELETE /admin/game-versions/{id}           → destroy (eliminar)
 * POST   /admin/game-versions/bulk/{action}  → bulk (enable | disable | delete)
 */
class GameSoftwareVersionController extends Controller
{
    public function __construct(
        private readonly SoftwareVersionService  $versionService,
        private readonly MinecraftVersionService $minecraftService,
    ) {}

    // ── Lista ─────────────────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $query = GameSoftwareVersion::query();

        // Búsqueda libre
        if ($search = trim((string) $request->get('search', ''))) {
            $query->where(fn ($q) =>
                $q->where('software_identifier', 'like', "%{$search}%")
                  ->orWhere('version',            'like', "%{$search}%")
                  ->orWhere('notes',              'like', "%{$search}%")
            );
        }

        // Filtro por software
        if ($software = $request->get('software')) {
            $query->forSoftware($software);
        }

        // Filtro por estado activo/inactivo
        $activeParam = $request->get('is_active', '');
        if ($activeParam !== '' && $activeParam !== null) {
            $query->where('is_active', filter_var($activeParam, FILTER_VALIDATE_BOOLEAN));
        }

        // Ordenar: software ASC, sort_order DESC, id DESC
        $query->orderBy('software_identifier')->orderByDesc('sort_order')->orderByDesc('id');

        $perPage    = min((int) $request->get('per_page', 100), 500);
        $paginated  = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data'    => $paginated->items(),
            'pagination' => [
                'current_page'   => $paginated->currentPage(),
                'per_page'       => $paginated->perPage(),
                'total'          => $paginated->total(),
                'last_page'      => $paginated->lastPage(),
                'has_more_pages' => $paginated->hasMorePages(),
            ],
            'stats' => [
                'total'     => GameSoftwareVersion::count(),
                'active'    => GameSoftwareVersion::where('is_active', true)->count(),
                'inactive'  => GameSoftwareVersion::where('is_active', false)->count(),
                'softwares' => GameSoftwareVersion::distinct()->count('software_identifier'),
            ],
            'identifiers' => SoftwareVersionService::knownIdentifiers(),
        ]);
    }

    // ── Crear ─────────────────────────────────────────────────────────────────

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'software_identifier' => [
                'required', 'string', 'max:50',
                Rule::in(SoftwareVersionService::knownIdentifiers()),
            ],
            'version'        => ['required', 'string', 'max:50'],
            'is_active'      => ['boolean'],
            'is_recommended' => ['boolean'],
            'notes'          => ['nullable', 'string', 'max:500'],
        ]);

        $software = $data['software_identifier'];
        $version  = $data['version'];

        // Unicidad
        if (GameSoftwareVersion::forSoftware($software)->where('version', $version)->exists()) {
            return response()->json([
                'success' => false,
                'message' => "La versión '{$version}' ya existe para '{$software}'.",
            ], 422);
        }

        // Quitar recomendación anterior si se marca esta como recomendada
        if (!empty($data['is_recommended'])) {
            GameSoftwareVersion::forSoftware($software)
                ->where('is_recommended', true)
                ->update(['is_recommended' => false]);
        }

        $record = GameSoftwareVersion::create([
            'software_identifier' => $software,
            'version'             => $version,
            'is_active'           => $data['is_active']      ?? true,
            'is_recommended'      => $data['is_recommended'] ?? false,
            'sort_order'          => GameSoftwareVersion::nextSortOrder($software),
            'notes'               => $data['notes']          ?? null,
        ]);

        $this->invalidateCaches($software);

        return response()->json([
            'success' => true,
            'message' => "Versión '{$version}' agregada para '{$software}'.",
            'data'    => $record,
        ], 201);
    }

    // ── Actualizar ────────────────────────────────────────────────────────────

    public function update(Request $request, int $id): JsonResponse
    {
        $record = GameSoftwareVersion::findOrFail($id);

        $data = $request->validate([
            'is_active'      => ['sometimes', 'boolean'],
            'is_recommended' => ['sometimes', 'boolean'],
            'notes'          => ['sometimes', 'nullable', 'string', 'max:500'],
            'sort_order'     => ['sometimes', 'integer', 'min:0'],
        ]);

        // Si se marca como recomendada, quitar la anterior del mismo software
        if (isset($data['is_recommended']) && $data['is_recommended'] === true) {
            GameSoftwareVersion::forSoftware($record->software_identifier)
                ->where('is_recommended', true)
                ->where('id', '!=', $id)
                ->update(['is_recommended' => false]);
        }

        $record->update($data);
        $this->invalidateCaches($record->software_identifier);

        return response()->json([
            'success' => true,
            'message' => 'Versión actualizada correctamente.',
            'data'    => $record->fresh(),
        ]);
    }

    // ── Eliminar ──────────────────────────────────────────────────────────────

    public function destroy(int $id): JsonResponse
    {
        $record   = GameSoftwareVersion::findOrFail($id);
        $software = $record->software_identifier;
        $version  = $record->version;
        $record->delete();
        $this->invalidateCaches($software);

        return response()->json([
            'success' => true,
            'message' => "Versión '{$version}' de '{$software}' eliminada.",
        ]);
    }

    // ── Bulk ──────────────────────────────────────────────────────────────────

    public function bulk(Request $request, string $action): JsonResponse
    {
        if (!in_array($action, ['enable', 'disable', 'delete'], true)) {
            return response()->json([
                'success' => false,
                'message' => "Acción desconocida: '{$action}'. Usa enable | disable | delete.",
            ], 400);
        }

        $data = $request->validate([
            'ids'   => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:game_software_versions,id'],
        ]);

        $ids = $data['ids'];

        // Obtener softwares ANTES de posible eliminación para invalidar caché
        $softwares = GameSoftwareVersion::whereIn('id', $ids)
            ->distinct()
            ->pluck('software_identifier');

        $affected = match ($action) {
            'enable'  => GameSoftwareVersion::whereIn('id', $ids)->update(['is_active' => true]),
            'disable' => GameSoftwareVersion::whereIn('id', $ids)->update(['is_active' => false]),
            'delete'  => GameSoftwareVersion::whereIn('id', $ids)->delete(),
        };

        foreach ($softwares as $software) {
            $this->invalidateCaches($software);
        }

        $label = match ($action) {
            'enable'  => 'activada(s)',
            'disable' => 'desactivada(s)',
            'delete'  => 'eliminada(s)',
        };

        return response()->json([
            'success'  => true,
            'message'  => "{$affected} versión(es) {$label}.",
            'affected' => $affected,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function invalidateCaches(string $software): void
    {
        $this->versionService->invalidateCache($software);
        $this->minecraftService->invalidateCache();
    }
}
