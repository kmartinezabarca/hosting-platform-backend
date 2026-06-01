<?php

namespace App\Domains\Platform\Http\Controllers\Client;

use App\Domains\Platform\Events\GameServerPingBroadcast;
use App\Http\Controllers\Controller;
use App\Domains\Platform\Models\GameServerPing;
use App\Domains\Platform\Models\PterodactylEgg;
use App\Domains\Platform\Models\Service;
use App\Domains\Platform\Models\ServiceMetric;
use App\Domains\Platform\Models\ActivityLog;
use App\Exceptions\PterodactylApiException;
use App\Domains\Platform\Services\GameServers\GameServerRuntimeService;
use App\Domains\Platform\Services\Minecraft\MinecraftPingService;
use App\Domains\Platform\Services\Minecraft\MinecraftServerConfigurationService;
use App\Domains\Platform\Services\Pterodactyl\PterodactylService;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GameServerController extends Controller
{
    public function __construct(
        private readonly PterodactylService  $pterodactyl,
        private readonly MinecraftPingService $minecraftPing,
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

                $service->loadMissing('plan');
                $memoryLimit = ($service->plan?->pterodactyl_limits['memory'] ?? 0) * 1024 * 1024;
                $diskLimit   = ($service->plan?->pterodactyl_limits['disk']   ?? 0) * 1024 * 1024;
                $uptimeMs    = $resources['resources']['uptime'] ?? 0;

                return response()->json([
                    'success' => true,
                    'data'    => [
                        'state'          => $resources['current_state']                 ?? 'offline',
                        'is_suspended'   => $resources['is_suspended']                  ?? false,
                        'cpu'            => $resources['resources']['cpu_absolute']     ?? 0,
                        'memory_bytes'   => $resources['resources']['memory_bytes']     ?? 0,
                        'memory_limit'   => $memoryLimit,
                        'disk_bytes'     => $resources['resources']['disk_bytes']       ?? 0,
                        'disk_limit'     => $diskLimit,
                        'network_rx'     => $resources['resources']['network_rx_bytes'] ?? 0,
                        'network_tx'     => $resources['resources']['network_tx_bytes'] ?? 0,
                        'uptime_ms'      => $uptimeMs,
                        'uptime_seconds' => (int) round($uptimeMs / 1000),
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
     * GET /services/{uuid}/game-server/logs
     * Retorna las últimas N líneas del archivo logs/latest.log del servidor.
     */
    public function getLogs(Request $request, string $uuid): JsonResponse
    {
        $user    = Auth::user();
        $service = Service::where('user_id', $user->id)->where('uuid', $uuid)->firstOrFail();

        if (!$service->isPterodactylManaged()) {
            return response()->json(['success' => false, 'message' => 'Este servicio no es un servidor de juego administrado.'], 422);
        }

        $identifier = $service->connection_details['identifier'] ?? null;

        if (!$identifier) {
            return response()->json(['success' => false, 'message' => 'El servidor aún no tiene identificador asignado.'], 404);
        }

        $lines = (int) $request->query('lines', 100);
        $lines = max(1, min($lines, 500));

        try {
            $content    = $this->pterodactyl->readServerFile($identifier, 'logs/latest.log');
            $allLines   = explode("\n", str_replace("\r\n", "\n", $content));
            $allLines   = array_filter($allLines, fn($l) => $l !== '');
            $lastLines  = array_values(array_slice($allLines, -$lines));

            return response()->json([
                'success' => true,
                'data'    => [
                    'lines'     => $lastLines,
                    'server_id' => $identifier,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('No se pudo leer logs/latest.log de Pterodactyl', [
                'service_id' => $service->id,
                'error'      => $e->getMessage(),
            ]);
            return response()->json(['success' => false, 'message' => 'No se pudo leer el archivo de logs. El servidor puede estar apagado o el archivo no existe.'], 503);
        }
    }

    /**
     * GET /services/{uuid}/game-server/status
     * Estado liviano del servidor: jugadores conectados y ping de estado Minecraft.
     */
    public function getStatus(string $uuid): JsonResponse
    {
        $user    = Auth::user();
        $service = Service::where('user_id', $user->id)->where('uuid', $uuid)->firstOrFail();

        if (!$service->isPterodactylManaged()) {
            return response()->json(['success' => false, 'message' => 'Este servicio no es un servidor de juego administrado.'], 422);
        }

        $identifier  = $service->connection_details['identifier'] ?? null;
        // connection_details almacena la IP como 'server_ip' / 'server_port'.
        // 'host' / 'port' son alias legacy que pueden no estar presentes.
        $host = $service->connection_details['host']       ?? $service->connection_details['server_ip']   ?? null;
        $port = (int) ($service->connection_details['port'] ?? $service->connection_details['server_port'] ?? 25565);

        if (!$identifier) {
            return response()->json(['success' => false, 'message' => 'El servidor aún no tiene identificador asignado.'], 404);
        }

        // Obtener estado básico de Pterodactyl (running/offline/etc.)
        try {
            $resources   = $this->pterodactyl->getServerResources($identifier);
            $state       = $resources['current_state'] ?? 'offline';
            $isSuspended = $resources['is_suspended']  ?? false;
        } catch (\Throwable $e) {
            Log::warning('No se pudo obtener estado de Pterodactyl para status', [
                'service_id' => $service->id,
                'error'      => $e->getMessage(),
            ]);
            return response()->json(['success' => false, 'message' => 'No se pudo conectar al panel. Intenta de nuevo.'], 503);
        }

        $statusData = [
            'state'        => $state,
            'is_suspended' => $isSuspended,
            'online'       => $state === 'running',
            'players'      => null,
            'max_players'  => null,
            'motd'         => null,
            'version'      => null,
            'ping_ms'      => null,
            'server_id'    => $identifier,
        ];

        // Si el servidor está corriendo y tenemos host, intentar ping Minecraft
        if ($state === 'running' && $host) {
            try {
                $pingResult = $this->minecraftPing->ping($host, $port, timeoutMs: 2000);
                if ($pingResult !== null) {
                    $statusData['players']     = $pingResult['players']['online']  ?? 0;
                    $statusData['max_players'] = $pingResult['players']['max']     ?? 0;
                    $statusData['player_sample'] = $pingResult['players']['sample'] ?? [];
                    $statusData['motd']        = $pingResult['description']        ?? null;
                    $statusData['version']     = $pingResult['version']['name']    ?? null;
                    $statusData['ping_ms']     = $pingResult['ping_ms']            ?? null;
                }
            } catch (\Throwable) {
                // El ping falló (servidor iniciando, no es Minecraft, firewall, etc.) — no es fatal
            }
        }

        return response()->json(['success' => true, 'data' => $statusData]);
    }

    // ── Anti-DDoS ─────────────────────────────────────────────────────────────

    /**
     * GET /services/{uuid}/game-server/ddos
     * Devuelve el estado de protección DDoS, información del nodo y lista de IPs permitidas.
     */
    public function ddosStatus(string $uuid): JsonResponse
    {
        $service = Service::where('user_id', Auth::id())
            ->where('uuid', $uuid)
            ->with(['plan', 'serverNode'])
            ->firstOrFail();

        $conn      = $service->connection_details ?? [];
        $allowlist = $conn['ddos_allowlist'] ?? [];
        $tier      = $this->resolveDdosTier($service);
        $node      = $service->serverNode;

        return response()->json([
            'success' => true,
            'data'    => [
                'protection' => [
                    'active'         => true,
                    'tier'           => $tier['name'],
                    'description'    => $tier['description'],
                    'mitigation'     => $tier['mitigation'],
                    'bandwidth_gbps' => $tier['bandwidth_gbps'],
                    'protocols'      => $tier['protocols'],
                ],
                'location' => [
                    'name'       => $node?->location          ?? 'Datacenter ROKE',
                    'hostname'   => $node?->hostname          ?? null,
                    'ip_address' => $node?->ip_address        ?? ($conn['server_ip'] ?? null),
                ],
                'allowlist' => array_values($allowlist),
            ],
        ]);
    }

    /**
     * POST /services/{uuid}/game-server/ddos/allowlist
     * Agrega una IP o CIDR a la lista de IPs permitidas durante la mitigación.
     * Body: { ip: "1.2.3.4" | "1.2.3.0/24", label?: "Mi casa" }
     */
    public function ddosAllowlistAdd(Request $request, string $uuid): JsonResponse
    {
        $service = Service::where('user_id', Auth::id())
            ->where('uuid', $uuid)
            ->firstOrFail();

        $validated = $request->validate([
            'ip'    => ['required', 'string', 'max:50', function ($attr, $value, $fail) {
                $value = trim($value);
                // Accept IPv4, IPv6 or CIDR
                $ip = preg_replace('#/\d+$#', '', $value);
                if (! filter_var($ip, FILTER_VALIDATE_IP)) {
                    $fail('La dirección IP no es válida. Usa IPv4, IPv6 o notación CIDR (ej: 192.168.1.0/24).');
                }
            }],
            'label' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);

        $conn      = $service->connection_details ?? [];
        $allowlist = $conn['ddos_allowlist'] ?? [];

        // Verificar duplicados
        $ip = trim($validated['ip']);
        $exists = collect($allowlist)->firstWhere('ip', $ip);
        if ($exists) {
            return response()->json(['success' => false, 'message' => 'Esta IP ya está en la lista.'], 422);
        }

        // Limitar a 20 IPs
        if (count($allowlist) >= 20) {
            return response()->json(['success' => false, 'message' => 'Se alcanzó el límite de 20 IPs permitidas.'], 422);
        }

        $entry = [
            'ip'         => $ip,
            'label'      => $validated['label'] ?? null,
            'added_at'   => now()->toISOString(),
        ];

        $allowlist[] = $entry;

        $service->update([
            'connection_details' => array_merge($conn, ['ddos_allowlist' => $allowlist]),
        ]);

        return response()->json(['success' => true, 'data' => $entry], 201);
    }

    /**
     * DELETE /services/{uuid}/game-server/ddos/allowlist/{ip}
     * Elimina una IP de la lista de permitidas.
     * {ip} es la dirección IP codificada en URL.
     */
    public function ddosAllowlistRemove(Request $request, string $uuid, string $ip): JsonResponse
    {
        $service = Service::where('user_id', Auth::id())
            ->where('uuid', $uuid)
            ->firstOrFail();

        $target    = rawurldecode($ip);
        $conn      = $service->connection_details ?? [];
        $allowlist = collect($conn['ddos_allowlist'] ?? []);

        if ($allowlist->firstWhere('ip', $target) === null) {
            return response()->json(['success' => false, 'message' => 'IP no encontrada en la lista.'], 404);
        }

        $filtered = $allowlist->reject(fn ($e) => $e['ip'] === $target)->values()->all();

        $service->update([
            'connection_details' => array_merge($conn, ['ddos_allowlist' => $filtered]),
        ]);

        return response()->json(['success' => true, 'message' => 'IP eliminada de la lista.']);
    }

    /**
     * Resuelve el tier de protección DDoS según las especificaciones del plan.
     */
    private function resolveDdosTier(Service $service): array
    {
        // 1. Buscar campo explícito en especificaciones del plan
        $specs = $service->plan?->specifications ?? [];
        if (! empty($specs['ddos_protection'])) {
            $t = $specs['ddos_protection'];
            return [
                'name'           => $t['tier']          ?? 'Personalizado',
                'description'    => $t['description']   ?? 'Protección anti-DDoS activa.',
                'mitigation'     => $t['mitigation']    ?? 'automatic',
                'bandwidth_gbps' => $t['bandwidth_gbps'] ?? 100,
                'protocols'      => $t['protocols']     ?? ['UDP', 'TCP', 'ICMP'],
            ];
        }

        // 2. Derivar del precio del servicio
        $price = (float) ($service->price ?? $service->plan?->base_price ?? 0);

        if ($price >= 50) {
            return [
                'name'           => 'Premium',
                'description'    => 'Mitigación automática de alta capacidad. Filtra ataques volumétricos, de protocolo y de capa 7.',
                'mitigation'     => 'automatic',
                'bandwidth_gbps' => 1000,
                'protocols'      => ['UDP flood', 'TCP SYN flood', 'ICMP flood', 'DNS amplification', 'NTP amplification', 'Layer 7'],
            ];
        }

        if ($price >= 20) {
            return [
                'name'           => 'Estándar',
                'description'    => 'Mitigación automática contra los vectores de ataque más comunes.',
                'mitigation'     => 'automatic',
                'bandwidth_gbps' => 300,
                'protocols'      => ['UDP flood', 'TCP SYN flood', 'ICMP flood', 'DNS amplification'],
            ];
        }

        return [
            'name'           => 'Básico',
            'description'    => 'Protección base contra ataques volumétricos de menor escala.',
            'mitigation'     => 'automatic',
            'bandwidth_gbps' => 100,
            'protocols'      => ['UDP flood', 'TCP SYN flood', 'ICMP flood'],
        ];
    }

    // ── Firewall ──────────────────────────────────────────────────────────────

    /**
     * GET /services/{uuid}/game-server/firewall
     * Devuelve configuración de firewall del servidor.
     */
    public function firewallStatus(string $uuid): JsonResponse
    {
        $service = Service::where('user_id', Auth::id())
            ->where('uuid', $uuid)
            ->firstOrFail();

        $conn     = $service->connection_details ?? [];
        $firewall = $conn['firewall'] ?? [];

        return response()->json([
            'success' => true,
            'data' => [
                'mode'                   => $firewall['mode']                   ?? 'open',
                'max_connections_per_ip' => $firewall['max_connections_per_ip'] ?? 3,
                'blocked_ips'            => $firewall['blocked_ips']            ?? [],
            ],
        ]);
    }

    /**
     * PUT /services/{uuid}/game-server/firewall/settings
     * Actualiza modo de firewall y límite de conexiones por IP.
     * Body: { mode: "open"|"whitelist", max_connections_per_ip: int }
     */
    public function firewallUpdateSettings(Request $request, string $uuid): JsonResponse
    {
        $service = Service::where('user_id', Auth::id())
            ->where('uuid', $uuid)
            ->firstOrFail();

        $validated = $request->validate([
            'mode'                   => ['sometimes', 'string', 'in:open,whitelist'],
            'max_connections_per_ip' => ['sometimes', 'integer', 'min:1', 'max:20'],
        ]);

        $conn              = $service->connection_details ?? [];
        $firewall          = $conn['firewall'] ?? [];

        if (isset($validated['mode'])) {
            $firewall['mode'] = $validated['mode'];
        }
        if (isset($validated['max_connections_per_ip'])) {
            $firewall['max_connections_per_ip'] = $validated['max_connections_per_ip'];
        }

        $conn['firewall']         = $firewall;
        $service->connection_details = $conn;
        $service->save();

        return response()->json([
            'success' => true,
            'message' => 'Configuración de firewall actualizada.',
            'data'    => [
                'mode'                   => $firewall['mode']                   ?? 'open',
                'max_connections_per_ip' => $firewall['max_connections_per_ip'] ?? 3,
            ],
        ]);
    }

    /**
     * POST /services/{uuid}/game-server/firewall/blocked-ips
     * Bloquea una IP o CIDR.
     * Body: { ip: "1.2.3.4", reason?: "spam" }
     */
    public function firewallBlockIp(Request $request, string $uuid): JsonResponse
    {
        $service = Service::where('user_id', Auth::id())
            ->where('uuid', $uuid)
            ->firstOrFail();

        $validated = $request->validate([
            'ip'     => ['required', 'string', 'max:50'],
            'reason' => ['nullable', 'string', 'max:200'],
        ]);

        $ip = trim($validated['ip']);

        // Validate IPv4, IPv6 or CIDR
        $isValid = filter_var($ip, FILTER_VALIDATE_IP)
            || preg_match('/^(\d{1,3}\.){3}\d{1,3}\/\d{1,2}$/', $ip)
            || preg_match('/^[0-9a-fA-F:]+\/\d{1,3}$/', $ip);

        if (! $isValid) {
            return response()->json(['success' => false, 'message' => 'Dirección IP o CIDR inválida.'], 422);
        }

        $conn     = $service->connection_details ?? [];
        $firewall = $conn['firewall'] ?? [];
        $list     = $firewall['blocked_ips'] ?? [];

        if (count($list) >= 50) {
            return response()->json(['success' => false, 'message' => 'Límite de 50 IPs bloqueadas alcanzado.'], 422);
        }
        if (collect($list)->contains(fn ($e) => $e['ip'] === $ip)) {
            return response()->json(['success' => false, 'message' => 'Esta IP ya está bloqueada.'], 422);
        }

        $entry = [
            'ip'       => $ip,
            'reason'   => $validated['reason'] ?? null,
            'added_at' => now()->toISOString(),
        ];

        $list[]                   = $entry;
        $firewall['blocked_ips']  = $list;
        $conn['firewall']         = $firewall;
        $service->connection_details = $conn;
        $service->save();

        return response()->json(['success' => true, 'data' => $entry], 201);
    }

    /**
     * DELETE /services/{uuid}/game-server/firewall/blocked-ips/{ip}
     * Desbloquea una IP o CIDR.
     */
    public function firewallUnblockIp(Request $request, string $uuid, string $ip): JsonResponse
    {
        $service = Service::where('user_id', Auth::id())
            ->where('uuid', $uuid)
            ->firstOrFail();

        $ipDecoded = rawurldecode($ip);
        $conn      = $service->connection_details ?? [];
        $firewall  = $conn['firewall'] ?? [];
        $list      = $firewall['blocked_ips'] ?? [];

        $filtered = array_values(array_filter($list, fn ($e) => $e['ip'] !== $ipDecoded));

        $firewall['blocked_ips']  = $filtered;
        $conn['firewall']         = $firewall;
        $service->connection_details = $conn;
        $service->save();

        return response()->json(['success' => true, 'message' => "IP {$ipDecoded} desbloqueada."]);
    }

    /**
     * GET /services/{uuid}/game-server/metrics
     * Historial de métricas de recursos (CPU, RAM, disco, red) de las últimas N horas.
     * Query param: hours=24 (default), max=48
     */
    public function metricsHistory(Request $request, string $uuid): JsonResponse
    {
        $service = Service::where('user_id', Auth::id())
            ->where('uuid', $uuid)
            ->with('plan')
            ->firstOrFail();

        $hours = min(48, max(1, (int) $request->query('hours', 24)));

        $samples = ServiceMetric::where('service_id', $service->id)
            ->where('sampled_at', '>=', now()->subHours($hours))
            ->orderBy('sampled_at')
            ->get(['cpu_percent', 'memory_bytes', 'memory_limit_bytes', 'disk_bytes',
                   'disk_limit_bytes', 'network_rx_bytes', 'network_tx_bytes', 'state', 'sampled_at']);

        // Límites del plan como fallback si las métricas son 0
        $memLimit  = ($service->plan?->pterodactyl_limits['memory'] ?? 0) * 1024 * 1024;
        $diskLimit = ($service->plan?->pterodactyl_limits['disk']   ?? 0) * 1024 * 1024;

        // También incluir los pings de las mismas horas para overlay de estado online
        $pings = GameServerPing::where('service_id', $service->id)
            ->where('sampled_at', '>=', now()->subHours($hours))
            ->orderBy('sampled_at')
            ->get(['ping_ms', 'is_online', 'players', 'sampled_at']);

        return response()->json([
            'success' => true,
            'data'    => [
                'samples' => $samples->map(function ($m) use ($memLimit, $diskLimit) {
                    $mLim = $m->memory_limit_bytes ?: $memLimit;
                    $dLim = $m->disk_limit_bytes   ?: $diskLimit;
                    return [
                        'cpu'        => round($m->cpu_percent, 1),
                        'memory_pct' => $mLim > 0 ? round(($m->memory_bytes / $mLim) * 100, 1) : 0,
                        'disk_pct'   => $dLim > 0 ? round(($m->disk_bytes   / $dLim) * 100, 1) : 0,
                        'net_rx_mb'  => round($m->network_rx_bytes / 1024 / 1024, 2),
                        'net_tx_mb'  => round($m->network_tx_bytes / 1024 / 1024, 2),
                        'state'      => $m->state,
                        'ts'         => $m->sampled_at->toISOString(),
                    ];
                })->values(),
                'pings' => $pings->map(fn ($p) => [
                    'ping_ms'   => $p->ping_ms,
                    'is_online' => $p->is_online,
                    'players'   => $p->players,
                    'ts'        => $p->sampled_at->toISOString(),
                ])->values(),
                'plan_limits' => [
                    'memory_mb' => (int) round($memLimit  / 1024 / 1024),
                    'disk_mb'   => (int) round($diskLimit / 1024 / 1024),
                ],
                'hours' => $hours,
            ],
        ]);
    }

    /**
     * GET /services/{uuid}/game-server/pings
     * Historial de pings de las últimas 24 h (max 288 muestras a 5 min de intervalo).
     */
    public function getPingHistory(string $uuid): JsonResponse
    {
        $service = Service::where('user_id', Auth::id())
            ->where('uuid', $uuid)
            ->firstOrFail();

        $samples = GameServerPing::where('service_id', $service->id)
            ->where('sampled_at', '>=', now()->subHours(24))
            ->orderBy('sampled_at')
            ->get(['ping_ms', 'is_online', 'players', 'sampled_at']);

        $onlineSamples  = $samples->where('is_online', true);
        $avgPing        = $onlineSamples->avg('ping_ms');
        $minPing        = $onlineSamples->min('ping_ms');
        $maxPing        = $onlineSamples->max('ping_ms');
        $totalSamples   = $samples->count();
        $lostSamples    = $samples->where('is_online', false)->count();
        $lossPercent    = $totalSamples > 0
            ? round(($lostSamples / $totalSamples) * 100, 1)
            : 0.0;

        return response()->json([
            'success' => true,
            'data'    => [
                'samples'      => $samples->map(fn ($p) => [
                    'ping_ms'    => $p->ping_ms,
                    'is_online'  => $p->is_online,
                    'players'    => $p->players,
                    'sampled_at' => $p->sampled_at?->toISOString(),
                ])->values(),
                'avg_ping_ms'  => $avgPing  !== null ? (int) round($avgPing)  : null,
                'min_ping_ms'  => $minPing  !== null ? (int) $minPing  : null,
                'max_ping_ms'  => $maxPing  !== null ? (int) $maxPing  : null,
                'loss_percent' => $lossPercent,
            ],
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
                ->when(! config('pterodactyl.verify_ssl', true), fn ($h) => $h->withoutVerifying())
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
            $wsData['socket'] = $this->rewriteWingsUrl($wsData['socket']);

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
                ->when(! config('pterodactyl.verify_ssl', true), fn ($h) => $h->withoutVerifying())
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
     *
     * Devuelve los eggs de Pterodactyl enriquecidos con datos de nuestro modelo:
     * protocol, protocol_label, category, category_label, icon_url.
     */
    public function listEggs(int $nest_id): JsonResponse
    {
        $eggs = $this->pterodactyl->listNestEggs($nest_id);

        // Índice de nuestro modelo por ptero_egg_id para O(1) lookup
        $localEggs = PterodactylEgg::where('ptero_nest_id', $nest_id)
            ->get()
            ->keyBy('ptero_egg_id');

        $cleaned = collect($eggs ?? [])->map(function ($egg) use ($localEggs) {
            $eggId    = $egg['id']   ?? null;
            $eggName  = $egg['name'] ?? '';

            $variables = $egg['relationships']['variables']['data'] ?? [];

            $versionVariable = collect($variables)->first(
                fn ($var) => str_contains($var['attributes']['env_variable'] ?? '', 'VERSION')
            );

            // Buscar en nuestro catálogo sincronizado
            /** @var PterodactylEgg|null $local */
            $local = $localEggs->get($eggId);

            if ($local) {
                $protocol       = $local->protocol()->value;
                $protocolLabel  = $local->protocol()->label();
                $category       = $local->getCategory();
                $categoryLabel  = $local->getCategoryLabel();
                $iconUrl        = $local->icon_url;
            } else {
                // Fallback: derivar categoría del nombre del egg
                $temp          = new PterodactylEgg(['egg_name' => $eggName, 'nest_name' => '']);
                $protocol      = 'java';
                $protocolLabel = 'Java';
                $category      = $temp->getCategory();
                $categoryLabel = $temp->getCategoryLabel();
                $iconUrl       = null;
            }

            return [
                'id'               => $eggId,
                'uuid'             => $egg['uuid'] ?? null,
                'name'             => $eggName,
                'description'      => $egg['description'] ?? null,
                'version_variable' => $versionVariable['attributes']['env_variable'] ?? null,
                'version'          => $versionVariable['attributes']['default_value'] ?? null,
                // Campos enriquecidos
                'icon_url'         => $iconUrl,
                'protocol'         => $protocol,
                'protocol_label'   => $protocolLabel,
                'category'         => $category,
                'category_label'   => $categoryLabel,
            ];
        })->values();

        return response()->json(['success' => true, 'data' => $cleaned]);
    }

    /**
     * GET /services/{uuid}/game-server/ping-now
     *
     * Realiza un ping SLP al servidor Minecraft en el momento y devuelve el resultado
     * inmediatamente. También emite GameServerPingBroadcast en el canal Reverb para que
     * otros tabs/clientes del mismo usuario reciban el valor actualizado.
     *
     * Úsalo solo al montar el componente o cuando el servidor cambia a "running" — no
     * con un intervalo fijo (eso sería polling y encarece la infraestructura).
     */
    public function pingNow(string $uuid): JsonResponse
    {
        $user    = Auth::user();
        $service = Service::where('user_id', $user->id)->where('uuid', $uuid)->firstOrFail();

        if (!$service->isPterodactylManaged()) {
            return response()->json(['success' => false, 'message' => 'Este servicio no es un servidor de juego.'], 422);
        }

        $host = $service->connection_details['host']       ?? $service->connection_details['server_ip']   ?? null;
        $port = (int) ($service->connection_details['port'] ?? $service->connection_details['server_port'] ?? 25565);

        if (!$host) {
            return response()->json([
                'success'    => true,
                'ping_ms'    => null,
                'is_online'  => false,
                'players'    => null,
                'sampled_at' => now()->toISOString(),
            ]);
        }

        try {
            $result   = $this->minecraftPing->ping($host, $port, timeoutMs: 3000);
            $isOnline = $result !== null;
            $pingMs   = $isOnline ? ($result['ping_ms'] ?? null) : null;
            $players  = $isOnline ? ($result['players']['online'] ?? null) : null;
            $sample   = $isOnline ? ($result['players']['sample'] ?? []) : [];
        } catch (\Throwable $e) {
            Log::warning('pingNow: error al pingear servidor', [
                'service_id' => $service->id,
                'error'      => $e->getMessage(),
            ]);
            $isOnline = false;
            $pingMs   = null;
            $players  = null;
            $sample   = [];
        }

        // Broadcast en tiempo real para que el canal Reverb entregue el valor
        try {
            GameServerPingBroadcast::dispatch($service->uuid, $pingMs, $isOnline, $players, is_array($sample) ? $sample : []);
        } catch (\Throwable) {
            // No fatal si Reverb no está disponible
        }

        return response()->json([
            'success'    => true,
            'ping_ms'    => $pingMs,
            'is_online'  => $isOnline,
            'players'    => $players,
            'player_sample' => is_array($sample) ? $sample : [],
            'sampled_at' => now()->toISOString(),
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
        return $this->findOwnedGameServerService($request, $uuid);
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
