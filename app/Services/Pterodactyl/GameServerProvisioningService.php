<?php

namespace App\Services\Pterodactyl;

use App\Models\Service;
use App\Models\User;
use App\Services\CloudflareService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use RuntimeException;

class GameServerProvisioningService
{
    public function __construct(
        private readonly PterodactylService $pterodactyl,
        private readonly CloudflareService  $cloudflare,
        private readonly \App\Services\FrpService $frp,
    ) {}

    // ─────────────────────────────────────────────────────────────────────────
    // Aprovisionamiento
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Crea el servidor de juego en Pterodactyl, registra DNS en Cloudflare
     * y activa el servicio.
     * Si falla, el servicio queda en 'failed' y se notifica al admin.
     */
    public function provision(Service $service): void
    {
        $plan = $service->plan;
        $user = $service->user;

        if ($plan->provisioner !== 'pterodactyl') {
            return; // nada que hacer
        }

        // ── Egg seleccionado por el cliente ─────────────────────────────────
        // El egg se guardó en services.selected_egg_id al contratar.
        // Si por alguna razón no existe (eg. servicio antiguo), fallamos
        // con un mensaje claro en lugar de silenciosamente.
        $selectedEgg = $service->selectedEgg;

        if (! $selectedEgg) {
            throw new \RuntimeException(
                "El servicio #{$service->id} no tiene un juego (egg) seleccionado. " .
                "Edita el servicio desde el panel de admin para asignar uno."
            );
        }

        try {
            // 1) Crear/localizar cuenta del cliente en Pterodactyl
            $pterodactylUser = $this->pterodactyl->findOrCreateUser($user);
            $pteroUserId     = $pterodactylUser['attributes']['id'];

            // 2) Seleccionar nodo
            $nodeId = $plan->pterodactyl_node_id
                ?? $this->pterodactyl->autoSelectNode();

            // 3) Obtener una allocation libre
            $allocation   = $this->pterodactyl->getAvailableAllocation($nodeId);
            $allocationId = $allocation['attributes']['id'];

            // 4) Obtener detalles del egg desde Pterodactyl (variables, startup real)
            $eggDetails = $this->pterodactyl->getEggDetails(
                $selectedEgg->ptero_nest_id,
                $selectedEgg->ptero_egg_id
            );

            // 5) Construir environment:
            //    Base: variables del egg con sus defaults
            //    + Overrides del plan (pterodactyl_environment)
            //    + MAX_PLAYERS dinámico del servicio
            $environment = $this->buildEnvironment(
                $eggDetails,
                $plan->pterodactyl_environment ?? [],
                $service->max_players
            );

            // 6) Construir payload completo
            //
            // docker_image y startup vienen del egg sincronizado.
            // Si el plan tiene overrides (pterodactyl_docker_image / pterodactyl_startup)
            // se usan en su lugar para casos especiales (ej. Java específico).
            //
            // resolvedLimits() / resolvedFeatureLimits():
            //   1. pterodactyl_limits explícito del plan ← lo correcto
            //   2. Derivado de specifications           ← fallback inteligente
            //   3. Defaults del config                  ← último recurso
            //
            $payload = [
                'name'         => $service->name,
                'user'         => $pteroUserId,
                'egg'          => $selectedEgg->ptero_egg_id,
                'docker_image' => $plan->pterodactyl_docker_image
                                  ?? $selectedEgg->docker_image
                                  ?? $eggDetails['attributes']['docker_image'],
                'startup'      => $plan->pterodactyl_startup
                                  ?? $selectedEgg->startup
                                  ?? $eggDetails['attributes']['startup'],
                'environment'    => $environment,
                'limits'         => $plan->resolvedLimits(),
                'feature_limits' => $plan->resolvedFeatureLimits(),
                'allocation'     => ['default' => $allocationId],
                'external_id'    => (string) $service->uuid,
                'start_on_completion' => true,
            ];

            \Illuminate\Support\Facades\Log::info('Payload de aprovisionamiento Pterodactyl', [
                'service_id'  => $service->id,
                'plan_slug'   => $plan->slug,
                'egg'         => "{$selectedEgg->nest_name} / {$selectedEgg->egg_name}",
                'max_players' => $service->max_players,
                'limits'      => $payload['limits'],
                'features'    => $payload['feature_limits'],
            ]);

            // 6) Crear el servidor en Pterodactyl
            $server      = $this->pterodactyl->createServer($payload);
            $serverAttrs = $server['attributes'];

            // 7) DNS + connection_details ─────────────────────────────────────
            //
            // Java Edition → registro SRV, el cliente se conecta solo con el hostname
            //   kmartinez.rokeindustries.com   (sin puerto)
            //
            // Bedrock → registro A + mostrar puerto en la dirección
            //   kmartinez-bedrock.rokeindustries.com:19132
            //
            $isJava       = $this->isJavaEdition($plan);
            $subdomain    = $this->buildSubdomain($user);
            $vpsIp        = config('pterodactyl.relay_ip', '178.156.225.26');
            $hostname     = null;
            $dnsRecordIds = [];

            try {
                if ($isJava) {
                    // 1. Crear CNAME base: kmartinez.rokeindustries.com -> mc.rokeindustries.com
                    $dnsRecordIds['cname'] = $this->cloudflare->createCnameRecord(
                        $subdomain,
                        'mc.rokeindustries.com'
                    );

                    // 2. Crear SRV: _minecraft._tcp.kmartinez -> kmartinez.rokeindustries.com:PORT
                    $dnsRecordIds['srv'] = $this->cloudflare->createMinecraftSrv(
                        $subdomain,
                        (int) $allocation['attributes']['port']
                    );
                    $hostname = "{$subdomain}.rokeindustries.com";
                } else {
                    // Bedrock: kmartinez.rokeindustries.com -> IP (Registro A)
                    // El cliente se conecta con kmartinez.rokeindustries.com:PORT
                    $dnsRecordIds['a'] = $this->cloudflare->createARecord(
                        $subdomain,
                        $vpsIp
                    );
                    $hostname = "{$subdomain}.rokeindustries.com";
                }
            } catch (\Throwable $e) {
                // DNS no es bloqueante — el servidor igual funciona con IP:puerto
                Log::warning('DNS Cloudflare no creado (no fatal)', [
                    'service_id' => $service->id,
                    'error'      => $e->getMessage(),
                ]);
            }

            $service->update([
                'pterodactyl_server_id'   => $serverAttrs['id'],
                'pterodactyl_server_uuid' => $serverAttrs['uuid'],
                'pterodactyl_user_id'     => $pteroUserId,
                'external_id'             => (string) $serverAttrs['id'],
                'status'                  => 'active',
                'connection_details'      => [
                    'server_ip'        => $allocation['attributes']['ip'],
                    'server_port'      => $allocation['attributes']['port'],
                    // hostname = null cuando Cloudflare falló (fallback a IP:port)
                    'hostname'         => $hostname,
                    // display: lo que se muestra al usuario en el panel
                    'display'          => $hostname
                        ? ($isJava
                            ? $hostname
                            : "{$hostname}:{$allocation['attributes']['port']}")
                        : "{$allocation['attributes']['ip']}:{$allocation['attributes']['port']}",
                    'is_java'          => $isJava,
                    'panel_url'        => rtrim(config('pterodactyl.base_url'), '/')
                                         . '/server/' . $serverAttrs['identifier'],
                    'identifier'       => $serverAttrs['identifier'],
                    'pterodactyl_uuid' => $serverAttrs['uuid'],
                    // IDs de registros DNS para eliminarlos al terminar el servicio
                    'dns_record_ids'   => $dnsRecordIds,
                ],
            ]);

            // 8) Agregar proxy frp para el puerto del servidor
            try {
                $port = $allocation['attributes']['port'];
                $this->frp->addTcpProxy($port, $service->name);

                // Guardar el puerto en connection_details para poder eliminarlo al terminar
                $service->update([
                    'connection_details' => array_merge(
                        $service->connection_details ?? [],
                        ['frp_port' => $port]
                    ),
                ]);
            } catch (\Throwable $e) {
                Log::warning('FRP proxy no creado (no fatal)', [
                    'service_id' => $service->id,
                    'error'      => $e->getMessage(),
                ]);
            }

            // 9) Notificar al cliente
            $this->notifyProvisioned($user, $service->fresh());

            Log::info('Servidor de juego aprovisionado', [
                'service_id'            => $service->id,
                'pterodactyl_server_id' => $serverAttrs['id'],
                'node_id'               => $nodeId,
                'allocation'            => $allocation['attributes']['ip'] . ':' . $allocation['attributes']['port'],
                'hostname'              => $hostname,
                'dns_records'           => $dnsRecordIds,
            ]);

        } catch (\Throwable $e) {
            $service->update(['status' => 'failed']);

            Log::error('Aprovisionamiento Pterodactyl fallido', [
                'service_id' => $service->id,
                'plan_id'    => $plan->id,
                'error'      => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
            ]);

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
     * Elimina registros DNS de Cloudflare, borra el servidor de Pterodactyl
     * y marca el servicio como terminado.
     */
    public function terminate(Service $service): void
    {
        // Eliminar registros DNS antes de borrar el servidor
        $dnsIds = $service->connection_details['dns_record_ids'] ?? [];
        foreach ($dnsIds as $recordId) {
            try {
                $this->cloudflare->deleteRecord($recordId);
                Log::info('DNS record eliminado', [
                    'service_id' => $service->id,
                    'record_id'  => $recordId,
                ]);
            } catch (\Throwable $e) {
                Log::warning('No se pudo borrar DNS record', [
                    'service_id' => $service->id,
                    'record_id'  => $recordId,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

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

        $frpPort = $service->connection_details['frp_port'] ?? null;
        if ($frpPort) {
            try {
                $this->frp->removeTcpProxy((int) $frpPort);
            } catch (\Throwable $e) {
                Log::warning('FRP proxy no eliminado', [
                    'service_id' => $service->id,
                    'port'       => $frpPort,
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
            'status'         => $attrs['status']         ?? 'unknown',
            'suspended'      => $attrs['suspended']      ?? false,
            'node'           => $attrs['node']           ?? null,
            'limits'         => $attrs['limits']         ?? [],
            'feature_limits' => $attrs['feature_limits'] ?? [],
            'panel_url'      => rtrim(config('pterodactyl.base_url'), '/') . '/server/' . $attrs['identifier'],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers privados
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Determina si el plan es Java Edition (vs Bedrock u otro protocolo).
     * Basado en el nombre del plan o el game_type del plan.
     */
    private function isJavaEdition(mixed $plan): bool
    {
        $gameType = strtolower($plan->game_type ?? '');
        if ($gameType === 'bedrock') {
            return false;
        }

        // Fallback: revisar nombre del plan
        $bedrockKeywords = ['bedrock', 'nukkit', 'pocketmine', 'pmmp'];
        $planName        = strtolower($plan->name ?? '');

        return ! collect($bedrockKeywords)->contains(fn ($kw) => str_contains($planName, $kw));
    }

    /**
     * Genera el subdominio a partir del username o email del usuario.
     * Solo caracteres alfanuméricos y guiones, máx 30 caracteres.
     *
     * Si el usuario ya tiene un servicio activo con ese subdominio base,
     * añade un sufijo numérico para hacerlo único (ej. kmartinez-2, kmartinez-3…).
     *
     * Ej: "k.martinez@roke.com" → "kmartinez"
     *     "player_one"          → "player-one"
     *     segundo servidor      → "kmartinez-2"
     */
    private function buildSubdomain(User $user): string
    {
        $base  = $user->username ?? explode('@', $user->email)[0];
        $clean = preg_replace('/[^a-z0-9-]/', '-', strtolower($base));
        $clean = trim(preg_replace('/-+/', '-', $clean), '-');
        $base  = substr($clean ?: 'server' . $user->id, 0, 25);

        // Verificar si el subdominio base ya está en uso por este usuario
        $existing = Service::where('user_id', $user->id)
            ->whereIn('status', ['active', 'pending'])
            ->whereNotNull('connection_details')
            ->get()
            ->map(fn ($s) => $s->connection_details['hostname'] ?? null)
            ->filter()
            ->values();

        if (! $existing->contains(fn ($h) => str_starts_with($h, $base))) {
            return $base;
        }

        // Sufijo numérico incremental: base-2, base-3…
        for ($i = 2; $i <= 99; $i++) {
            $candidate = "{$base}-{$i}";
            if (! $existing->contains(fn ($h) => str_starts_with($h, $candidate))) {
                return $candidate;
            }
        }

        // Último recurso: base + ID de servicio
        return substr($base, 0, 20) . '-' . substr((string) $user->id, 0, 5);
    }

    /**
     * Construye el array de variables de entorno para Pterodactyl.
     *
     * Prioridad (de menor a mayor):
     *   1. Defaults del egg  (variables definidas en el egg con sus default_value)
     *   2. Overrides del plan (pterodactyl_environment en service_plans)
     *   3. MAX_PLAYERS dinámico del servicio
     *
     * Mapeo automático de MAX_PLAYERS:
     *   Minecraft/Paper  → MAX_PLAYERS
     *   Rust             → server.maxplayers
     *   Otros juegos     → MAX_PLAYERS (convención general de eggs Pterodactyl)
     */
    private function buildEnvironment(array $egg, array $planEnv, ?int $maxPlayers = null): array
    {
        $env = [];

        // 1) Defaults del egg
        foreach ($egg['attributes']['relationships']['variables']['data'] ?? [] as $var) {
            $attr               = $var['attributes'];
            $env[$attr['env_variable']] = $attr['default_value'];
        }

        // 2) Overrides del plan
        foreach ($planEnv as $key => $value) {
            $env[$key] = $value;
        }

        // 3) MAX_PLAYERS — inyectar si el egg lo soporta
        if ($maxPlayers !== null && $maxPlayers > 0) {
            // Determinar la variable correcta según las variables que define el egg
            $eggVarNames = collect($egg['attributes']['relationships']['variables']['data'] ?? [])
                ->map(fn($v) => $v['attributes']['env_variable'] ?? '')
                ->filter()
                ->all();

            foreach (['MAX_PLAYERS', 'PLAYERS', 'SERVER_MAXPLAYERS', 'MAXPLAYERS'] as $candidate) {
                if (in_array($candidate, $eggVarNames, true)) {
                    $env[$candidate] = (string) $maxPlayers;
                    break;
                }
            }

            // Si el egg no define ninguna variable conocida pero sí define MAX_PLAYERS
            // (muchos eggs de Pterodactyl usan este nombre por convención), setearla de todas formas
            if (! isset($env['MAX_PLAYERS'])) {
                $env['MAX_PLAYERS'] = (string) $maxPlayers;
            }
        }

        return $env;
    }

    private function requirePterodactylServer(Service $service): void
    {
        if (! $service->pterodactyl_server_id) {
            throw new RuntimeException("El servicio #{$service->id} no tiene servidor de Pterodactyl asociado.");
        }
    }

    private function notifyProvisioned(User $user, Service $service): void
    {
        try {
            $details = $service->connection_details ?? [];
            $display = $details['display'] ?? ($details['server_ip'] . ':' . $details['server_port']);

            Notification::send($user, new \App\Notifications\ServiceNotification([
                'title'   => '¡Tu servidor está listo!',
                'message' => "Tu servidor '{$service->name}' ha sido creado y está en línea. Dirección: {$display}",
                'type'    => 'service.provisioned',
                'data'    => [
                    'service_id'  => $service->uuid,
                    'display'     => $display,
                    'server_ip'   => $details['server_ip']   ?? null,
                    'server_port' => $details['server_port'] ?? null,
                    'hostname'    => $details['hostname']    ?? null,
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
