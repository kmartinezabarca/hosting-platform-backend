<?php

namespace App\Domains\Platform\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Domains\Platform\Models\Service;
use App\Domains\Platform\Services\Pterodactyl\GameServerProvisioningService;
use App\Domains\Platform\Services\Pterodactyl\PterodactylService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class GameServerController extends Controller
{
    public function __construct(
        private readonly GameServerProvisioningService $provisioner,
        private readonly PterodactylService $pterodactyl,
    ) {}

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
            'data'    => $query->paginate(min((int) $request->get('per_page', 20), 100)),
        ]);
    }

    /**
     * GET /admin/game-servers/{uuid}
     * Detalle del servicio + estado en tiempo real de Pterodactyl.
     */
    public function show(string $uuid): JsonResponse
    {

        $service = Service::with(['user', 'plan'])
    ->where('uuid', $uuid)
    ->firstOrFail();

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

    // ─────────────────────────────────────────────────────────────────────────────
    // Console / Runtime (admin bypass — sin verificación de propiedad)
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * GET /admin/game-servers/{id}/websocket
     * Credenciales WebSocket de Wings para la consola admin.
     */
    public function websocket(int $id): JsonResponse
    {
        $service    = Service::findOrFail($id);
        $identifier = $service->connection_details['identifier'] ?? null;

        if (!$identifier) {
            return response()->json(['success' => false, 'message' => 'El servidor no tiene identificador de Pterodactyl asignado.'], 404);
        }

        try {
            $response = Http::withToken(config('pterodactyl.client_api_key'))
                ->baseUrl(config('pterodactyl.base_url'))
                ->when(! config('pterodactyl.verify_ssl', true), fn ($h) => $h->withoutVerifying())
                ->acceptJson()
                ->get("/api/client/servers/{$identifier}/websocket");

            if ($response->failed()) {
                Log::error('Admin websocket credentials failed', ['service_id' => $service->id, 'status' => $response->status()]);
                return response()->json(['success' => false, 'message' => 'No se pudo obtener acceso a la consola.'], 503);
            }

            $wsData = $response->json('data');
            $wsData['socket'] = $this->rewriteWingsUrl($wsData['socket']);

            return response()->json(['success' => true, 'data' => $wsData]);
        } catch (\Throwable $e) {
            Log::error('Admin gameServerWebsocket error', ['service_id' => $service->id, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Error al conectar con el panel.'], 503);
        }
    }

    /**
     * GET /admin/game-servers/{id}/usage
     * Métricas en tiempo real (CPU, RAM, disco).
     */
    public function usage(int $id): JsonResponse
    {
        $service    = Service::findOrFail($id);
        $identifier = $service->connection_details['identifier'] ?? null;

        if (!$identifier) {
            return response()->json(['success' => false, 'message' => 'Sin identificador de Pterodactyl.'], 404);
        }

        try {
            $resources = $this->pterodactyl->getServerResources($identifier);

            return response()->json([
                'success' => true,
                'data'    => [
                    'state'        => $resources['current_state']             ?? 'offline',
                    'is_suspended' => $resources['is_suspended']              ?? false,
                    'cpu'          => $resources['resources']['cpu_absolute'] ?? 0,
                    'memory_bytes' => $resources['resources']['memory_bytes'] ?? 0,
                    'disk_bytes'   => $resources['resources']['disk_bytes']   ?? 0,
                    'uptime'       => $resources['resources']['uptime']       ?? 0,
                    'network'      => $resources['resources']['network']      ?? [],
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 503);
        }
    }

    /**
     * POST /admin/game-servers/{id}/power
     * Señal de poder: start | stop | restart | kill
     */
    public function power(Request $request, int $id): JsonResponse
    {
        $service = Service::findOrFail($id);

        $validated  = $request->validate([
            'signal' => ['required', Rule::in(['start', 'stop', 'restart', 'kill'])],
        ]);

        $identifier = $service->connection_details['identifier'] ?? null;

        if (!$identifier) {
            return response()->json(['success' => false, 'message' => 'Sin identificador de Pterodactyl.'], 500);
        }

        try {
            $this->pterodactyl->sendPowerSignal($identifier, $validated['signal']);

            $labels = [
                'start'   => 'Servidor iniciando...',
                'stop'    => 'Servidor deteniéndose...',
                'restart' => 'Servidor reiniciando...',
                'kill'    => 'Servidor detenido forzosamente.',
            ];

            Log::info("Admin power action: {$validated['signal']}", ['service_id' => $service->id]);

            return response()->json(['success' => true, 'message' => $labels[$validated['signal']]]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'No se pudo enviar la señal al servidor.'], 503);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // File manager (admin bypass)
    // ─────────────────────────────────────────────────────────────────────────────

    /** GET /admin/game-servers/{id}/files/list?directory=... */
    public function listFiles(Request $request, int $id): JsonResponse
    {
        $service    = Service::findOrFail($id);
        $identifier = $service->connection_details['identifier'] ?? null;

        if (!$identifier) {
            return response()->json(['success' => false, 'message' => 'Sin identificador de Pterodactyl.'], 404);
        }

        $validated = $request->validate([
            'directory' => ['sometimes', 'string', 'max:512', 'not_regex:/\.\.[\/\\\\]/'],
        ]);
        $directory = $validated['directory'] ?? '/';

        try {
            $response = Http::withToken(config('pterodactyl.client_api_key'))
                ->baseUrl(config('pterodactyl.base_url'))
                ->when(! config('pterodactyl.verify_ssl', true), fn ($h) => $h->withoutVerifying())
                ->acceptJson()
                ->get("/api/client/servers/{$identifier}/files/list", ['directory' => $directory]);

            if ($response->failed()) {
                return response()->json(['success' => false, 'message' => 'Error al listar archivos.'], 503);
            }

            $files = collect($response->json('data', []))
                ->filter(fn($f) => $f['attributes']['is_file'] ?? false)
                ->map(fn($f) => [
                    'name'        => $f['attributes']['name'],
                    'size'        => $f['attributes']['size'],
                    'modified_at' => $f['attributes']['modified_at'],
                    'is_file'     => $f['attributes']['is_file'],
                    'mimetype'    => $f['attributes']['mimetype'] ?? null,
                ])->values();

            return response()->json(['success' => true, 'data' => $files]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 503);
        }
    }

    /** GET /admin/game-servers/{id}/files/upload */
    public function uploadUrl(int $id): JsonResponse
    {
        $service    = Service::findOrFail($id);
        $identifier = $service->connection_details['identifier'] ?? null;

        if (!$identifier) {
            return response()->json(['success' => false, 'message' => 'Sin identificador de Pterodactyl.'], 404);
        }

        try {
            $response = Http::withToken(config('pterodactyl.client_api_key'))
                ->baseUrl(config('pterodactyl.base_url'))
                ->when(! config('pterodactyl.verify_ssl', true), fn ($h) => $h->withoutVerifying())
                ->acceptJson()
                ->get("/api/client/servers/{$identifier}/files/upload");

            if ($response->failed()) {
                return response()->json(['success' => false, 'message' => 'Error al obtener URL de subida.'], 503);
            }

            $url = $response->json('attributes.url') ?? $response->json('data.attributes.url');
            $url = $this->rewriteWingsUrl($url);

            return response()->json(['success' => true, 'data' => ['url' => $url]]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 503);
        }
    }

    /** POST /admin/game-servers/{id}/files/delete */
    public function deleteFiles(Request $request, int $id): JsonResponse
    {
        $service    = Service::findOrFail($id);
        $identifier = $service->connection_details['identifier'] ?? null;

        if (!$identifier) {
            return response()->json(['success' => false, 'message' => 'Sin identificador de Pterodactyl.'], 404);
        }

        $validated = $request->validate([
            'root'    => ['required', 'string', 'max:512', 'not_regex:/\.\.[\/\\\\]/'],
            'files'   => ['required', 'array', 'min:1', 'max:50'],
            'files.*' => ['required', 'string', 'max:512', 'not_regex:/\.\.[\/\\\\]/'],
        ]);

        try {
            $response = Http::withToken(config('pterodactyl.client_api_key'))
                ->baseUrl(config('pterodactyl.base_url'))
                ->when(! config('pterodactyl.verify_ssl', true), fn ($h) => $h->withoutVerifying())
                ->acceptJson()
                ->post("/api/client/servers/{$identifier}/files/delete", [
                    'root'  => $validated['root'],
                    'files' => $validated['files'],
                ]);

            if ($response->failed()) {
                return response()->json(['success' => false, 'message' => 'Error al eliminar archivos.'], 503);
            }

            return response()->json(['success' => true, 'message' => 'Archivos eliminados.']);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 503);
        }
    }

    /** GET /admin/game-servers/{id}/files/download?file=... */
    public function downloadUrl(Request $request, int $id): JsonResponse
    {
        $service    = Service::findOrFail($id);
        $identifier = $service->connection_details['identifier'] ?? null;

        if (!$identifier) {
            return response()->json(['success' => false, 'message' => 'Sin identificador de Pterodactyl.'], 404);
        }

        $validated = $request->validate([
            'file' => ['required', 'string', 'max:512', 'not_regex:/\.\.[\/\\\\]/'],
        ]);
        $file = $validated['file'];

        try {
            $response = Http::withToken(config('pterodactyl.client_api_key'))
                ->baseUrl(config('pterodactyl.base_url'))
                ->when(! config('pterodactyl.verify_ssl', true), fn ($h) => $h->withoutVerifying())
                ->acceptJson()
                ->get("/api/client/servers/{$identifier}/files/download", ['file' => $file]);

            if ($response->failed()) {
                return response()->json(['success' => false, 'message' => 'Error al obtener URL de descarga.'], 503);
            }

            $url = $response->json('attributes.url') ?? $response->json('data.attributes.url');
            $url = $this->rewriteWingsUrl($url);

            return response()->json(['success' => true, 'data' => ['url' => $url]]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 503);
        }
    }

    /**
     * POST /admin/game-servers/{id}/command
     * Envía un comando a la consola del servidor.
     */
    public function command(Request $request, int $id): JsonResponse
    {
        $service = Service::findOrFail($id);

        $validated  = $request->validate(['command' => 'required|string|max:500']);
        $identifier = $service->connection_details['identifier'] ?? null;

        if (!$identifier) {
            return response()->json(['success' => false, 'message' => 'Sin identificador de Pterodactyl.'], 500);
        }

        try {
            $response = Http::withToken(config('pterodactyl.client_api_key'))
                ->baseUrl(config('pterodactyl.base_url'))
                ->when(! config('pterodactyl.verify_ssl', true), fn ($h) => $h->withoutVerifying())
                ->acceptJson()
                ->post("/api/client/servers/{$identifier}/command", ['command' => $validated['command']]);

            if ($response->failed()) {
                return response()->json(['success' => false, 'message' => 'Error al enviar el comando.'], 503);
            }

            return response()->json(['success' => true, 'message' => 'Comando enviado.']);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 503);
        }
    }

    private function rewriteWingsUrl(string $url): string
    {
        $internalHost = preg_replace('#^https?://#i', '', rtrim(config('pterodactyl.wings_internal_url', ''), '/'));
        $publicHost   = preg_replace('#^https?://#i', '', rtrim(config('pterodactyl.wings_public_url', ''), '/'));

        if (! $internalHost || ! $publicHost) {
            return $url;
        }

        $escaped = preg_quote($internalHost, '#');
        $url = preg_replace("#^wss?://{$escaped}#i",   "wss://{$publicHost}",   $url);
        $url = preg_replace("#^https?://{$escaped}#i", "https://{$publicHost}", $url);

        return $url;
    }
}
