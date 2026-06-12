<?php

namespace App\Domains\Platform\Compute\Providers\Coolify;

use App\Domains\Platform\Compute\Models\Resource;
use App\Domains\Platform\Compute\Providers\Contracts\DatabaseDriver;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
        if (! is_array($db)) {
            $this->failConnection($externalId, 'la respuesta no es un objeto JSON', []);
        }

        // Coolify expone los campos con prefijo por engine; tolerar variantes.
        $first = fn (array $keys) => collect($keys)
            ->map(fn ($k) => $db[$k] ?? null)
            ->first(fn ($v) => $v !== null && $v !== '');

        $engine   = $this->engineFromResponse($db);
        $host     = $first(['internal_db_host', 'internal_db_url', 'hostname']);
        $password = $first(['mysql_password', 'postgres_password', 'redis_password', 'password']);
        $database = $first(['mysql_database', 'postgres_db', 'database']);
        $username = $first(['mysql_user', 'postgres_user', 'username']);

        // Campos que SIEMPRE deben venir. Si faltan, fallamos ruidoso: NUNCA
        // adivinamos host/credenciales (un dato inventado rompería la app en
        // silencio). Redis no tiene base ni usuario, así que no se exigen.
        $required = ['host' => $host, 'password' => $password];
        if ($engine !== 'redis') {
            $required['database'] = $database;
            $required['username'] = $username;
        }

        $missing = array_keys(array_filter($required, fn ($v) => $v === null));
        if ($missing !== []) {
            $this->failConnection($externalId, 'faltan campos de conexión: ' . implode(', ', $missing), array_keys($db));
        }

        return [
            'host'     => (string) $host,
            'port'     => (int) ($first(['public_port', 'mysql_port', 'postgres_port', 'redis_port']) ?? $this->defaultPortForEngine($engine)),
            'database' => (string) ($database ?? ''),
            'username' => (string) ($username ?? ''),
            'password' => (string) $password,
        ];
    }

    /**
     * Aborta la lectura de conexión con contexto claro. Loguea SOLO las claves
     * presentes (no los valores) para no filtrar la contraseña al log.
     *
     * @param  string[]  $keysPresent
     */
    private function failConnection(string $externalId, string $reason, array $keysPresent): never
    {
        Log::error('Coolify connectionInfo con shape inesperado', [
            'database' => $externalId,
            'reason'   => $reason,
            'keys'     => $keysPresent, // claves, NUNCA valores (evita filtrar secretos)
        ]);

        throw new RuntimeException(
            "No se pudo leer la conexión de la base de datos {$externalId}: {$reason}. "
            . 'Claves recibidas de Coolify: ' . (implode(', ', $keysPresent) ?: '(ninguna)') . '.'
        );
    }

    private function engineFromResponse(array $db): string
    {
        return match (true) {
            isset($db['postgres_db']) || isset($db['postgres_user']) || isset($db['postgres_password']) => 'postgres',
            isset($db['redis_password']) => 'redis',
            default => 'mysql',
        };
    }

    private function defaultPortForEngine(string $engine): int
    {
        return match ($engine) {
            'postgres' => 5432,
            'redis'    => 6379,
            default    => 3306,
        };
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
