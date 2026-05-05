<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Models\ActivityLog;
use App\Exceptions\PterodactylApiException;
use App\Services\GameServers\GameServerRuntimeService;
use App\Services\Minecraft\MinecraftServerConfigurationService;
use App\Services\Pterodactyl\PterodactylService;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GameServerController extends Controller
{
    public function __construct(
        private readonly PterodactylService $pterodactyl
    ) {}

    /**
     * GET /services/{uuid}/usage
     * Uso de recursos en tiempo real (CPU, RAM, disco).
     */
    public function getServiceUsage(string $uuid): JsonResponse
    {
        $user    = Auth::user();
        $service = Service::where('user_id', $user->id)->where('uuid', $uuid)->firstOrFail();

        if ($service->isPterodactylManaged()) {
            $identifier = $service->connection_details['identifier'] ?? null;

            if (!$identifier) {
                return response()->json(['success' => false, 'message' => 'El servidor aún no tiene identificador asignado.'], 404);
            }

            try {
                $resources = $this->pterodactyl->getServerResources($identifier);

                return response()->json([
                    'success' => true,
                    'data'    => [
                        'state'        => $resources['current_state']              ?? 'offline',
                        'is_suspended' => $resources['is_suspended']               ?? false,
                        'cpu'          => $resources['resources']['cpu_absolute']  ?? 0,
                        'memory_bytes' => $resources['resources']['memory_bytes']  ?? 0,
                        'disk_bytes'   => $resources['resources']['disk_bytes']    ?? 0,
                        'network_rx'   => $resources['resources']['network_rx_bytes'] ?? 0,
                        'network_tx'   => $resources['resources']['network_tx_bytes'] ?? 0,
                        'uptime_ms'    => $resources['resources']['uptime']        ?? 0,
                    ],
                ]);
            } catch (\Throwable $e) {
                Log::warning('No se pudieron obtener métricas de Pterodactyl', [
                    'service_id' => $service->id,
                    'error'      => $e->getMessage(),
                ]);
                return response()->json(['success' => false, 'message' => 'No se pudo conectar al panel. Intenta de nuevo.'], 503);
            }
        }

        $usage = $service->configuration['usage'] ?? null;

        return response()->json([
            'success' => true,
            'data'    => $usage,
            'message' => $usage ? null : 'No hay datos de uso disponibles para este servicio.',
        ]);
    }

    /**
     * GET /services/metrics
     * Métricas cacheadas (30s en Redis) de todos los game servers del usuario.
     */
    public function getAllServicesMetrics(): JsonResponse
    {
        $user = Auth::user();

        $services = Service::where('user_id', $user->id)
            ->where('status', 'active')
            ->whereNotNull('pterodactyl_server_id')
            ->with('plan')
            ->get(['id', 'uuid', 'pterodactyl_server_id', 'connection_details', 'plan_id']);

        if ($services->isEmpty()) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $metrics = cache()->remember("user:{$user->id}:services:metrics", 30, function () use ($services) {
            $result = [];

            foreach ($services as $service) {
                $identifier = $service->connection_details['identifier'] ?? null;
                if (!$identifier) continue;

                try {
                    $resources     = $this->pterodactyl->getServerResources($identifier);
                    $memoryBytes   = $resources['resources']['memory_bytes'] ?? 0;
                    $diskBytes     = $resources['resources']['disk_bytes']   ?? 0;

                    $service->loadMissing('plan');
                    $memoryLimit = ($service->plan?->pterodactyl_limits['memory'] ?? 0) * 1024 * 1024;
                    $diskLimit   = ($service->plan?->pterodactyl_limits['disk']   ?? 0) * 1024 * 1024;

                    $result[$service->uuid] = [
                        'state'        => $resources['current_state'] ?? 'offline',
                        'suspended'    => $resources['is_suspended']  ?? false,
                        'cpu'          => round($resources['resources']['cpu_absolute'] ?? 0, 1),
                        'memory'       => $memoryLimit > 0 ? round(($memoryBytes / $memoryLimit) * 100, 1) : 0,
                        'disk'         => $diskLimit   > 0 ? round(($diskBytes   / $diskLimit)   * 100, 1) : 0,
                        'memory_bytes' => $memoryBytes,
                        'memory_limit' => $memoryLimit,
                        'disk_bytes'   => $diskBytes,
                        'disk_limit'   => $diskLimit,
                        'network_rx'   => $resources['resources']['network_rx_bytes'] ?? 0,
                        'network_tx'   => $resources['resources']['network_tx_bytes'] ?? 0,
                        'uptime'       => round(($resources['resources']['uptime'] ?? 0) / 1000),
                    ];
                } catch (\Throwable) {
                    $result[$service->uuid] = ['state' => 'unknown', 'error' => true];
                }
            }

            return $result;
        });

        return response()->json(['success' => true, 'data' => $metrics]);
    }

    /**
     * POST /services/{uuid}/game-server/power
     * Envía señal de poder: start | stop | restart | kill
     */
    public function power(Request $request, string $uuid): JsonResponse
    {
        $user    = Auth::user();
        $service = Service::where('user_id', $user->id)->where('uuid', $uuid)->firstOrFail();

        if (!$service->isPterodactylManaged()) {
            return response()->json(['success' => false, 'message' => 'Este servicio no es un servidor de juego administrado.'], 422);
        }

        if ($service->status === 'suspended') {
            return response()->json(['success' => false, 'message' => 'Tu servidor está suspendido. Contacta a soporte.'], 403);
        }

        $validated = $request->validate([
            'signal' => ['required', \Illuminate\Validation\Rule::in(['start', 'stop', 'restart', 'kill'])],
        ], [
            'signal.in' => 'Señal inválida. Usa: start, stop, restart o kill.',
        ]);

        $identifier = $service->connection_details['identifier'] ?? null;

        if (!$identifier) {
            return response()->json(['success' => false, 'message' => 'El servidor no tiene identificador asignado. Contacta a soporte.'], 500);
        }

        try {
            $this->pterodactyl->sendPowerSignal($identifier, $validated['signal']);

            // Limpiar restart_required cuando el cliente reinicia o inicia
            if (in_array($validated['signal'], ['restart', 'start'])) {
                $service->update([
                    'restart_required'      => false,
                    'pending_changes_count' => 0,
                ]);
            }

            ActivityLog::record(
                "Power action: {$validated['signal']}",
                "El cliente ejecutó '{$validated['signal']}' en el servicio {$service->name}.",
                'service',
                ['service_id' => $service->id, 'signal' => $validated['signal']],
                $user->id
            );

            $labels = [
                'start'   => 'Servidor iniciando...',
                'stop'    => 'Servidor deteniéndose...',
                'restart' => 'Servidor reiniciando...',
                'kill'    => 'Servidor detenido forzosamente.',
            ];

            return response()->json(['success' => true, 'message' => $labels[$validated['signal']]]);
        } catch (\Throwable $e) {
            Log::error('Power action fallida', [
                'service_id' => $service->id,
                'signal'     => $validated['signal'],
                'error'      => $e->getMessage(),
            ]);
            return response()->json(['success' => false, 'message' => 'No se pudo enviar la señal al servidor. Intenta de nuevo.'], 503);
        }
    }

    /**
     * GET /services/{uuid}/game-server/websocket
     * Retorna token + URL del WebSocket de Wings.
     */
    public function websocket(string $uuid): JsonResponse
    {
        $user    = Auth::user();
        $service = Service::where('user_id', $user->id)->where('uuid', $uuid)->firstOrFail();

        if (!$service->isPterodactylManaged()) {
            return response()->json(['success' => false, 'message' => 'Este servicio no tiene consola disponible.'], 422);
        }

        if ($service->status === 'suspended') {
            return response()->json(['success' => false, 'message' => 'El servidor está suspendido.'], 403);
        }

        $identifier = $service->connection_details['identifier'] ?? null;

        if (!$identifier) {
            return response()->json(['success' => false, 'message' => 'El servidor no tiene identificador asignado.'], 404);
        }

        try {
            $response = Http::withToken(config('pterodactyl.client_api_key'))
                ->baseUrl(config('pterodactyl.base_url'))
                ->withoutVerifying()
                ->acceptJson()
                ->get("/api/client/servers/{$identifier}/websocket");

            if ($response->failed()) {
                Log::error('Pterodactyl websocket credentials failed', [
                    'service_id' => $service->id,
                    'status'     => $response->status(),
                ]);
                return response()->json(['success' => false, 'message' => 'No se pudo obtener acceso a la consola.'], 503);
            }

            $wsData = $response->json('data');
            $wsData['socket'] = preg_replace(
                '#^wss?://100\.94\.93\.51:8080#',
                'wss://mc.rokeindustries.com',
                $wsData['socket']
            );

            return response()->json(['success' => true, 'data' => $wsData]);
        } catch (\Throwable $e) {
            Log::error('gameServerWebsocket error', ['service_id' => $service->id, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Error al conectar con el panel.'], 503);
        }
    }

    /**
     * POST /services/{uuid}/game-server/command
     * Envía un comando puntual a la consola (sin WebSocket).
     */
    public function command(Request $request, string $uuid): JsonResponse
    {
        $user    = Auth::user();
        $service = Service::where('user_id', $user->id)->where('uuid', $uuid)->firstOrFail();

        if (!$service->isPterodactylManaged()) {
            return response()->json(['success' => false, 'message' => 'Este servicio no soporta comandos.'], 422);
        }

        $validated  = $request->validate(['command' => ['required', 'string', 'max:255']]);
        $identifier = $service->connection_details['identifier'] ?? null;

        if (!$identifier) {
            return response()->json(['success' => false, 'message' => 'El servidor no tiene identificador asignado.'], 404);
        }

        try {
            $response = Http::withToken(config('pterodactyl.client_api_key'))
                ->baseUrl(config('pterodactyl.base_url'))
                ->withoutVerifying()
                ->acceptJson()
                ->post("/api/client/servers/{$identifier}/command", ['command' => $validated['command']]);

            if ($response->status() === 204) {
                return response()->json(['success' => true, 'message' => 'Comando enviado.']);
            }

            return response()->json(['success' => false, 'message' => 'El servidor no aceptó el comando. ¿Está en línea?'], 422);
        } catch (\Throwable $e) {
            Log::error('gameServerCommand error', ['service_id' => $service->id, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Error al enviar el comando.'], 503);
        }
    }

    /**
     * GET /services/{uuid}/game-server/software-options
     */
    public function softwareOptions(Request $request, string $uuid, GameServerRuntimeService $runtime): JsonResponse
    {
        $service = $this->findOwnedGameServerService($request, $uuid);

        return response()->json([
            'data' => $runtime->softwareOptions($service),
            'game_type' => $runtime->gameType($service),
        ]);
    }

    /**
     * GET /services/{uuid}/game-server/configuration
     */
    public function configuration(
        Request $request,
        string $uuid,
        MinecraftServerConfigurationService $configuration
    ): JsonResponse {
        $service = $this->findOwnedMinecraftService($request, $uuid);

        try {
            return response()->json(['data' => $configuration->configuration($service)]);
        } catch (PterodactylApiException $e) {
            return response()->json(['message' => $e->getMessage()], $e->statusCode());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * PATCH /services/{uuid}/game-server/software
     */
    public function updateSoftware(
        Request $request,
        string $uuid,
        MinecraftServerConfigurationService $configuration
    ): JsonResponse {
        $validated = $request->validate([
            'software' => ['required', 'string'],
            'version' => ['required', 'string'],
        ]);

        $service = $this->findOwnedMinecraftService($request, $uuid);

        try {
            return response()->json([
                'data' => $configuration->updateSoftware($service, $validated['software'], $validated['version']),
            ]);
        } catch (PterodactylApiException $e) {
            return response()->json(['message' => $e->getMessage()], $e->statusCode());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Obtener la config de statup
     * GET usando getStartupConfig
     */
    public function getStartupConfig(string $uuid): JsonResponse
    {

        try {
            $user    = Auth::user();
            $service = Service::where('user_id', $user->id)->where('uuid', $uuid)->firstOrFail();

            if ($service->isPterodactylManaged()) {
                $identifier = $service->connection_details['identifier'] ?? null;

                if (!$identifier) {
                    return response()->json(['success' => false, 'message' => 'El servidor aún no tiene identificador asignado.'], 404);
                }
            } else {
                return response()->json(['success' => false, 'message' => 'Este servicio no es un servidor de juego administrado.'], 422);
            }

            return response()->json([
                'data' => $this->pterodactyl->getStartupConfig($identifier),
            ]);
        } catch (PterodactylApiException $e) {
            return response()->json(['message' => $e->getMessage()], $e->statusCode());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * PATCH /services/{uuid}/game-server/server-properties
     */
    public function updateServerProperties(
        Request $request,
        string $uuid,
        MinecraftServerConfigurationService $configuration
    ): JsonResponse {
        $service = $this->findOwnedMinecraftService($request, $uuid);

        try {
            return response()->json([
                'data' => $configuration->updateServerProperties($service, $request->all()),
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (PterodactylApiException $e) {
            return response()->json(['message' => $e->getMessage()], $e->statusCode());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * POST /services/{uuid}/game-server/fix-java
     * Corrige la imagen Docker del servidor para usar la versión de Java correcta.
     * No reinstala el servidor (skip_scripts=true) — solo actualiza el contenedor.
     * El usuario debe reiniciar manualmente para aplicar el cambio.
     *
     * Body (opcional):
     *   target_java: int  — versión de Java explícita (útil cuando el error viene
     *                       de un plugin/mod compilado con un Java más reciente).
     *                       Null → se calcula automáticamente desde la versión MC.
     */
    public function fixJavaVersion(
        Request $request,
        string $uuid,
        MinecraftServerConfigurationService $configuration
    ): JsonResponse {
        // Aceptar todas las versiones disponibles en Pterodactyl Yolks
        $availableJava = $configuration->availableJavaVersionNumbers();

        $validated = $request->validate([
            'target_java' => ['sometimes', 'nullable', 'integer', \Illuminate\Validation\Rule::in($availableJava)],
        ], [
            'target_java.in' => 'La versión de Java debe ser una de las disponibles en Pterodactyl Yolks: ' . implode(', ', $availableJava) . '.',
        ]);

        $service = $this->findOwnedMinecraftService($request, $uuid);

        try {
            $targetJava = isset($validated['target_java']) ? (int) $validated['target_java'] : null;
            $result     = $configuration->fixJavaVersion($service, $targetJava);
            return response()->json($result);
        } catch (PterodactylApiException $e) {
            return response()->json(['message' => $e->getMessage()], $e->statusCode());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * GET /services/{uuid}/game-server/java-check
     * Lee los logs del servidor y detecta errores de compatibilidad de Java.
     * Devuelve el diagnóstico completo sin aplicar ninguna corrección.
     */
    public function checkJavaCompatibility(
        Request $request,
        string $uuid,
        MinecraftServerConfigurationService $configuration
    ): JsonResponse {
        $service = $this->findOwnedMinecraftService($request, $uuid);

        try {
            $result = $configuration->detectJavaCompatibilityFromLogs($service);
            return response()->json(['success' => true, 'data' => $result]);
        } catch (PterodactylApiException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->statusCode());
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * POST /services/{uuid}/game-server/java-autofix
     * Lee los logs, detecta el error de Java y lo corrige automáticamente.
     * Si no hay error, devuelve has_error=false y fix_applied=false.
     */
    public function autoFixJavaCompatibility(
        Request $request,
        string $uuid,
        MinecraftServerConfigurationService $configuration
    ): JsonResponse {
        $service = $this->findOwnedMinecraftService($request, $uuid);

        try {
            $result = $configuration->checkAndFixJavaCompatibility($service);
            return response()->json(['success' => true, 'data' => $result]);
        } catch (PterodactylApiException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->statusCode());
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * GET /services/{uuid}/game-server/java-requirements
     * Devuelve la tabla de requisitos de Java por versión de Minecraft.
     * Útil para mostrar en el frontend "¿qué Java necesita mi versión de MC?".
     */
    public function javaRequirements(
        Request $request,
        string $uuid,
        MinecraftServerConfigurationService $configuration
    ): JsonResponse {
        // Solo necesitamos que sea un game server válido del usuario
        $this->findOwnedMinecraftService($request, $uuid);

        return response()->json([
            'success' => true,
            'data'    => [
                'requirements'     => $configuration->javaRequirementsTable(),
                'available_images' => $configuration->availableJavaVersionNumbers(),
            ],
        ]);
    }

    /**
     * GET /services/{uuid}/game-server/eula
     * Devuelve si el EULA de Minecraft fue aceptado en este servidor.
     * Solo aplica a servidores Minecraft (Java Edition).
     */
    public function eulaStatus(
        Request $request,
        string $uuid,
        MinecraftServerConfigurationService $configuration
    ): JsonResponse {
        $service = $this->findOwnedMinecraftService($request, $uuid);

        try {
            return response()->json([
                'eula_accepted' => $configuration->eulaAccepted($service),
            ]);
        } catch (PterodactylApiException $e) {
            return response()->json(['message' => $e->getMessage()], $e->statusCode());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * POST /services/{uuid}/game-server/eula/accept
     * Escribe eula=true en el archivo eula.txt del servidor.
     * El usuario debe haber aceptado explícitamente en el frontend.
     */
    public function acceptEula(
        Request $request,
        string $uuid,
        MinecraftServerConfigurationService $configuration
    ): JsonResponse {
        $service = $this->findOwnedMinecraftService($request, $uuid);

        try {
            $configuration->acceptEula($service);

            return response()->json([
                'success'       => true,
                'eula_accepted' => true,
                'message'       => 'EULA aceptado correctamente.',
            ]);
        } catch (PterodactylApiException $e) {
            return response()->json(['message' => $e->getMessage()], $e->statusCode());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * POST /services/{uuid}/files/restart-required
     * Marca el servicio como pendiente de reinicio tras subir archivos.
     */
    public function markRestartRequired(Request $request, string $uuid): JsonResponse
    {
        $service = Service::where('user_id', $request->user()->id)->where('uuid', $uuid)->firstOrFail();

        $service->increment('pending_changes_count');
        $service->update(['restart_required' => true]);

        return response()->json([
            'success'               => true,
            'restart_required'      => true,
            'pending_changes_count' => $service->fresh()->pending_changes_count,
        ]);
    }

    /**
     * GET /services/game-servers/{nest_id}/eggs
     * List Nest Eggs
     */
    public function listEggs(int $nest_id): JsonResponse
{
    $eggs = $this->pterodactyl->listNestEggs($nest_id);

    $cleaned = collect($eggs ?? [])->map(function ($egg) {

        $variables = $egg['relationships']['variables']['data'] ?? [];

        // 🔥 Detectar variable de versión de forma inteligente
        $versionVariable = collect($variables)->first(function ($var) {
            $env = $var['attributes']['env_variable'] ?? '';
            return str_contains($env, 'VERSION');
        });

        return [
            'id' => $egg['id'] ?? null,
            'uuid' => $egg['uuid'] ?? null,
            'name' => $egg['name'] ?? null,
            'description' => $egg['description'] ?? null,

            // puede ser MINECRAFT_VERSION, SERVER_VERSION, etc.
            'version_variable' => $versionVariable['attributes']['env_variable'] ?? null,
            'version' => $versionVariable['attributes']['default_value'] ?? null,

            // 👇 opcional pero MUY útil para SaaS
            // 'variables' => collect($variables)->map(function ($var) {
            //     return [
            //         'key' => $var['attributes']['env_variable'] ?? null,
            //         'default' => $var['attributes']['default_value'] ?? null,
            //         'editable' => $var['attributes']['user_editable'] ?? false,
            //     ];
            // })->values(),
        ];
    })->values();

    return response()->json([
        'status' => 'success',
        'code' => 200,
        'message' => 'Eggs retrieved successfully.',
        'data' => $cleaned,
        // 'tiempo de respuesta' => now()->diffInSeconds(request()->server('REQUEST_TIME_FLOAT'), true) . 's',
    ]);
}

    private function findOwnedGameServerService(Request $request, string $uuid): Service
    {
        $service = Service::where('user_id', $request->user()->id)
            ->where('uuid', $uuid)
            ->with('plan.category')
            ->firstOrFail();

        $categorySlug = $service->plan?->category?->slug;

        if (!$service->isPterodactylManaged() || !in_array($categorySlug, ['gameserver', 'game-servers'], true)) {
            abort(response()->json(['message' => 'Este servicio no es un servidor de juego administrado.'], 422));
        }

        if (!($service->connection_details['identifier'] ?? null)) {
            abort(response()->json(['message' => 'El servidor no tiene un identificador asignado.'], 404));
        }

        return $service;
    }

    private function findOwnedMinecraftService(Request $request, string $uuid): Service
    {
        $service = $this->findOwnedGameServerService($request, $uuid);

        // if ($service->plan?->game_type !== 'minecraft') {
        //     abort(response()->json([
        //         'message' => 'Este endpoint de configuración solo está disponible para servidores Minecraft.',
        //     ], 422));
        // }

        return $service;
    }


}
