<?php

namespace App\Services\Coolify;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class CoolifyService
{
    private string $baseUrl;
    private string $apiToken;
    private string $teamId;
    private string $serverUuid;

    public function __construct()
    {
        $this->baseUrl    = rtrim(config('coolify.base_url'), '/');
        $this->apiToken   = config('coolify.api_token', '');
        $this->teamId     = (string) config('coolify.team_id', '0');
        $this->serverUuid = config('coolify.server_uuid', '');
    }

    // ── Proyectos ─────────────────────────────────────────────────────────────

    public function createProject(string $name, string $description = ''): array
    {
        $response = $this->http()->post('/api/v1/projects', [
            'name'        => $name,
            'description' => $description,
        ]);

        $this->assertOk($response, 'createProject');

        return $response->json();
    }

    public function deleteProject(string $projectUuid): void
    {
        $response = $this->http()->delete("/api/v1/projects/{$projectUuid}");

        if ($response->status() !== 204 && $response->failed()) {
            $this->assertOk($response, 'deleteProject');
        }
    }

    public function listProjects(): array
    {
        $response = $this->http()->get('/api/v1/projects');

        $this->assertOk($response, 'listProjects');

        return $response->json() ?? [];
    }

    // ── Aplicaciones ──────────────────────────────────────────────────────────

    /**
     * Crea una aplicación en Coolify usando docker image.
     *
     * $data campos clave:
     *   project_uuid    string  (requerido)
     *   server_uuid     string  (requerido, default: config)
     *   name            string
     *   fqdn            string  ej: "https://cliente.rokeindustries.com"
     *   build_pack      string  'static' | 'php'  → mapea a docker image
     *   environment_name string (default: 'production')
     */
    public function createApplication(array $data): array
    {
        $buildPack   = $data['build_pack'] ?? 'static';
        $dockerImage = $this->resolveDockerImage($buildPack);

        $payload = [
            'project_uuid'     => $data['project_uuid'],
            'server_uuid'      => $data['server_uuid'] ?? $this->serverUuid,
            'environment_name' => $data['environment_name'] ?? 'production',
            'docker_image'     => $dockerImage,
            'name'             => $data['name'],
            'fqdn'             => $data['fqdn'] ?? null,
            'ports_exposes'    => '80',
            'instant_deploy'   => false,
        ];

        $response = $this->http()->post('/api/v1/applications/dockerimage', $payload);

        $this->assertOk($response, 'createApplication');

        return $response->json();
    }

    public function deleteApplication(string $appUuid): void
    {
        $response = $this->http()->delete("/api/v1/applications/{$appUuid}");

        if ($response->status() !== 204 && $response->failed()) {
            $this->assertOk($response, 'deleteApplication');
        }
    }

    public function startApplication(string $appUuid): void
    {
        $response = $this->http()->post("/api/v1/applications/{$appUuid}/start");
        $this->assertOk($response, 'startApplication');
    }

    public function stopApplication(string $appUuid): void
    {
        $response = $this->http()->post("/api/v1/applications/{$appUuid}/stop");
        $this->assertOk($response, 'stopApplication');
    }

    public function restartApplication(string $appUuid): void
    {
        $response = $this->http()->post("/api/v1/applications/{$appUuid}/restart");
        $this->assertOk($response, 'restartApplication');
    }

    public function deployApplication(string $appUuid): array
    {
        $response = $this->http()->post('/api/v1/deploy', ['uuid' => $appUuid, 'force' => false]);
        $this->assertOk($response, 'deployApplication');
        return $response->json() ?? [];
    }

    public function getApplication(string $appUuid): array
    {
        $response = $this->http()->get("/api/v1/applications/{$appUuid}");
        $this->assertOk($response, 'getApplication');

        return $response->json();
    }

    /**
     * Actualiza campos de una aplicación en Coolify (PATCH).
     * Campos comunes: redirect_http_to_https, fqdn, name, etc.
     */
    public function updateApplication(string $appUuid, array $data): array
    {
        $response = $this->http()->patch("/api/v1/applications/{$appUuid}", $data);
        $this->assertOk($response, 'updateApplication');

        return $response->json() ?? [];
    }

    /**
     * Lista todas las aplicaciones de un proyecto (via recursos del environment).
     */
    public function listApplications(string $projectUuid, string $environment = 'production'): array
    {
        $response = $this->http()->get("/api/v1/projects/{$projectUuid}/{$environment}/resources");

        $this->assertOk($response, 'listApplications');

        $resources = $response->json() ?? [];

        return array_filter($resources, fn($r) => ($r['type'] ?? '') === 'application');
    }

    // ── Bases de datos ────────────────────────────────────────────────────────

