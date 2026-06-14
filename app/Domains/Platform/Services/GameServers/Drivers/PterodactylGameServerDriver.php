<?php

namespace App\Domains\Platform\Services\GameServers\Drivers;

use App\Domains\Platform\Services\GameServers\Contracts\GameServerDriver;
use App\Domains\Platform\Services\Pterodactyl\PterodactylService;

/**
 * Implementación Pterodactyl del GameServerDriver. Es un ADAPTADOR delgado:
 * delega 1:1 en el `PterodactylService` existente (el cliente del panel que ya
 * funciona). No reescribe ni cambia comportamiento — solo expone esas
 * operaciones detrás del contrato neutral para poder enchufar otro panel
 * (Pelican) bajo la misma interfaz.
 */
class PterodactylGameServerDriver implements GameServerDriver
{
    public function __construct(private readonly PterodactylService $pterodactyl)
    {
    }

    public function name(): string
    {
        return 'pterodactyl';
    }

    public function getServer(int $serverId): array
    {
        return $this->pterodactyl->getServer($serverId);
    }

    public function suspendServer(int $serverId): void
    {
        $this->pterodactyl->suspendServer($serverId);
    }

    public function unsuspendServer(int $serverId): void
    {
        $this->pterodactyl->unsuspendServer($serverId);
    }

    public function reinstallServer(int $serverId): void
    {
        $this->pterodactyl->reinstallServer($serverId);
    }

    public function deleteServer(int $serverId, bool $force = true): void
    {
        $this->pterodactyl->deleteServer($serverId, $force);
    }

    public function sendPowerSignal(string $identifier, string $signal): void
    {
        $this->pterodactyl->sendPowerSignal($identifier, $signal);
    }

    public function getServerResources(string $identifier): array
    {
        return $this->pterodactyl->getServerResources($identifier);
    }
}
