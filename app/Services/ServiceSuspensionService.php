<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Service;
use App\Services\Coolify\HostingProvisioningService;
use App\Services\Pterodactyl\PterodactylService;
use Illuminate\Support\Facades\Log;

/**
 * Suspende y reactiva servicios a nivel de proveedor (Pterodactyl / Coolify)
 * y de base de datos, de forma idempotente y best-effort.
 *
 * La llamada al proveedor nunca es fatal: si falla, el estado en BD igual se
 * actualiza (un servicio marcado "suspended" no debe seguir facturándose aunque
 * el panel externo no haya respondido). El reverso lo corrige un re-intento o el
 * comando subscriptions:process-overdue.
 */
class ServiceSuspensionService
{
    public function __construct(
        private readonly PterodactylService $pterodactyl,
        private readonly HostingProvisioningService $hosting,
    ) {}

    /**
     * Suspende el servicio en el proveedor y marca el estado en BD.
     */
    public function suspend(Service $service, string $reason = 'payment_overdue'): void
    {
        if ($service->status === 'suspended') {
            return; // idempotente
        }

        $this->suspendOnProvider($service);

        $service->update([
            'status'            => 'suspended',
            'suspended_at'      => now(),
            'suspension_reason' => $reason,
        ]);

        ActivityLog::record(
            'Servicio suspendido',
            "El servicio {$service->name} fue suspendido ({$reason}).",
            'service',
            ['service_id' => $service->id, 'reason' => $reason],
            $service->user_id
        );
    }

    /**
     * Reactiva el servicio en el proveedor y marca el estado en BD.
     */
    public function reactivate(Service $service): void
    {
        $this->reactivateOnProvider($service);

        $service->update([
            'status'               => 'active',
            'suspended_at'         => null,
            'suspension_reason'    => null,
            'grace_period_ends_at' => null,
        ]);

        ActivityLog::record(
            'Servicio reactivado',
            "El servicio {$service->name} fue reactivado tras regularizar el pago.",
            'service',
            ['service_id' => $service->id],
            $service->user_id
        );
    }

    private function suspendOnProvider(Service $service): void
    {
        try {
            if ($service->pterodactyl_server_id) {
                $this->pterodactyl->suspendServer((int) $service->pterodactyl_server_id);
            } elseif ($service->plan?->isCoolifyManaged()) {
                $this->hosting->suspend($service);
            }
        } catch (\Throwable $e) {
            Log::error('Suspensión en proveedor falló (no fatal)', [
                'service_id' => $service->id,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    private function reactivateOnProvider(Service $service): void
    {
        try {
            if ($service->pterodactyl_server_id) {
                $this->pterodactyl->unsuspendServer((int) $service->pterodactyl_server_id);
            } elseif ($service->plan?->isCoolifyManaged()) {
                $this->hosting->unsuspend($service);
            }
        } catch (\Throwable $e) {
            Log::error('Reactivación en proveedor falló (no fatal)', [
                'service_id' => $service->id,
                'error'      => $e->getMessage(),
            ]);
        }
    }
}
