<?php

namespace App\Domains\Platform\Services;

use App\Domains\Platform\Models\Service;
use App\Domains\Platform\Services\Coolify\CoolifyService;
use App\Domains\Platform\Services\GameServers\Contracts\GameServerDriver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sincroniza el "live_status" de cada servicio contra el proveedor real:
 *   - game-servers / minecraft → Pterodactyl (current_state)
 *   - hosting / web-hosting    → Coolify (status del application)
 *
 * El status de runtime es independiente del status administrativo (billing).
 * Un servicio puede estar `active` (pagado) pero su contenedor `stopped` o
 * `error` — ahora el cliente verá eso reflejado en el dashboard.
 */
class ServiceStatusSyncService
{
    /** Mapa de estados crudos del proveedor → estado canónico que muestra la UI. */
    private const CANON = [
        // Pterodactyl
        'running'    => 'running',
        'starting'   => 'starting',
        'stopping'   => 'stopping',
        'offline'    => 'stopped',
        'stopped'    => 'stopped',
        // Coolify
        'exited'     => 'stopped',
        'deploying'  => 'deploying',
        'building'   => 'building',
        'restarting' => 'restarting',
        'error'      => 'error',
        'unhealthy'  => 'error',
        'degraded'   => 'degraded',
    ];

    public function __construct(
        private readonly GameServerDriver $ptero,
        private readonly CoolifyService $coolify,
        private readonly HostingHealthService $health,
    ) {}

    /**
     * Sincroniza un solo servicio. No lanza excepciones: cualquier error queda
     * guardado en live_status='error' para que la UI sepa que no se pudo medir.
     */
    public function syncOne(Service $service): void
    {
        $slug = $service->plan?->category?->slug;
        try {
            if ($this->isGameServer($slug)) {
                [$status, $metrics] = $this->fetchFromPterodactyl($service);
            } elseif ($this->isHosting($slug)) {
                [$status, $metrics] = $this->fetchFromCoolify($service);
            } else {
                // Categorías sin proveedor (dominios, etc.): no hay status runtime.
                return;
            }

            $service->forceFill([
                'live_status'    => $this->canonical($status),
                'live_metrics'   => $metrics,
                'live_synced_at' => Carbon::now(),
            ])->save();
        } catch (Throwable $e) {
            Log::warning('ServiceStatusSync: '.$service->uuid.' → '.$e->getMessage());
            $service->forceFill([
                'live_status'    => 'error',
                'live_metrics'   => $this->syncErrorMetrics($service, $slug, $e),
                'live_synced_at' => Carbon::now(),
            ])->save();
        }
    }

    /** Sincroniza todos los servicios `active`. Devuelve [synced, failed]. */
    public function syncAll(): array
    {
        $synced = 0; $failed = 0;
        Service::query()
            ->whereIn('status', ['active', 'maintenance'])
            ->with('plan.category')
            ->chunk(50, function ($chunk) use (&$synced, &$failed) {
                foreach ($chunk as $service) {
                    try {
                        $this->syncOne($service);
                        $synced++;
                    } catch (Throwable $e) {
                        $failed++;
                    }
                }
            });
        return [$synced, $failed];
    }

    /* ---------------- Helpers ---------------- */

    private function isGameServer(?string $slug): bool
    {
        return in_array($slug, ['game-servers', 'minecraft', 'gameserver'], true);
    }

    private function isHosting(?string $slug): bool
    {
        return in_array($slug, ['web-hosting', 'hosting', 'webhosting'], true);
    }

    private function canonical(?string $raw): string
    {
        $r = strtolower((string) $raw);
        return self::CANON[$r] ?? ($r !== '' ? $r : 'unknown');
    }

