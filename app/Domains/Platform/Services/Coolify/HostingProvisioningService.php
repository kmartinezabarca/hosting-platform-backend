<?php

namespace App\Domains\Platform\Services\Coolify;

use App\Domains\Platform\Models\Service;
use App\Models\User;
use App\Domains\Platform\Notifications\HostingProvisioned;
use App\Domains\Platform\Services\CloudflareService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use RuntimeException;

class HostingProvisioningService
{
    public function __construct(
        private readonly CoolifyService  $coolify,
        private readonly CloudflareService $cloudflare,
    ) {}

    public function provision(Service $service): void
    {
        $service->loadMissing(['plan', 'user']);
        $plan = $service->plan;
        $user = $service->user;

        if ($plan?->provisioner !== 'coolify') {
            return;
        }

        $domain       = $this->normalizeDomain($service->domain);
        $subdomain    = $this->buildSubdomain($user);
        $fqdn         = $domain
            ? "https://{$domain}"
            : "https://{$subdomain}.rokeindustries.com";
        $buildPack    = $plan->provisioner_config['build_pack'] ?? 'static';
        $dbEnabled    = (bool) ($plan->provisioner_config['db_enabled'] ?? false);
        $dbType       = $plan->provisioner_config['db_type'] ?? 'mariadb';
        $dnsRecordIds = [];

        // Coolify valida el nombre: solo letras (unicode), números, espacios y
        // - _ . / @ & ( ) # , : +  → cualquier otro carácter (p. ej. "—") da 422.
        // El nombre lo elige el cliente, así que hay que sanitizarlo.
        $coolifyName = $this->sanitizeCoolifyName($service->name);

        try {
            // 1) Crear proyecto en Coolify
            $project = $this->coolify->createProject(
                $coolifyName,
                "Hosting para {$user->email}"
            );
            $projectUuid = $project['uuid'];

            // 2) Crear aplicación
            $app = $this->coolify->createApplication([
                'project_uuid' => $projectUuid,
                'server_uuid'  => config('coolify.server_uuid'),
                'name'         => $coolifyName,
                'build_pack'   => $buildPack,
                'fqdn'         => $fqdn,
            ]);
            $appUuid = $app['uuid'];

            // 3) Crear base de datos si el plan lo incluye
            $db = null;
            if ($dbEnabled) {
                $db = $this->coolify->createDatabase([
                    'project_uuid' => $projectUuid,
                    'server_uuid'  => config('coolify.server_uuid'),
                    'name'         => "db_{$subdomain}",
                    'type'         => $dbType,
                ]);
            }

            // 4) DNS en Cloudflare
            $dnsTarget = $domain ?? "{$subdomain}.rokeindustries.com";
            try {
                $dnsRecordIds['a'] = $this->cloudflare->createARecord(
                    $this->cloudflareName($dnsTarget),
                    '100.124.151.68'
                );
            } catch (\Throwable $e) {
                Log::warning('DNS Cloudflare para hosting Coolify no creado (no fatal)', [
                    'service_id' => $service->id,
                    'fqdn'       => $fqdn,
                    'error'      => $e->getMessage(),
                ]);
            }

            // 5) Persistir connection_details
            $service->update([
                'external_id' => $appUuid,
                'status'      => 'active',
                'connection_details' => [
                    'coolify_project_uuid' => $projectUuid,
                    'coolify_app_uuid'     => $appUuid,
                    'coolify_db_uuid'      => $db['uuid'] ?? null,
                    'domain'               => $domain,
                    'fqdn'                 => $fqdn,
                    'subdomain'            => $subdomain,
                    'db_host'              => $db['internal_db_url'] ?? null,
                    'db_name'              => $db['_db_name'] ?? null,
                    'db_user'              => $db['_db_user'] ?? null,
                    'db_type'              => $db['_db_type'] ?? null,
                    'dns_record_ids'       => $dnsRecordIds,
                    'panel_url'            => config('coolify.base_url'),
                ],
                'connection_secrets' => array_filter([
                    'db_password' => $db['_db_password'] ?? null,
                ], fn ($value) => $value !== null && $value !== ''),
            ]);

            // 6) Notificar
            $this->notifyProvisioned($user, $service->fresh(['plan', 'user']));

            Log::info('Hosting Coolify aprovisionado', [
                'service_id'   => $service->id,
                'project_uuid' => $projectUuid,
                'app_uuid'     => $appUuid,
                'db_uuid'      => $db['uuid'] ?? null,
                'fqdn'         => $fqdn,
                'dns_records'  => $dnsRecordIds,
            ]);
        } catch (\Throwable $e) {
            $service->update(['status' => 'failed']);

            Log::error('Aprovisionamiento Coolify fallido', [
                'service_id' => $service->id,
                'plan_id'    => $plan?->id,
                'error'      => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    public function suspend(Service $service): void
    {
        $appUuid = $this->requireAppUuid($service);

        $this->coolify->stopApplication($appUuid);
        $service->update(['status' => 'suspended']);

        Log::info('Hosting Coolify suspendido', ['service_id' => $service->id]);
    }

    public function unsuspend(Service $service): void
    {
        $appUuid = $this->requireAppUuid($service);

        $this->coolify->startApplication($appUuid);
        $service->update(['status' => 'active']);

        Log::info('Hosting Coolify reactivado', ['service_id' => $service->id]);
    }

    public function terminate(Service $service): void
    {
        $conn = $service->connection_details ?? [];

        // Borrar DNS
        foreach ($conn['dns_record_ids'] ?? [] as $recordId) {
            try {
                $this->cloudflare->deleteRecord($recordId);
            } catch (\Throwable $e) {
                Log::warning('No se pudo borrar DNS de hosting Coolify', [
                    'service_id' => $service->id,
                    'record_id'  => $recordId,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        // Borrar base de datos
        if (!empty($conn['coolify_db_uuid'])) {
            try {
                $this->coolify->deleteDatabase($conn['coolify_db_uuid']);
            } catch (\Throwable $e) {
                Log::warning('No se pudo borrar DB Coolify', [
                    'service_id' => $service->id,
                    'db_uuid'    => $conn['coolify_db_uuid'],
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        // Borrar aplicación
        if (!empty($conn['coolify_app_uuid'])) {
            try {
                $this->coolify->deleteApplication($conn['coolify_app_uuid']);
            } catch (\Throwable $e) {
                Log::warning('No se pudo borrar aplicación Coolify', [
                    'service_id' => $service->id,
                    'app_uuid'   => $conn['coolify_app_uuid'],
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        // Borrar proyecto (con reintentos: tras borrar la app, Coolify puede
        // tardar un instante en liberar el proyecto y rechazar el primer borrado).
        if (!empty($conn['coolify_project_uuid'])) {
            $this->deleteProjectWithRetry($service, $conn['coolify_project_uuid']);
        }

        $service->update([
            'status'        => 'terminated',
            'terminated_at' => now(),
        ]);

        Log::info('Hosting Coolify terminado', ['service_id' => $service->id]);
    }

    /**
     * Borra un proyecto de Coolify reintentando con backoff. Coolify rechaza el
     * borrado mientras el proyecto aún tenga recursos (la app recién borrada puede
     * tardar en liberarse), así que reintentamos antes de rendirnos. Best-effort:
     * si tras todos los intentos falla, se registra pero no se lanza excepción.
     */
    private function deleteProjectWithRetry(Service $service, string $projectUuid, int $attempts = 4): void
    {
        for ($i = 1; $i <= $attempts; $i++) {
            try {
                $this->coolify->deleteProject($projectUuid);

                return;
            } catch (\Throwable $e) {
                if ($i === $attempts) {
                    Log::warning('No se pudo borrar proyecto Coolify tras reintentos', [
                        'service_id'   => $service->id,
                        'project_uuid' => $projectUuid,
                        'attempts'     => $attempts,
                        'error'        => $e->getMessage(),
                    ]);

                    return;
                }

                // Backoff incremental: 1.5s, 3s, 4.5s…
                usleep($i * 1_500_000);
            }
        }
    }

    public function syncStatus(Service $service): array
    {
        $appUuid = $this->requireAppUuid($service);
        $conn    = $service->connection_details ?? [];
        $app     = $this->coolify->getApplication($appUuid);

        $db = null;
        if (!empty($conn['coolify_db_uuid'])) {
            try {
                $db = $this->coolify->getDatabase($conn['coolify_db_uuid']);
            } catch (\Throwable) {
                // No bloquear si la DB no responde
            }
        }

        return [
            'status'      => $app['status'] ?? 'unknown',
            'app_uuid'    => $appUuid,
            'fqdn'        => $app['fqdn'] ?? $conn['fqdn'] ?? null,
            'app'         => $app,
            'database'    => $db,
            'panel_url'   => config('coolify.base_url'),
        ];
    }

    // ── Helpers privados ──────────────────────────────────────────────────────

    private function buildSubdomain(User $user): string
    {
        $base  = $user->username ?? explode('@', $user->email)[0];
        $clean = preg_replace('/[^a-z0-9-]/', '-', strtolower($base));
        $clean = trim(preg_replace('/-+/', '-', $clean), '-');
        $base  = substr($clean ?: 'hosting' . $user->id, 0, 25);

        $existing = Service::where('user_id', $user->id)
            ->whereIn('status', ['active', 'pending'])
            ->whereNotNull('connection_details')
            ->get()
            ->map(fn($s) => $s->connection_details['subdomain'] ?? null)
            ->filter()
            ->values();

        if (! $existing->contains($base)) {
            return $base;
        }

        for ($i = 2; $i <= 99; $i++) {
            $candidate = "{$base}-{$i}";
            if (! $existing->contains($candidate)) {
                return $candidate;
            }
        }

        return substr($base, 0, 20) . '-' . substr((string) $user->id, 0, 5);
    }

    private function normalizeDomain(?string $domain): ?string
    {
        $domain = strtolower(trim((string) $domain));
        $domain = preg_replace('#^https?://#', '', $domain);
        $domain = trim(explode('/', $domain)[0] ?? '', '.');

        return $domain !== '' ? $domain : null;
    }

    /**
     * Sanitiza un nombre para Coolify. Coolify solo acepta letras (unicode),
     * números, espacios y los caracteres - _ . / @ & ( ) # , : + — cualquier
     * otro (p. ej. guión largo "—", emojis, comillas tipográficas) provoca un
     * 422 al crear el proyecto/app. Reemplaza lo no permitido por un espacio.
     */
    private function sanitizeCoolifyName(string $name): string
    {
        $clean = preg_replace('/[^\p{L}\p{N} \-_.\/@&()#,:+]/u', ' ', $name);
        $clean = trim((string) preg_replace('/\s+/', ' ', (string) $clean));

        return $clean !== '' ? mb_substr($clean, 0, 100) : 'Hosting';
    }

    private function cloudflareName(string $domain): string
    {
        $zone = config('services.cloudflare.zone_name', 'rokeindustries.com');

        if (str_ends_with($domain, '.' . $zone)) {
            return substr($domain, 0, -strlen('.' . $zone));
        }

        return $domain;
    }

    private function requireAppUuid(Service $service): string
    {
        $uuid = $service->connection_details['coolify_app_uuid'] ?? $service->external_id;

        if (!$uuid) {
            throw new RuntimeException("El servicio #{$service->id} no tiene aplicación Coolify asociada.");
        }

        return $uuid;
    }

    private function notifyProvisioned(User $user, Service $service): void
    {
        try {
            Notification::send($user, new HostingProvisioned($service));
        } catch (\Throwable $e) {
            Log::warning('No se pudo notificar hosting Coolify aprovisionado', [
                'service_id' => $service->id,
                'error'      => $e->getMessage(),
            ]);
        }
    }
}
