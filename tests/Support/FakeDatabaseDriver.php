<?php

namespace Tests\Support;

use App\Domains\Platform\Compute\Models\Resource;
use App\Domains\Platform\Compute\Providers\Contracts\DatabaseDriver;

/**
 * Driver fake de data stores para tests del orquestador: registra llamadas y
 * simula el arranque (N polls starting → running) con una conexión fija.
 */
class FakeDatabaseDriver implements DatabaseDriver
{
    /** @var array<int, array{method: string, args: array}> */
    public array $calls = [];

    public bool $failDatabase = false;

    public int $pollsUntilReady = 1;

    private int $polls = 0;

    /** Conexión que devuelve connectionInfo() — los tests la inspeccionan. */
    public array $connection = [
        'host'     => 'db-internal-host',
        'port'     => 3306,
        'database' => 'app_db',
        'username' => 'app_user',
        'password' => 's3cr3t-pass',
    ];

    public function createDatabase(Resource $resource, array $config): string
    {
        $this->record(__FUNCTION__, [$resource->uuid, $config]);
        $this->polls = 0;

        return 'cool-db-1';
    }

    public function getDatabase(string $externalId): array
    {
        $this->record(__FUNCTION__, func_get_args());
        $this->polls++;

        if ($this->failDatabase) {
            return ['status' => 'failed'];
        }

        return ['status' => $this->polls < $this->pollsUntilReady ? 'starting' : 'running'];
    }

    public function connectionInfo(string $externalId): array
    {
        $this->record(__FUNCTION__, func_get_args());

        return $this->connection;
    }

    public function startDatabase(string $externalId): void
    {
        $this->record(__FUNCTION__, func_get_args());
    }

    public function deleteDatabase(string $externalId): void
    {
        $this->record(__FUNCTION__, func_get_args());
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