    /**
     * @return array{0:?string,1:array}
     */
    private function fetchFromPterodactyl(Service $service): array
    {
        $conn = (array) ($service->connection_details ?? []);
        $identifier = $conn['ptero_identifier'] ?? $conn['identifier'] ?? null;
        if (!$identifier) return [null, []];

        $resources = $this->ptero->getServerResources($identifier);
        $attrs = $resources['attributes'] ?? $resources;

        $state    = $attrs['current_state'] ?? null;
        $rs       = $attrs['resources'] ?? [];
        $memBytes       = (int) ($rs['memory_bytes'] ?? 0);
        $planLimits     = (array) ($service->plan?->pterodactyl_limits ?? []);
        $planSpecs      = (array) ($service->plan?->specifications ?? []);
        $planMemoryMb   = $planLimits['memory'] ?? null;
        $specMemoryByte = $planSpecs['ram_bytes'] ?? null;
        $memLimit       = (int) (
            $attrs['memory_limit_bytes']
            ?? ($planMemoryMb ? $planMemoryMb * 1024 * 1024 : null)
            ?? $specMemoryByte
            ?? 0
        );
        $cpuAbs   = (float) ($rs['cpu_absolute'] ?? 0);

        $metrics = [
            'cpu'        => $cpuAbs,
            'ram'        => $memLimit > 0 ? ($memBytes / $memLimit) * 100 : null,
            'ram_human'  => $memLimit > 0
                ? sprintf('%s / %s', $this->formatBytes($memBytes), $this->formatBytes($memLimit))
                : null,
            'players'    => $attrs['players_online'] ?? null,
            'uptime_pct' => null,
            'state'      => $state,
            'memory_bytes'       => $memBytes,
            'memory_limit_bytes' => $memLimit,
            'disk_bytes'         => (int) ($rs['disk_bytes'] ?? 0),
            'network_rx_bytes'   => (int) ($rs['network_rx_bytes'] ?? 0),
            'network_tx_bytes'   => (int) ($rs['network_tx_bytes'] ?? 0),
            'uptime_ms'          => (int) ($rs['uptime'] ?? 0),
            'sampled_at'         => Carbon::now()->toISOString(),
        ];

        return [$state, $metrics];
    }

    /**
     * @return array{0:?string,1:array}
     */
    private function fetchFromCoolify(Service $service): array
    {
        $conn = (array) ($service->connection_details ?? []);
        $appUuid = $conn['coolify_app_uuid'] ?? null;
        if (!$appUuid) return [null, []];

        $app = $this->coolify->getApplication($appUuid);
        $state = $app['status'] ?? null;

        // Métricas REALES de hosting: Coolify no da CPU/RAM, así que usamos el
        // historial de health checks (uptime + latencia medidos con GET HTTP).
        $summary = $this->health->summary($service);

        $metrics = [
            'cpu'             => null,
            'ram'             => null,
            'uptime_pct'      => $summary['uptime_pct'],
            'latency_ms'      => $summary['latency_ms'],
            'latency_history' => $summary['latency_history'],
            'last_ok'         => $summary['last_ok'],
            'sampled_at'      => Carbon::now()->toISOString(),
        ];

        // Detección real de caída: si Coolify reporta "running" pero el sitio no
        // responde, el estado efectivo es degradado (el cliente ve la verdad).
        if ($summary['last_ok'] === false && in_array(strtolower((string) $state), ['running', 'healthy', ''], true)) {
            $state = 'degraded';
        }

        return [$state, $metrics];
    }

    private function syncErrorMetrics(Service $service, ?string $slug, Throwable $e): array
    {
        $existing = is_array($service->live_metrics) ? $service->live_metrics : [];
        $provider = $this->isGameServer($slug)
            ? 'pterodactyl'
            : ($this->isHosting($slug) ? 'coolify' : 'provider');

        return array_merge($existing, [
            'state'         => 'error',
            'error_code'    => strtoupper($provider) . '_SYNC_ERROR',
            'error_ref'     => 'SVC-' . $service->id . '-SYNC-' . Carbon::now()->format('YmdHis'),
            'error_message' => $e->getMessage(),
            'error_at'      => Carbon::now()->toISOString(),
        ]);
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 1).' GB';
        if ($bytes >= 1048576)    return number_format($bytes / 1048576, 0).' MB';
        if ($bytes >= 1024)       return number_format($bytes / 1024, 0).' KB';
        return $bytes.' B';
    }
}
