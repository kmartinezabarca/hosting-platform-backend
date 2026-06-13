<?php

namespace App\Domains\Platform\Services;

use App\Domains\Platform\Jobs\RunProvisioningJob;
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
     * Encola (idempotente) el aprovisionamiento del servicio. Pensado para
     * llamarse justo tras contratar: NO bloquea el request HTTP del checkout —
     * un worker ejecuta el trabajo en segundo plano vía {@see RunProvisioningJob}.
     * En tests (QUEUE_CONNECTION=sync) corre inline, preservando el flujo síncrono.
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
            // available_at en el futuro: el worker ejecuta el job de inmediato; el
            // cron `provisioning:process-pending` solo entra como respaldo si el
            // worker no corrió (evita doble ejecución worker + cron en operación normal).
            ['status' => ProvisioningJob::STATUS_PENDING, 'available_at' => now()->addMinutes(2)],
        );

        // Si ya quedó aprovisionado, no hacer nada.
        if ($job->status === ProvisioningJob::STATUS_SUCCEEDED) {
            return;
        }

        // La UI muestra "Aprovisionando" mientras el worker trabaja.
        if ($service->provisioning_status !== 'provisioning') {
            $service->forceFill(['provisioning_status' => 'pending'])->save();
        }

        RunProvisioningJob::dispatch($job->id)
            ->onQueue(config('compute.queues.provisioning', 'provisioning'));
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

            $repairs = ['provisioning_status' => 'succeeded', 'provisioning_error' => null];

            // Reparar el estado del servicio: si un paso tardío (p. ej. FRP) falló
            // DESPUÉS de crear el servidor, provision() lo dejó en 'failed' aunque
            // el proveedor sí tiene el recurso. Sin esto, el reintento marcaba el
            // job como succeeded pero el servicio quedaba 'failed' para siempre.
            if ($service->status === 'failed') {
                $repairs['status'] = 'active';
            }

            $service->forceFill($repairs)->save();
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

            // Espejo al plano de cómputo (API v2 / agente de IA). No-fatal:
            // platform:compute:mirror-game-servers reconcilia si esto falla.
            try {
                \App\Domains\Platform\Compute\Jobs\MirrorServiceToCompute::dispatch($service->id);
            } catch (\Throwable $e) {
                Log::warning('No se pudo encolar el espejo a cómputo', ['service_id' => $service->id, 'error' => $e->getMessage()]);
            }

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

            // Avisar al panel de administración. Si se agotaron los reintentos el
            // servicio quedó SIN aprovisionar (pagado pero no entregado): es crítico.
            \App\Domains\Platform\Support\AdminNotifier::notify(
                $exhausted ? 'Aprovisionamiento fallido (sin reintentos)' : 'Aprovisionamiento con error (reintentando)',
                $exhausted
                    ? "El servicio '{$service->name}' de {$service->user?->full_name} NO se aprovisionó tras {$job->attempts} intentos. Requiere intervención manual. Error: {$e->getMessage()}"
                    : "El servicio '{$service->name}' de {$service->user?->full_name} falló al aprovisionar (intento {$job->attempts}). Se reintentará automáticamente. Error: {$e->getMessage()}",
                $exhausted ? 'admin_provisioning_failed' : 'admin_provisioning_retry',
                [
                    'service_id' => $service->uuid ?? $service->id,
                    'provider'   => $job->provider,
                    'attempt'    => $job->attempts,
                    'exhausted'  => $exhausted,
                ],
                // Solo enviamos correo cuando se agotaron los reintentos (crítico).
                $exhausted ? ['email' => true, 'action_url' => '/admin/services', 'action_text' => 'Revisar servicio', 'subtitle' => 'Acción requerida'] : [],
            );

            // Si se agotaron los reintentos, el cliente pagó pero no recibió el
            // servicio: avisarle para que no quede a ciegas (soporte ya lo revisa).
            if ($exhausted && $service->user) {
                $service->user->notify(new \App\Domains\Platform\Notifications\ServiceNotification([
                    'title'    => 'Tu servicio necesita atención',
                    'message'  => "Hubo un problema al activar '{$service->name}'. Nuestro equipo ya fue notificado y lo resolverá a la brevedad; no necesitas hacer nada.",
                    'type'     => 'service.provision_failed',
                    'data'     => ['service_id' => $service->uuid ?? $service->id],
                    'target'   => 'client',
                    '_channel' => 'user.' . $service->user->uuid,
                ]));
            }

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
            // Servidor creado Y proxy FRP activo: un fallo tardío de FRP no
            // cuenta como aprovisionado — el reintento entra a provision(),
            // que es resumible y solo repite el paso FRP.
            ProvisioningJob::PROVIDER_PTERODACTYL => ! empty($service->pterodactyl_server_id)
                && ! empty(($service->connection_details ?? [])['frp_enabled']),
            // El marcador es la BANDERA de finalización, no el app uuid: el
            // aprovisionamiento Coolify es resumible y un app creado con DB/DNS
            // pendientes debe volver a entrar a provision() para completarse.
            // Fallback legado: filas aprovisionadas antes de la bandera tienen
            // app uuid + status active y se consideran completas.
            ProvisioningJob::PROVIDER_COOLIFY => ! empty(($service->connection_details ?? [])['coolify_provisioned_at'])
                || (! empty(($service->connection_details ?? [])['coolify_app_uuid']) && $service->status === 'active'),
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
