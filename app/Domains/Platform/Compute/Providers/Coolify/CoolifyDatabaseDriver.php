<?php

namespace App\Domains\Platform\Compute\Providers\Coolify;

use App\Domains\Platform\Compute\Models\Resource;
use App\Domains\Platform\Compute\Providers\Contracts\DatabaseDriver;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Driver de bases de datos administradas de Coolify v4.
 *
 * Mapea engine → endpoint (databases/mysql|postgresql|redis) y lee del recurso
 * los datos de conexión interna que Coolify genera. El host interno es el uuid
 * del servicio en la red de Docker del proyecto — las apps del mismo proyecto
 * lo resuelven por nombre. La contraseña la genera Coolify; nunca la fijamos.
 */
class CoolifyDatabaseDriver implements DatabaseDriver
{
    private string $serverUuid;

    /** engine ROKE → segmento de endpoint Coolify. */
    private const ENGINE_PATH = [
        'mysql'    => 'mysql',
        'postgres' => 'postgresql',
        'redis'    => 'redis',
    ];

    public function __construct()
    {
        $this->serverUuid = (string) config('coolify.server_uuid', '');
    }

    public function createDatabase(Resource $resource, array $config): string
    {
        $engine = (string) ($config['engine'] ?? 'mysql');
        $path   = self::ENGINE_PATH[$engine] ?? throw new RuntimeException("Engine no soportado: {$engine}");

        $project     = $resource->environment->project;
        $coolifyApp  = new CoolifyDriver();
        $projectUuid = $coolifyApp->ensureProject($project);

        $payload = array_filter([
            'server_uuid'      => $this->serverUuid,
            'project_uuid'     => $projectUuid,
            'environment_name' => $resource->environment->slug,
            'name'             => $resource->name,
            // Coolify nombra la base/usuario; fijamos solo el nombre lógico de la DB.
            'database'         => $config['name'] ?? $resource->name,
        ]);

        $response = $this->http()->post("/api/v1/databases/{$path}", $payload);
        $this->assertOk($response, 'createDatabase');

        return (string) $response->json('uuid');
    }

    public function getDatabase(string $externalId): array
    {
        $response = $this->http()->get("/api/v1/databases/{$externalId}");
        $this->assertOk($response, 'getDatabase');

        $status = (string) $response->json('status', 'starting');

        // Coolify reporta "running:healthy", "starting", "exited", etc.
        $normalized = match (true) {
            str_starts_with($status, 'running')  => 'running',
            str_contains($status, 'exited'),
            str_contains($status, 'error')       => 'failed',
            default                              => 'starting',
        };

        return ['status' => $normalized];
    }

    public function connectionInfo(string $externalId): array
    {
        $response = $this->http()->get("/api/v1/databases/{$externalId}");
        $this->assertOk($response, 'connectionInfo');

        $db = $response->json();

        // Coolify expone los campos con prefijo por engine; tolerar variantes.
        $get = fn (array $keys, $default = null) => collect($keys)
            ->map(fn ($k) => $db[$k] ?? null)
            ->first(fn ($v) => $v !== null && $v !== '') ?? $default;

        return [
            // Host interno = uuid del servicio en la red del proyecto.
            'host'     => (string) $get(['internal_db_host', 'hostname'], $externalId),
            'port'     => (int) $get(['public_port', 'mysql_port', 'postgres_port', 'redis_port'], $this->defaultPort($db)),
            'database' => (string) $get(['mysql_database', 'postgres_db', 'database'], ''),
            'username' => (string) $get(['mysql_user', 'postgres_user', 'username'], ''),
            'password' => (string) $get(['mysql_password', 'postgres_password', 'redis_password', 'password'], ''),
        ];
    }

    public function startDatabase(string $externalId): void
    {
        $this->assertOk($this->http()->post("/api/v1/databases/{$externalId}/start"), 'startDatabase');
    }

    public function deleteDatabase(string $externalId): void
    {
        $response = $this->http()->delete("/api/v1/databases/{$externalId}");

        if ($response->status() !== 404) { // ya borrada = idempotente
            $this->assertOk($response, 'deleteDatabase');
        }
    }

    private function defaultPort(array $db): int
    {
        return match (true) {
            isset($db['postgres_db'], $db['postgres_user']) => 5432,
            isset($db['redis_password'])                    => 6379,
            default                                          => 3306,
        };
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('coolify.base_url', ''), '/'))
            ->timeout(30)
            ->withToken((string) config('coolify.api_token', ''))
            ->acceptJson();
    }

    private function assertOk(Response $response, string $operation): void
    {
        if ($response->failed()) {
            throw new RuntimeException(
                "Coolify {$operation} falló (HTTP {$response->status()}): "
                . substr($response->body(), 0, 300)
            );
        }
    }
}
