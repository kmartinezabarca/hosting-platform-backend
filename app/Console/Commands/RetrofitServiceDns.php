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
 *   php artisan game-servers:retrofit-dns --id=10 --force  # recrear DNS existente
 */
class RetrofitServiceDns extends Command
{
    protected $signature = 'game-servers:retrofit-dns
                            {--dry-run  : Mostrar qué se haría sin aplicar cambios}
                            {--id=      : Reparar solo el servicio con ese ID}
                            {--force   : Recrear DNS aunque el servicio ya tenga hostname}';

    protected $description = 'Crea o recrea registros DNS de Cloudflare para servicios de juego';

    /** Subdominos ya asignados en esta ejecución (para evitar duplicados en el mismo batch) */
    private array $assignedThisRun = [];

    public function handle(CloudflareService $cloudflare): int
    {
        $dryRun = $this->option('dry-run');
        $idOnly = $this->option('id');
        $force = (bool) $this->option('force');

        $query = Service::whereNotNull('pterodactyl_server_id')
            ->whereIn('status', ['active', 'pending']);

        if ($idOnly) {
            $query->where('id', $idOnly);
        }

        $services = $query->get()->filter(function (Service $s) use ($force) {
            if ($force) {
                return true;
            }

            $cd = $s->connection_details ?? [];
            // Necesita DNS si no tiene hostname.
            return ! ($cd['hostname'] ?? null);
        });

        if ($services->isEmpty()) {
            $this->info('Todos los servicios ya tienen hostname. Usa --force para recrear DNS existente.');
            return self::SUCCESS;
        }

        $this->info($force
            ? "Servicios a recrear DNS: {$services->count()}"
            : "Servicios sin hostname: {$services->count()}"
        );

        foreach ($services as $service) {
            $this->processService($service, $cloudflare, $dryRun, $force);
        }

        return self::SUCCESS;
    }

    private function processService(Service $service, CloudflareService $cloudflare, bool $dryRun, bool $force): void
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
        $zoneName     = trim((string) config('services.cloudflare.zone_name', 'rokeindustries.com'), '.');
        $hostname     = "{$subdomain}.{$zoneName}";

        $this->line("  [#{$service->id}] {$service->name} | {$ip}:{$port} | subdomain={$subdomain} | java=" . ($isJava ? 'yes' : 'no'));

        if ($dryRun) {
            $type = $isJava ? 'SRV' : 'A';
            $action = $force ? 'Recrearía' : 'Crearía';
            $this->line("     [dry-run] {$action} registro {$type} para {$hostname}");
            return;
        }

        try {
            if ($force) {
                $this->deleteExistingDns($cloudflare, $dnsRecordIds, $hostname, $isJava);
                $dnsRecordIds = [];
            }

            if ($isJava) {
                $dnsRecordIds['cname'] = $cloudflare->createCnameRecord($subdomain, 'mc.rokeindustries.com');
                $dnsRecordIds['srv']   = $cloudflare->createMinecraftSrv($subdomain, $port);
                $display               = $hostname;
            } else {
                $dnsRecordIds['a'] = $cloudflare->createARecord($subdomain, config('pterodactyl.relay_ip', $ip));
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
            ->map(function ($s) {
                $cd = $s->connection_details;
                return $this->hostnameLabel($cd['hostname'] ?? null);
            })
            ->filter()
            ->values()
            ->toArray();

        // Todos los subdominos tomados (BD + asignados en este batch)
        $taken = array_merge($dbHostnames, $this->assignedThisRun);

        // Función auxiliar: ¿está tomado este candidato?
        $isTaken = function (string $candidate) use ($taken): bool {
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

    private function deleteExistingDns(CloudflareService $cloudflare, array $dnsRecordIds, string $hostname, bool $isJava): void
    {
        foreach (array_filter($dnsRecordIds) as $recordId) {
            $cloudflare->deleteRecord((string) $recordId);
        }

        foreach ($this->recordNamesFor($hostname, $isJava) as $recordName) {
            foreach ($cloudflare->listRecords($recordName) as $record) {
                if (! empty($record['id'])) {
                    $cloudflare->deleteRecord((string) $record['id']);
                }
            }
        }
    }

    private function recordNamesFor(string $hostname, bool $isJava): array
    {
        if ($isJava) {
            return [$hostname, "_minecraft._tcp.{$hostname}"];
        }

        return [$hostname];
    }

    private function hostnameLabel(?string $hostname): ?string
    {
        if (! is_string($hostname) || trim($hostname) === '') {
            return null;
        }

        return explode('.', strtolower(trim($hostname)))[0] ?: null;
    }
}
