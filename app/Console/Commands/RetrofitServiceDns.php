<?php

namespace App\Console\Commands;

use App\Models\Service;
use App\Services\CloudflareService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Comando de reparación: crea los registros DNS de Cloudflare para servicios
 * activos que fueron aprovisionados antes de que el sistema DNS estuviera
 * operativo (o cuando falló por SSL en dev).
 *
 * Uso:
 *   php artisan game-servers:retrofit-dns          # todos los que les falte
 *   php artisan game-servers:retrofit-dns --dry-run
 *   php artisan game-servers:retrofit-dns --id=10  # solo un servicio
 */
class RetrofitServiceDns extends Command
{
    protected $signature = 'game-servers:retrofit-dns
                            {--dry-run  : Mostrar qué se haría sin aplicar cambios}
                            {--id=      : Reparar solo el servicio con ese ID}';

    protected $description = 'Crea registros DNS de Cloudflare para servicios de juego que no los tienen';

    /** Subdominos ya asignados en esta ejecución (para evitar duplicados en el mismo batch) */
    private array $assignedThisRun = [];

    public function handle(CloudflareService $cloudflare): int
    {
        $dryRun = $this->option('dry-run');
        $idOnly = $this->option('id');

        $query = Service::whereNotNull('pterodactyl_server_id')
            ->whereIn('status', ['active', 'pending']);

        if ($idOnly) {
            $query->where('id', $idOnly);
        }

        $services = $query->get()->filter(function (Service $s) {
            $cd = $s->connection_details ?? [];
            // Necesita DNS si no tiene hostname o si dns_record_ids está vacío
            return ! ($cd['hostname'] ?? null);
        });

        if ($services->isEmpty()) {
            $this->info('Todos los servicios ya tienen hostname. Nada que hacer.');
            return self::SUCCESS;
        }

        $this->info("Servicios sin hostname: {$services->count()}");

        foreach ($services as $service) {
            $this->processService($service, $cloudflare, $dryRun);
        }

        return self::SUCCESS;
    }

    private function processService(Service $service, CloudflareService $cloudflare, bool $dryRun): void
    {
        $cd   = $service->connection_details ?? [];
        $port = (int) ($cd['server_port'] ?? 0);
        $ip   = $cd['server_ip'] ?? null;

        if (! $port || ! $ip) {
            $this->warn("  [#{$service->id}] Sin IP/puerto en connection_details, saltando.");
            return;
        }

        $user   = $service->user;
        if (! $user) {
            $this->warn("  [#{$service->id}] Sin usuario asociado, saltando.");
            return;
        }

        // Determinar subdominio — respetar el que ya exista (si hostname parcial) o construir uno
        $subdomain    = $this->resolveSubdomain($service, $user);
        $isJava       = $cd['is_java'] ?? true;      // Asumir Java si no está definido
        $dnsRecordIds = $cd['dns_record_ids'] ?? [];
        $hostname     = null;

        $this->line("  [#{$service->id}] {$service->name} | {$ip}:{$port} | subdomain={$subdomain} | java=" . ($isJava ? 'yes' : 'no'));

        if ($dryRun) {
            $type = $isJava ? 'SRV' : 'A';
            $this->line("     [dry-run] Crearía registro {$type} para {$subdomain}.rokeindustries.com");
            return;
        }

        try {
            if ($isJava) {
                $dnsRecordIds['srv'] = $cloudflare->createMinecraftSrv($subdomain, $port);
                $hostname            = "{$subdomain}.rokeindustries.com";
                $display             = $hostname;
            } else {
                $dnsRecordIds['a'] = $cloudflare->createARecord("{$subdomain}-bedrock", config('pterodactyl.relay_ip', $ip));
                $hostname          = "{$subdomain}-bedrock.rokeindustries.com";
                $display           = "{$hostname}:{$port}";
            }

            // Actualizar connection_details conservando todos los campos existentes
            $service->update([
                'connection_details' => array_merge($cd, [
                    'hostname'       => $hostname,
                    'display'        => $display,
                    'is_java'        => $isJava,
                    'dns_record_ids' => $dnsRecordIds,
                ]),
            ]);

            $this->info("     ✓ DNS creado → {$hostname}");
            Log::info('RetrofitServiceDns: DNS creado', [
                'service_id' => $service->id,
                'hostname'   => $hostname,
                'dns_ids'    => $dnsRecordIds,
            ]);

        } catch (\Throwable $e) {
            $this->error("     ✗ Error: {$e->getMessage()}");
            Log::error('RetrofitServiceDns: falló', [
                'service_id' => $service->id,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    /**
     * Construye un subdominio único para este servicio.
     * Considera hostnames ya existentes en BD + los asignados en esta misma ejecución.
     */
    private function resolveSubdomain(Service $service, \App\Models\User $user): string
    {
        $base  = $user->username ?? explode('@', $user->email)[0];
        $clean = preg_replace('/[^a-z0-9-]/', '-', strtolower($base));
        $clean = trim(preg_replace('/-+/', '-', $clean), '-');
        $base  = substr($clean ?: 'server' . $user->id, 0, 25);

        // Hostnames ya persistidos en BD (otros servicios)
        $dbHostnames = Service::where('user_id', $user->id)
            ->where('id', '!=', $service->id)
            ->whereIn('status', ['active', 'pending'])
            ->whereNotNull('connection_details')
            ->get()
            ->map(function ($s) { $cd = $s->connection_details; return $cd['hostname'] ?? null; })
            ->filter()
            ->values()
            ->toArray();

        // Todos los subdominos tomados (BD + asignados en este batch)
        $taken = array_merge($dbHostnames, $this->assignedThisRun);

        // Función auxiliar: ¿está tomado este candidato?
        $isTaken = function (string $candidate) use ($taken): bool {
            foreach ($taken as $h) {
                if ($h === $candidate || str_starts_with($h, $candidate . '.')) {
                    return true;
                }
            }
            // También comparar subdominio base sin dominio
            return in_array($candidate, $taken, true);
        };

        if (! $isTaken($base)) {
            $this->assignedThisRun[] = $base;
            return $base;
        }

        for ($i = 2; $i <= 99; $i++) {
            $candidate = "{$base}-{$i}";
            if (! $isTaken($candidate)) {
                $this->assignedThisRun[] = $candidate;
                return $candidate;
            }
        }

        $fallback = substr($base, 0, 20) . '-' . $service->id;
        $this->assignedThisRun[] = $fallback;
        return $fallback;
    }
}
