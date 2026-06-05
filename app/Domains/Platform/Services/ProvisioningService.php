<?php

namespace App\Domains\Platform\Services;

use App\Domains\Platform\Models\ProvisioningJob;
use App\Domains\Platform\Models\Service;
use App\Domains\Platform\Services\Coolify\HostingProvisioningService;
use App\Domains\Platform\Services\Pterodactyl\GameServerProvisioningService;
use Illuminate\Support\Facades\Log;

/**
 * Orquesta el aprovisionamiento de servicios de forma idempotente y con
 * reintentos persistentes (tabla provisioning_jobs).
 *
 * Garantías:
 *  - NO se aprovisiona dos veces el mismo servicio (guarda por marcadores del
 *    proveedor: pterodactyl_server_id / connection_details.coolify_app_uuid, y
 *    unique(service_id, provider) en la tabla de jobs).
 *  - Un fallo del proveedor NO deja el servicio a medias: el job queda pendiente
 *    con backoff y lo reintenta provisioning:process-pending.
 */
class ProvisioningService
{
    /** Backoff base en minutos: espera = base * 2^(intentos-1). */
    private const BACKOFF_BASE_MINUTES = 5;

    public function __construct(
        private readonly GameServerProvisioningService $gameServers,
        private readonly HostingProvisioningService $hosting,
    ) {}

    /**
     * Encola (idempotente) y ejecuta de inmediato el aprovisionamiento del
     * servicio. Pensado para llamarse justo tras contratar.
     */
    public function dispatch(Service $service): void
    {
        $provider = $this->providerFor($service);

        if (! $provider) {
            $service->forceFill(['provisioning_status' => 'not_required'])->save();
            return;
        }

        $job = ProvisioningJob::firstOrCreate(
            ['service_id' => $service->id, 'provider' => $provider],
            ['status' => ProvisioningJob::STATUS_PENDING, 'available_at' => now()],
        );

        // Si ya quedó aprovisionado o resuelto, no hacer nada.
        if (in_array($job->status, [ProvisioningJob::STATUS_SUCCEEDED], true)) {
            return;
        }

        $this->runJob($job->fresh('service'));
    }

    /**
     * Ejecuta un job concreto. Devuelve true si el servicio quedó aprovisionado.
     */
    public function runJob(ProvisioningJob $job): bool
    {
        $service = $job->service?->fresh(['plan', 'user']);

        if (! $service) {
            $job->update(['status' => ProvisioningJob::STATUS_FAILED, 'last_error' => 'Servicio inexistente']);
            return false;
        }

        // ── Idempotencia: ¿ya está aprovisionado en el proveedor? ─────────────
        if ($this->alreadyProvisioned($service, $job->provider)) {
            $job->update([
                'status'       => ProvisioningJob::STATUS_SUCCEEDED,
                'processed_at' => now(),
                'last_error'   => null,
            ]);
            $service->forceFill(['provisioning_status' => 'succeeded', 'provisioning_error' => null])->save();
            return true;
        }

        $job->update([
            'status'   => ProvisioningJob::STATUS_RUNNING,
            'attempts' => $job->attempts + 1,
        ]);
        $service->forceFill(['provisioning_status' => 'provisioning'])->save();

        try {
            $this->provisionOnProvider($service, $job->provider);

            $job->update([
                'status'       => ProvisioningJob::STATUS_SUCCEEDED,
                'processed_at' => now(),
                'last_error'   => null,
            ]);
            $service->fresh()?->forceFill(['provisioning_status' => 'succeeded', 'provisioning_error' => null])->save();

            return true;
        } catch (\Throwable $e) {
            $exhausted = ($job->attempts + 0) >= $job->max_attempts; // attempts ya incrementado arriba
            $backoffMin = self::BACKOFF_BASE_MINUTES * (2 ** max(0, $job->attempts - 1));

            $job->update([
                'status'       => $exhausted ? ProvisioningJob::STATUS_FAILED : ProvisioningJob::STATUS_PENDING,
                'last_error'   => $e->getMessage(),
                'available_at' => $exhausted ? null : now()->addMinutes($backoffMin),
            ]);

            $service->fresh()?->forceFill([
                'provisioning_status' => $exhausted ? 'failed' : 'pending',
                'provisioning_error'  => $e->getMessage(),
            ])->save();

            Log::error('Aprovisionamiento falló', [
                'service_id' => $service->id,
                'provider'   => $job->provider,
                'attempt'    => $job->attempts,
                'exhausted'  => $exhausted,
                'error'      => $e->getMessage(),
            ]);

            return false;
        }
    }

    // ── Internos ──────────────────────────────────────────────────────────────

    private function providerFor(Service $service): ?string
    {
        if ($service->plan?->isPterodactylManaged()) {
            return ProvisioningJob::PROVIDER_PTERODACTYL;
        }
        if ($service->plan?->isCoolifyManaged()) {
            return ProvisioningJob::PROVIDER_COOLIFY;
        }
        return null;
    }

    private function alreadyProvisioned(Service $service, string $provider): bool
    {
        return match ($provider) {
            ProvisioningJob::PROVIDER_PTERODACTYL => ! empty($service->pterodactyl_server_id),
            ProvisioningJob::PROVIDER_COOLIFY     => ! empty(($service->connection_details ?? [])['coolify_app_uuid']),
            default => false,
        };
    }

    private function provisionOnProvider(Service $service, string $provider): void
    {
        match ($provider) {
            ProvisioningJob::PROVIDER_PTERODACTYL => $this->gameServers->provision($service),
            ProvisioningJob::PROVIDER_COOLIFY     => $this->hosting->provision($service),
            default => throw new \RuntimeException("Proveedor de aprovisionamiento desconocido: {$provider}"),
        };
    }
}
