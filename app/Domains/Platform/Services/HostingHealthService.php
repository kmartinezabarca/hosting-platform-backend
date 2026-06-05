<?php

namespace App\Domains\Platform\Services;

use App\Domains\Platform\Models\Service;
use App\Domains\Platform\Models\ServiceHealthCheck;
use Illuminate\Support\Facades\Http;

/**
 * Mide disponibilidad (uptime) y latencia REAL de un sitio de hosting mediante
 * un GET HTTP a su dominio. Persiste cada muestra en service_health_checks para
 * construir un sparkline de latencia y un % de uptime reales.
 */
class HostingHealthService
{
    /** Resuelve la URL pública del sitio a partir del servicio. */
    public function urlFor(Service $service): ?string
    {
        $conn = (array) ($service->connection_details ?? []);
        $fqdn = $conn['fqdn'] ?? $conn['domain'] ?? $service->domain ?? null;

        if (! $fqdn) {
            return null;
        }

        return str_starts_with((string) $fqdn, 'http') ? (string) $fqdn : 'https://' . $fqdn;
    }

    /**
     * Ejecuta un health check y persiste el resultado. Devuelve null si el
     * servicio no tiene dominio que medir.
     */
    public function check(Service $service): ?ServiceHealthCheck
    {
        $url = $this->urlFor($service);
        if (! $url) {
            return null;
        }

        $start = microtime(true);

        try {
            $response = Http::timeout(10)->connectTimeout(5)->get($url);
            $latency  = (int) round((microtime(true) - $start) * 1000);

            // 2xx y 3xx (redirecciones) cuentan como "arriba".
            $ok = $response->status() >= 200 && $response->status() < 400;

            return ServiceHealthCheck::create([
                'service_id'  => $service->id,
                'ok'          => $ok,
                'http_status' => $response->status(),
                'latency_ms'  => $latency,
                'error'       => $ok ? null : 'HTTP ' . $response->status(),
                'checked_at'  => now(),
            ]);
        } catch (\Throwable $e) {
            $latency = (int) round((microtime(true) - $start) * 1000);

            return ServiceHealthCheck::create([
                'service_id'  => $service->id,
                'ok'          => false,
                'http_status' => null,
                'latency_ms'  => $latency,
                'error'       => mb_substr($e->getMessage(), 0, 250),
                'checked_at'  => now(),
            ]);
        }
    }

    /**
     * Resumen de salud de las últimas 24 h para alimentar el dashboard.
     *
     * @return array{uptime_pct: ?float, latency_ms: ?int, last_ok: ?bool, latency_history: int[]}
     */
    public function summary(Service $service): array
    {
        $rows = ServiceHealthCheck::where('service_id', $service->id)
            ->where('checked_at', '>=', now()->subDay())
            ->orderBy('checked_at')
            ->get(['ok', 'latency_ms', 'checked_at']);

        if ($rows->isEmpty()) {
            return ['uptime_pct' => null, 'latency_ms' => null, 'last_ok' => null, 'latency_history' => []];
        }

        $total  = $rows->count();
        $oked   = $rows->where('ok', true)->count();
        $latest = $rows->last();

        return [
            'uptime_pct'      => round($oked / $total * 100, 1),
            'latency_ms'      => $latest->ok ? (int) $latest->latency_ms : null,
            'last_ok'         => (bool) $latest->ok,
            'latency_history' => $rows->slice(-12)->pluck('latency_ms')->map(fn ($v) => (int) $v)->values()->all(),
        ];
    }

    /** Elimina muestras anteriores a $days días (retención). */
    public function prune(int $days = 7): int
    {
        return ServiceHealthCheck::where('checked_at', '<', now()->subDays($days))->delete();
    }
}
