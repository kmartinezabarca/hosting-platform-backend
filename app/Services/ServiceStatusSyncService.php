<?php

namespace App\Services;

use App\Models\Service;
use App\Services\Coolify\CoolifyService;
use App\Services\Pterodactyl\PterodactylService;
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
        private readonly PterodactylService $ptero,
        private readonly CoolifyService $coolify,
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
        $memBytes = (int) ($rs['memory_bytes'] ?? 0);
        $memLimit = (int) ($attrs['memory_limit_bytes'] ?? ($service->plan?->specifications['ram_bytes'] ?? 0));
        $cpuAbs   = (float) ($rs['cpu_absolute'] ?? 0);

        $metrics = [
            'cpu'        => (int) round($cpuAbs),
            'ram'        => $memLimit > 0 ? (int) round(($memBytes / $memLimit) * 100) : 0,
            'ram_human'  => $memLimit > 0
                ? sprintf('%s / %s', $this->formatBytes($memBytes), $this->formatBytes($memLimit))
                : null,
            'players'    => $attrs['players_online'] ?? null,
            'uptime_pct' => null,
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

        $metrics = [
            'cpu'        => null,
            'ram'        => null,
            'visits'     => $app['visits'] ?? null,
            'sites'      => $app['sites'] ?? null,
            'uptime_pct' => null,
        ];

        return [$state, $metrics];
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 1).' GB';
        if ($bytes >= 1048576)    return number_format($bytes / 1048576, 0).' MB';
        if ($bytes >= 1024)       return number_format($bytes / 1024, 0).' KB';
        return $bytes.' B';
    }
}
