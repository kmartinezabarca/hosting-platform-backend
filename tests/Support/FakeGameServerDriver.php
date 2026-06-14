<?php

namespace Tests\Support;

use App\Domains\Platform\Services\GameServers\Contracts\GameServerDriver;

/**
 * Driver fake del panel de juegos para tests: registra llamadas y devuelve
 * datos canónicos, sin tocar Pterodactyl.
 */
class FakeGameServerDriver implements GameServerDriver
{
    /** @var array<int, array{method: string, args: array}> */
    public array $calls = [];

    public function name(): string
    {
        return 'fake';
    }

    public function getServer(int $serverId): array
    {
        $this->record(__FUNCTION__, func_get_args());

        return ['id' => $serverId, 'status' => 'running'];
    }

    public function suspendServer(int $serverId): void
    {
        $this->record(__FUNCTION__, func_get_args());
    }

    public function unsuspendServer(int $serverId): void
    {
        $this->record(__FUNCTION__, func_get_args());
    }

    public function reinstallServer(int $serverId): void
    {
        $this->record(__FUNCTION__, func_get_args());
    }

    public function deleteServer(int $serverId, bool $force = true): void
    {
        $this->record(__FUNCTION__, func_get_args());
    }

    public function sendPowerSignal(string $identifier, string $signal): void
    {
        $this->record(__FUNCTION__, func_get_args());
    }

    public function getServerResources(string $identifier): array
    {
        $this->record(__FUNCTION__, func_get_args());

        return ['current_state' => 'running', 'resources' => []];
    }

    public function called(string $method): bool
    {
        return collect($this->calls)->contains(fn ($c) => $c['method'] === $method);
    }

    private function record(string $method, array $args): void
    {
        $this->calls[] = ['method' => $method, 'args' => $args];
    }
}
