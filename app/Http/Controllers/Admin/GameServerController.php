<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Services\Pterodactyl\GameServerProvisioningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GameServerController extends Controller
{
    public function __construct(private readonly GameServerProvisioningService $provisioner) {}

    /**
     * GET /admin/game-servers
     * Lista todos los servicios gestionados por Pterodactyl.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Service::with(['user:id,uuid,first_name,last_name,email', 'plan:id,uuid,name,slug'])
            ->whereNotNull('pterodactyl_server_id')
            ->when($request->filled('status'), fn($q) => $q->where('status', $request->get('status')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = $request->get('search');
                $q->where(fn($qq) =>
                    $qq->where('name', 'like', "%{$s}%")
                       ->orWhere('external_id', 'like', "%{$s}%")
                       ->orWhereHas('user', fn($u) => $u->where('email', 'like', "%{$s}%"))
                );
            })
            ->orderByDesc('created_at');

        return response()->json([
            'success' => true,
            'data'    => $query->paginate((int) $request->get('per_page', 20)),
        ]);
    }

    /**
     * GET /admin/game-servers/{id}
     * Detalle del servicio + estado en tiempo real de Pterodactyl.
     */
    public function show(int $id): JsonResponse
    {
        $service = Service::with(['user', 'plan'])->findOrFail($id);

        $pterodactylStatus = null;
        if ($service->pterodactyl_server_id) {
            try {
                $pterodactylStatus = $this->provisioner->syncStatus($service);
            } catch (\Throwable $e) {
                $pterodactylStatus = ['error' => $e->getMessage()];
            }
        }

        return response()->json([
            'success'            => true,
            'data'               => $service,
            'pterodactyl_status' => $pterodactylStatus,
        ]);
    }

    /**
     * POST /admin/game-servers/{id}/provision
     * Aprovisiona manualmente un servicio que falló o quedó en pending.
     */
    public function provision(int $id): JsonResponse
    {
        $service = Service::with(['plan', 'user'])->findOrFail($id);

        if (!in_array($service->status, ['pending', 'failed'])) {
            return response()->json([
                'success' => false,
                'message' => "Solo se puede re-aprovisionar un servicio en estado 'pending' o 'failed'. Estado actual: {$service->status}",
            ], 422);
        }

        try {
            $this->provisioner->provision($service);
            return response()->json([
                'success' => true,
                'message' => 'Servidor aprovisionado exitosamente.',
                'data'    => $service->fresh(),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
                'data'    => $service->fresh(),
            ], 500);
        }
    }

    /**
     * POST /admin/game-servers/{id}/suspend
     */
    public function suspend(int $id): JsonResponse
    {
        $service = Service::findOrFail($id);

        try {
            $this->provisioner->suspend($service);
            return response()->json(['success' => true, 'message' => 'Servidor suspendido.', 'data' => $service->fresh()]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /admin/game-servers/{id}/unsuspend
     */
    public function unsuspend(int $id): JsonResponse
    {
        $service = Service::findOrFail($id);

        try {
            $this->provisioner->unsuspend($service);
            return response()->json(['success' => true, 'message' => 'Servidor reactivado.', 'data' => $service->fresh()]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /admin/game-servers/{id}/reinstall
     * ⚠️ Borra todos los archivos del servidor y reinstala desde cero.
     */
    public function reinstall(int $id): JsonResponse
    {
        $service = Service::findOrFail($id);

        try {
            $this->provisioner->reinstall($service);
            return response()->json(['success' => true, 'message' => 'Reinstalación iniciada. El servidor estará listo en unos minutos.']);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * DELETE /admin/game-servers/{id}
     * Termina el servicio y elimina el servidor de Pterodactyl permanentemente.
     */
    public function terminate(int $id): JsonResponse
    {
        $service = Service::findOrFail($id);

        try {
            $this->provisioner->terminate($service);
            return response()->json(['success' => true, 'message' => 'Servidor eliminado y servicio terminado.']);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
