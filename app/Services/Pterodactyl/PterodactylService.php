<?php

namespace App\Services\Pterodactyl;

use App\Exceptions\PterodactylApiException;
use App\Models\User;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Cliente HTTP para la Application API de Pterodactyl.
 *
 * Auth: Bearer token (Application API key que empieza con "ptla_")
 * Docs: https://dashflo.net/docs/api/pterodactyl/v1/
 */
class PterodactylService
{
    private string $baseUrl;
    private string $apiKey;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('pterodactyl.base_url'), '/');
        $this->apiKey  = config('pterodactyl.api_key', '');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Usuarios
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Busca un usuario en Pterodactyl por email o lo crea si no existe.
     * Actualiza `user->pterodactyl_user_id` en nuestra BD.
     *
     * @return array  Objeto usuario de Pterodactyl (con clave 'attributes')
     */
    public function findOrCreateUser(User $user): array
    {
        // Buscar por email
        $response = $this->http()->get('/api/application/users', [
            'filter[email]' => $user->email,
        ]);

        $this->assertOk($response, 'findUser');

        $data = $response->json('data', []);

        if (!empty($data)) {
            $pterodactylUser = $data[0];
            $pteroId = $pterodactylUser['attributes']['id'];
        } else {
            // Crear usuario nuevo
            $createResp = $this->http()->post('/api/application/users', [
                'email'      => $user->email,
                'username'   => $this->sanitizeUsername($user->email),
                'first_name' => $user->first_name ?? 'User',
                'last_name'  => $user->last_name  ?? (string) $user->id,
                'password'   => \Illuminate\Support\Str::random(32),
            ]);

            $this->assertOk($createResp, 'createUser');
            $pterodactylUser = $createResp->json();
            $pteroId = $pterodactylUser['attributes']['id'];
        }

        // Persistir en nuestra BD
        $user->update(['pterodactyl_user_id' => $pteroId]);

        return $pterodactylUser;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Nodos y Allocations
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Selecciona automáticamente el nodo con más allocations libres.
     */
    public function autoSelectNode(): int
    {
        $response = $this->http()->get('/api/application/nodes', ['per_page' => 100]);
        $this->assertOk($response, 'listNodes');

        $nodes = collect($response->json('data', []));

        if ($nodes->isEmpty()) {
            throw new RuntimeException('No hay nodos configurados en Pterodactyl.');
        }

        // Contamos allocations libres por nodo para elegir el menos cargado
        $best = null;
        $bestFree = -1;

        foreach ($nodes as $node) {
            $nodeId = $node['attributes']['id'];
            $freeCount = $this->countFreeAllocations($nodeId);
            if ($freeCount > $bestFree) {
                $bestFree = $freeCount;
                $best = $nodeId;
            }
        }

        if ($best === null || $bestFree === 0) {
            throw new RuntimeException('No hay allocations disponibles en ningún nodo.');
        }

        return $best;
    }

    private function countFreeAllocations(int $nodeId): int
{
    $response = $this->http()->get("/api/application/nodes/{$nodeId}/allocations", [
        'per_page' => 100,
    ]);
    if ($response->failed()) return 0;

    $data = $response->json('data', []);
    return count(array_filter($data, fn($a) => empty($a['attributes']['assigned'])));
}

    /**
     * Devuelve la primera allocation libre del nodo indicado.
     *
     * @return array  Objeto allocation con 'id', 'ip', 'port'
     */
    public function getAvailableAllocation(int $nodeId): array
{
    $response = $this->http()->get("/api/application/nodes/{$nodeId}/allocations", [
        'per_page' => 100,
    ]);

    $this->assertOk($response, 'getAvailableAllocation');

    $data = $response->json('data', []);

    // Filtrar manualmente las allocations no asignadas
    foreach ($data as $allocation) {
        if (empty($allocation['attributes']['assigned'])) {
            return $allocation;
        }
    }

    throw new RuntimeException("No hay allocations libres en el nodo #{$nodeId}.");
}

    // ─────────────────────────────────────────────────────────────────────────
    // Eggs (juegos)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Obtiene los detalles de un egg incluyendo sus variables de entorno.
     */
    public function getEggDetails(int $nestId, int $eggId): array
    {
        $response = $this->http()->get(
            "/api/application/nests/{$nestId}/eggs/{$eggId}",
            ['include' => 'variables']
        );

        $this->assertOk($response, 'getEggDetails');
        return $response->json();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Servidores
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Crea un nuevo servidor de juego en Pterodactyl.
     *
     * @param  array $data  Payload completo (user, egg, limits, allocation, environment, etc.)
     * @return array  Objeto servidor creado
     */
    public function createServer(array $data): array
    {
        $response = $this->http()->post('/api/application/servers', $data);
        $this->assertOk($response, 'createServer', $data);
        return $response->json();
    }

    /** GET /api/application/servers/{id} */
    public function getServer(int $serverId): array
    {
        $response = $this->http()->get("/api/application/servers/{$serverId}");
        $this->assertOk($response, 'getServer');
        return $response->json();
    }

    /** POST /api/application/servers/{id}/suspend */
    public function suspendServer(int $serverId): void
    {
        $response = $this->http()->post("/api/application/servers/{$serverId}/suspend");
        $this->assertOk($response, 'suspendServer');
    }

    /** POST /api/application/servers/{id}/unsuspend */
    public function unsuspendServer(int $serverId): void
    {
        $response = $this->http()->post("/api/application/servers/{$serverId}/unsuspend");
        $this->assertOk($response, 'unsuspendServer');
    }

    /** POST /api/application/servers/{id}/reinstall */
    public function reinstallServer(int $serverId): void
    {
        $response = $this->http()->post("/api/application/servers/{$serverId}/reinstall");
        $this->assertOk($response, 'reinstallServer');
    }

    /**
     * Elimina un servidor de Pterodactyl.
     * Con $force = true lo elimina incluso si está en línea.
     */
    public function deleteServer(int $serverId, bool $force = true): void
    {
        $url = "/api/application/servers/{$serverId}" . ($force ? '/force' : '');
        $response = $this->http()->delete($url);

        // 204 No Content = éxito
        if ($response->status() !== 204 && $response->failed()) {
            $this->assertOk($response, 'deleteServer');
        }
    }

    /** PATCH /api/application/servers/{id}/build — actualizar recursos */
    public function updateServerBuild(int $serverId, array $limits): void
    {
        $response = $this->http()->patch("/api/application/servers/{$serverId}/build", $limits);
        $this->assertOk($response, 'updateServerBuild');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Client API — métricas y control de energía
    // (usa el Client API key del admin, no el Application key)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Devuelve el estado y métricas en tiempo real del servidor.
     *
     * Respuesta incluye:
     *   current_state: running | starting | stopping | offline
     *   resources: { cpu_absolute, memory_bytes, disk_bytes, network_rx_bytes, network_tx_bytes }
     *   is_suspended: bool
     *
     * @param  string $identifier  El identificador corto del servidor (ej. "c5f04c87")
     */
    public function getServerResources(string $identifier): array
    {
        $response = $this->clientHttp()->get("/api/client/servers/{$identifier}/resources");
        $this->assertOk($response, 'getServerResources');
        return $response->json('attributes', []);
    }

    /**
     * Envía una señal de energía al servidor.
     *
     * @param  string $identifier  Identificador corto del servidor
     * @param  string $signal      start | stop | restart | kill
     */
    public function sendPowerSignal(string $identifier, string $signal): void
    {
        if (!in_array($signal, ['start', 'stop', 'restart', 'kill'], true)) {
            throw new RuntimeException("Señal inválida: {$signal}. Use: start, stop, restart, kill.");
        }

        $response = $this->clientHttp()->post(
            "/api/client/servers/{$identifier}/power",
            ['signal' => $signal]
        );

        // 204 No Content = éxito
        if ($response->status() !== 204 && $response->failed()) {
            $this->assertOk($response, 'sendPowerSignal');
        }
    }

    /**
     * Lista archivos/directorios desde la Client API de Pterodactyl.
     *
     * @param  string $identifier  Identificador corto del servidor
     */
    public function listFiles(string $identifier, string $directory): array
    {
        $response = $this->clientHttp()->get(
            "/api/client/servers/{$identifier}/files/list",
            ['directory' => $directory]
        );

        $this->assertClientApiOk($response, 'listFiles');

        return collect($response->json('data', []))
            ->map(fn (array $file) => $file['attributes'] ?? [])
            ->map(fn (array $attributes) => [
                'name'        => $attributes['name'] ?? null,
                'size'        => $attributes['size'] ?? 0,
                'modified_at' => $attributes['modified_at'] ?? null,
                'is_file'     => $attributes['is_file'] ?? false,
                'mimetype'    => $attributes['mimetype'] ?? null,
            ])
            ->values()
            ->all();
    }

    /**
     * Obtiene una URL firmada para subir archivos directo al panel.
     */
    public function getUploadUrl(string $identifier): string
    {
        $response = $this->clientHttp()->get("/api/client/servers/{$identifier}/files/upload");

        $this->assertClientApiOk($response, 'getUploadUrl');

        return $this->extractSignedUrl($response, 'getUploadUrl');
    }

    /**
     * Elimina archivos/directorios desde la Client API de Pterodactyl.
     */
    public function deleteFiles(string $identifier, string $root, array $files): void
    {
        $response = $this->clientHttp()->post(
            "/api/client/servers/{$identifier}/files/delete",
            [
                'root'  => $root,
                'files' => $files,
            ]
        );

        if ($response->status() !== 204 && $response->failed()) {
            $this->assertClientApiOk($response, 'deleteFiles');
        }
    }

    /**
     * Obtiene una URL firmada para descargar un archivo.
     */
    public function getDownloadUrl(string $identifier, string $file): string
    {
        $response = $this->clientHttp()->get(
            "/api/client/servers/{$identifier}/files/download",
            ['file' => $file]
        );

        $this->assertClientApiOk($response, 'getDownloadUrl');

        return $this->extractSignedUrl($response, 'getDownloadUrl');
    }

    /**
     * Lee un archivo del filesystem del server via Client API.
     */
    public function readServerFile(string $identifier, string $file): string
    {
        $response = $this->clientHttp()->get(
            "/api/client/servers/{$identifier}/files/contents",
            ['file' => $file]
        );

        $this->assertClientApiOk($response, 'readServerFile');

        return $response->body();
    }

    /**
     * Escribe un archivo del filesystem del server via Client API.
     */
    public function writeServerFile(string $identifier, string $file, string $contents): void
    {
        $response = $this->clientHttp()
            ->withBody($contents, 'text/plain')
            ->post("/api/client/servers/{$identifier}/files/write?file=" . urlencode($file));

        if ($response->status() !== 204 && $response->failed()) {
            $this->assertClientApiOk($response, 'writeServerFile');
        }
    }

    public function getStartupConfig(string $identifier)
    {
        $response = $this->clientHttp()->get("/api/client/servers/{$identifier}/startup");

        $this->assertClientApiOk($response, 'getStartupConfig');
    }

    public function updateMinecraftVersion(string $identifier, string $version): void
    {
        $response = $this->clientHttp()->put(
            "/api/client/servers/{$identifier}/startup/variable",
            [
                'key'   => 'MINECRAFT_VERSION',
                'value' => $version,
            ]
        );

        $this->assertClientApiOk($response, 'updateMinecraftVersion');
    }

    public function listNestEggs(int $nestId): array
    {
        $response = $this->http()->get("/api/application/nests/{$nestId}/eggs", [
            'include' => 'variables',
            'per_page' => 100,
        ]);

        $this->assertOk($response, 'listNestEggs');

        return collect($response->json('data', []))
            ->map(fn($egg) => $egg['attributes'] ?? [])
            ->values()
            ->all();
    }
    /**
     * Actualiza startup/env/docker image de un servidor usando Application API.
     */
    public function updateServerStartup(
        int $serverId,
        array $environment,
        string $startup,
        ?int $egg,
        string $dockerImage
    ): void {
        if (!$egg) {
            throw new RuntimeException('No se pudo determinar el egg de Pterodactyl para actualizar startup.');
        }

        $response = $this->http()->patch("/api/application/servers/{$serverId}/startup", [
            'startup' => $startup,
            'environment' => $environment,
            'egg' => $egg,
            'image' => $dockerImage,
            'skip_scripts' => false,
        ]);

        $this->assertClientApiOk($response, 'updateServerStartup');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers privados
    // ─────────────────────────────────────────────────────────────────────────

    /** Application API client (gestión de usuarios/servidores/nodos) */
    private function http()
    {
        return Http::baseUrl($this->baseUrl)
            ->withToken($this->apiKey)
            ->withoutVerifying()
            ->timeout(config('pterodactyl.timeout', 60))
            ->acceptJson()
            ->asJson();
    }

    /** Client API client (power actions y métricas — usa client API key del admin) */
    private function clientHttp()
    {
        return Http::baseUrl($this->baseUrl)
            ->withToken(config('pterodactyl.client_api_key', ''))
            ->withoutVerifying()
            ->timeout(config('pterodactyl.timeout', 60))
            ->acceptJson()
            ->asJson();
    }

    private function assertOk(Response $response, string $method, array $payload = []): void
    {
        if ($response->successful()) return;

        $errors = $response->json('errors', []);
        $detail = $errors[0]['detail'] ?? $errors[0]['status'] ?? null;
        $message = $response->json('message') ?? $detail ?? "HTTP {$response->status()}";

        Log::error("PterodactylService::{$method} falló", [
            'status'  => $response->status(),
            'body'    => $response->body(),
            'payload' => $payload,
        ]);

        throw new RuntimeException("Pterodactyl [{$method}]: {$message}");
    }

    private function assertClientApiOk(Response $response, string $method): void
    {
        if ($response->successful()) return;

        $message = $this->extractErrorMessage($response);

        Log::error("PterodactylService::{$method} falló", [
            'status' => $response->status(),
            'body'   => $response->body(),
        ]);

        throw new PterodactylApiException($message, $response->status());
    }

    private function extractSignedUrl(Response $response, string $method): string
    {
        $url = $response->json('attributes.url')
            ?? $response->json('data.url')
            ?? $response->json('url');

        if (is_string($url) && $url !== '') {
            return $url;
        }

        Log::error("PterodactylService::{$method} no devolvió URL firmada", [
            'body' => $response->body(),
        ]);

        throw new PterodactylApiException('El panel no devolvió una URL válida.', 502);
    }

    private function extractErrorMessage(Response $response): string
    {
        $errors = $response->json('errors', []);
        $detail = $errors[0]['detail'] ?? $errors[0]['status'] ?? null;

        return $response->json('message')
            ?? $detail
            ?? "HTTP {$response->status()}";
    }

    private function sanitizeUsername(string $email): string
    {
        // Pterodactyl username: solo letras, números, guiones y puntos, max 255
        $base = strtolower(explode('@', $email)[0]);
        $clean = preg_replace('/[^a-z0-9._-]/', '', $base);
        return substr($clean ?: 'user' . rand(1000, 9999), 0, 255);
    }
}
