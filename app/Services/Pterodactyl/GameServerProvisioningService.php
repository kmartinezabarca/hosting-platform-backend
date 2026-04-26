<?php

namespace App\Services\Pterodactyl;

use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use RuntimeException;

class GameServerProvisioningService
{
    public function __construct(private readonly PterodactylService $pterodactyl) {}

    // ─────────────────────────────────────────────────────────────────────────
    // Aprovisionamiento
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Crea el servidor de juego en Pterodactyl y activa el servicio.
     * Si falla, el servicio queda en 'failed' y se notifica al admin.
     */
    public function provision(Service $service): void
    {
        $plan = $service->plan;
        $user = $service->user;

        if ($plan->provisioner !== 'pterodactyl') {
            return; // nada que hacer
        }

        try {
            // 1) Crear/localizar cuenta del cliente en Pterodactyl
            $pterodactylUser = $this->pterodactyl->findOrCreateUser($user);
            $pteroUserId     = $pterodactylUser['attributes']['id'];

            // 2) Seleccionar nodo
            $nodeId = $plan->pterodactyl_node_id
                ?? $this->pterodactyl->autoSelectNode();

            // 3) Obtener una allocation libre
            $allocation = $this->pterodactyl->getAvailableAllocation($nodeId);
            $allocationId = $allocation['attributes']['id'];

            // 4) Obtener defaults del egg para environment y startup
            $egg = $this->pterodactyl->getEggDetails(
                $plan->pterodactyl_nest_id,
                $plan->pterodactyl_egg_id
            );

            $environment = $this->buildEnvironment($egg, $plan->pterodactyl_environment ?? []);

            // 5) Construir payload del servidor
            $defaultLimits   = config('pterodactyl.defaults.limits');
            $defaultFeatures = config('pterodactyl.defaults.feature_limits');

            $payload = [
                'name'         => $service->name,
                'user'         => $pteroUserId,
                'egg'          => $plan->pterodactyl_egg_id,
                'docker_image' => $plan->pterodactyl_docker_image
                                  ?? $egg['attributes']['docker_image'],
                'startup'      => $plan->pterodactyl_startup
                                  ?? $egg['attributes']['startup'],
                'environment'  => $environment,
                'limits'       => $plan->pterodactyl_limits ?? $defaultLimits,
                'feature_limits' => $plan->pterodactyl_feature_limits ?? $defaultFeatures,
                'allocation'   => ['default' => $allocationId],
                // external_id nos permite encontrar este servidor desde Pterodactyl
                'external_id'  => (string) $service->uuid,
                'start_on_completion' => true,
            ];

            // 6) Crear el servidor
            $server = $this->pterodactyl->createServer($payload);

            $serverAttrs = $server['attributes'];

            // 7) Actualizar el servicio en nuestra BD
            $service->update([
                'pterodactyl_server_id'   => $serverAttrs['id'],
                'pterodactyl_server_uuid' => $serverAttrs['uuid'],
                'pterodactyl_user_id'     => $pteroUserId,
                'external_id'             => (string) $serverAttrs['id'],
                'status'                  => 'active',
                'connection_details'      => [
                    'server_ip'        => $allocation['attributes']['ip'],
                    'server_port'      => $allocation['attributes']['port'],
                    'panel_url'        => rtrim(config('pterodactyl.base_url'), '/')
                                         . '/server/' . $serverAttrs['identifier'],
                    'identifier'       => $serverAttrs['identifier'],
                    'pterodactyl_uuid' => $serverAttrs['uuid'],
                ],
            ]);

            // 8) Notificar al cliente
            $this->notifyProvisioned($user, $service->fresh());

            Log::info('Servidor de juego aprovisionado', [
                'service_id'            => $service->id,
                'pterodactyl_server_id' => $serverAttrs['id'],
                'node_id'               => $nodeId,
                'allocation'            => $allocation['attributes']['ip'] . ':' . $allocation['attributes']['port'],
            ]);
        } catch (\Throwable $e) {
            $service->update(['status' => 'failed']);

            Log::error('Aprovisionamiento Pterodactyl fallido', [
                'service_id' => $service->id,
                'plan_id'    => $plan->id,
                'error'      => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
            ]);

            // Notificar a todos los admins
            $this->notifyAdminsFailed($service, $e->getMessage());

            throw $e;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Operaciones de ciclo de vida
    // ─────────────────────────────────────────────────────────────────────────

    public function suspend(Service $service): void
    {
        $this->requirePterodactylServer($service);

        $this->pterodactyl->suspendServer($service->pterodactyl_server_id);
        $service->update(['status' => 'suspended']);

        Log::info('Servidor suspendido', ['service_id' => $service->id]);
    }

    public function unsuspend(Service $service): void
    {
        $this->requirePterodactylServer($service);

        $this->pterodactyl->unsuspendServer($service->pterodactyl_server_id);
        $service->update(['status' => 'active']);

        Log::info('Servidor reactivado', ['service_id' => $service->id]);
    }

    public function reinstall(Service $service): void
    {
        $this->requirePterodactylServer($service);

        $this->pterodactyl->reinstallServer($service->pterodactyl_server_id);

        Log::info('Servidor reinstalado', ['service_id' => $service->id]);
    }

    /**
     * Elimina el servidor de Pterodactyl y marca el servicio como terminado.
     */
    public function terminate(Service $service): void
    {
        if ($service->pterodactyl_server_id) {
            try {
                $this->pterodactyl->deleteServer($service->pterodactyl_server_id, force: true);
            } catch (\Throwable $e) {
                // Si ya no existe en Pterodactyl, continuamos con la terminación local
                Log::warning('Error al eliminar servidor de Pterodactyl (se continúa terminación)', [
                    'service_id' => $service->id,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        $service->update([
            'status'        => 'terminated',
            'terminated_at' => now(),
        ]);

        Log::info('Servidor terminado', ['service_id' => $service->id]);
    }

    /**
     * Sincroniza el estado del servidor con Pterodactyl y devuelve la info.
     */
    public function syncStatus(Service $service): array
    {
        $this->requirePterodactylServer($service);

        $server = $this->pterodactyl->getServer($service->pterodactyl_server_id);
        $attrs  = $server['attributes'];

        return [
            'status'       => $attrs['status'] ?? 'unknown',
            'suspended'    => $attrs['suspended'] ?? false,
            'node'         => $attrs['node'] ?? null,
            'limits'       => $attrs['limits'] ?? [],
            'feature_limits' => $attrs['feature_limits'] ?? [],
            'panel_url'    => rtrim(config('pterodactyl.base_url'), '/') . '/server/' . $attrs['identifier'],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers privados
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Construye el array de variables de entorno:
     * - Toma los valores por defecto de las variables del egg
     * - Aplica los overrides del plan encima
     */
    private function buildEnvironment(array $egg, array $planEnv): array
    {
        $env = [];

        // Defaults del egg
        foreach ($egg['attributes']['relationships']['variables']['data'] ?? [] as $var) {
            $attr = $var['attributes'];
            $env[$attr['env_variable']] = $attr['default_value'];
        }

        // Overrides del plan
        foreach ($planEnv as $key => $value) {
            $env[$key] = $value;
        }

        return $env;
    }

    private function requirePterodactylServer(Service $service): void
    {
        if (!$service->pterodactyl_server_id) {
            throw new RuntimeException("El servicio #{$service->id} no tiene servidor de Pterodactyl asociado.");
        }
    }

    private function notifyProvisioned(User $user, Service $service): void
    {
        try {
            $details = $service->connection_details ?? [];

            Notification::send($user, new \App\Notifications\ServiceNotification([
                'title'   => '¡Tu servidor está listo!',
                'message' => "Tu servidor '{$service->name}' ha sido creado y está en línea. IP: {$details['server_ip']}:{$details['server_port']}",
                'type'    => 'service.provisioned',
                'data'    => [
                    'service_id'  => $service->uuid,
                    'server_ip'   => $details['server_ip']   ?? null,
                    'server_port' => $details['server_port'] ?? null,
                    'panel_url'   => $details['panel_url']   ?? null,
                ],
            ]));
        } catch (\Throwable $e) {
            Log::warning('No se pudo notificar al usuario sobre servidor aprovisionado', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function notifyAdminsFailed(Service $service, string $error): void
    {
        try {
            $admins = \App\Models\User::whereIn('role', ['admin', 'super_admin'])->get();
            Notification::send($admins, new \App\Notifications\ServiceNotification([
                'title'   => 'Error de aprovisionamiento',
                'message' => "Falló el aprovisionamiento del servicio #{$service->id} ({$service->name}): {$error}",
                'type'    => 'service.provision_failed',
                'data'    => ['service_id' => $service->uuid, 'error' => $error],
            ]));
        } catch (\Throwable $e) {
            Log::warning('No se pudo notificar a admins sobre fallo de aprovisionamiento', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