    /**
     * Crea una base de datos en Coolify.
     *
     * $data campos clave:
     *   project_uuid    string
     *   server_uuid     string
     *   name            string
     *   type            string  'mariadb' | 'mysql' | 'postgresql'
     *   environment_name string (default: 'production')
     */
    public function createDatabase(array $data): array
    {
        $type    = $data['type'] ?? 'mariadb';
        $dbName  = preg_replace('/[^a-z0-9_]/', '_', strtolower($data['name'] ?? 'hosting_db'));
        $dbUser  = $dbName . '_user';
        $dbPass  = $this->generateDbPassword();
        $rootPass = $this->generateDbPassword();

        $payload = [
            'project_uuid'     => $data['project_uuid'],
            'server_uuid'      => $data['server_uuid'] ?? $this->serverUuid,
            'environment_name' => $data['environment_name'] ?? 'production',
            'name'             => $data['name'],
        ];

        $payload = match ($type) {
            'mysql' => array_merge($payload, [
                'mysql_root_password' => $rootPass,
                'mysql_database'      => $dbName,
                'mysql_user'          => $dbUser,
                'mysql_password'      => $dbPass,
            ]),
            'postgresql' => array_merge($payload, [
                'postgres_user'     => $dbUser,
                'postgres_password' => $dbPass,
                'postgres_db'       => $dbName,
            ]),
            default => array_merge($payload, [ // mariadb
                'mariadb_root_password' => $rootPass,
                'mariadb_database'      => $dbName,
                'mariadb_user'          => $dbUser,
                'mariadb_password'      => $dbPass,
            ]),
        };

        $response = $this->http()->post("/api/v1/databases/{$type}", $payload);

        $this->assertOk($response, 'createDatabase');

        $result = $response->json();

        // Normalizar credenciales en la respuesta para acceso uniforme
        $result['_db_name']     = $dbName;
        $result['_db_user']     = $dbUser;
        $result['_db_password'] = $dbPass;
        $result['_db_type']     = $type;

        return $result;
    }

    public function deleteDatabase(string $dbUuid): void
    {
        $response = $this->http()->delete("/api/v1/databases/{$dbUuid}");

        if ($response->status() !== 204 && $response->failed()) {
            $this->assertOk($response, 'deleteDatabase');
        }
    }

    public function startDatabase(string $dbUuid): void
    {
        $response = $this->http()->post("/api/v1/databases/{$dbUuid}/start");
        $this->assertOk($response, 'startDatabase');
    }

    public function stopDatabase(string $dbUuid): void
    {
        $response = $this->http()->post("/api/v1/databases/{$dbUuid}/stop");
        $this->assertOk($response, 'stopDatabase');
    }

    public function getDatabase(string $dbUuid): array
    {
        $response = $this->http()->get("/api/v1/databases/{$dbUuid}");
        $this->assertOk($response, 'getDatabase');

        return $response->json();
    }

    // ── Recursos generales ────────────────────────────────────────────────────

    public function listResources(string $projectUuid, string $environment = 'production'): array
    {
        $response = $this->http()->get("/api/v1/projects/{$projectUuid}/{$environment}/resources");
        $this->assertOk($response, 'listResources');

        return $response->json() ?? [];
    }

    // ── Servidor ──────────────────────────────────────────────────────────────

    public function getServerInfo(): array
    {
        $response = $this->http()->get("/api/v1/servers/{$this->serverUuid}");
        $this->assertOk($response, 'getServerInfo');

        return $response->json();
    }

    public function validateServer(): bool
    {
        $response = $this->http()->get("/api/v1/servers/{$this->serverUuid}/validate");

        return $response->successful();
    }

    // ── Helpers privados ──────────────────────────────────────────────────────

    private function http()
    {
        return Http::baseUrl($this->baseUrl)
            ->withToken($this->apiToken)
            ->when(! config('coolify.verify_ssl', true), fn ($h) => $h->withoutVerifying())
            ->timeout(60)
            ->acceptJson()
            ->asJson();
    }

    private function assertOk(Response $response, string $method): void
    {
        if ($response->successful()) {
            return;
        }

        Log::error("CoolifyService::{$method} falló", [
            'status' => $response->status(),
            'body'   => $response->body(),
        ]);

        throw new RuntimeException("Coolify [{$method}]: HTTP {$response->status()} — {$response->body()}");
    }

    private function resolveDockerImage(string $buildPack): string
    {
        return match ($buildPack) {
            'php'    => 'serversideup/php:8.2-fpm-nginx',
            'static' => 'nginx:alpine',
            default  => 'nginx:alpine',
        };
    }

    private function generateDbPassword(): string
    {
        return Str::random(32);
    }
}
