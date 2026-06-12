<?php

namespace App\Domains\Platform\Git;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Cliente de la GitHub App: JWT de app (RS256) → token de instalación (1h,
 * cacheado 50 min) → API de repos/contents/branches.
 *
 * El JWT se firma a mano con openssl en vez de firebase/php-jwt: esa librería
 * solo está en vendor como dependencia transitiva de Socialite y usarla
 * directamente sin declararla es frágil.
 */
class GitHubAppClient
{
    private string $apiBase;
    private int $timeout;

    public function __construct()
    {
        $this->apiBase = config('github.api_base');
        $this->timeout = (int) config('github.timeout', 15);
    }

    // ── Autenticación ─────────────────────────────────────────────────────

    /** JWT de la App (RS256, 9 min de vida — GitHub permite máx 10). */
    public function appJwt(): string
    {
        $appId = config('github.app_id');
        if (! $appId) {
            throw new RuntimeException('GITHUB_APP_ID no configurado.');
        }

        $b64 = fn (string $d) => rtrim(strtr(base64_encode($d), '+/', '-_'), '=');

        $header  = $b64(json_encode(['typ' => 'JWT', 'alg' => 'RS256']));
        // iat con 60s de margen por clock drift contra GitHub.
        $payload = $b64(json_encode([
            'iat' => time() - 60,
            'exp' => time() + 540,
            'iss' => (string) $appId,
        ]));

        $key = $this->privateKey();
        if (openssl_sign("{$header}.{$payload}", $signature, $key, OPENSSL_ALGO_SHA256) !== true) {
            throw new RuntimeException('No se pudo firmar el JWT de la GitHub App.');
        }

        return "{$header}.{$payload}." . $b64($signature);
    }

    private function privateKey(): \OpenSSLAsymmetricKey
    {
        $configured = trim((string) config('github.private_key_base64'));

        if ($configured === '') {
            throw new RuntimeException('Llave privada de la GitHub App ausente (GITHUB_APP_PRIVATE_KEY_BASE64).');
        }

        $pem = $this->resolvePem($configured);
        $key = $pem ? openssl_pkey_get_private($pem) : false;

        if ($key === false) {
            throw new RuntimeException(
                'Llave privada de la GitHub App inválida. Acepta: ruta a un .pem (absoluta o '
                . 'relativa a la raíz del proyecto), el PEM en crudo, o el PEM en base64.'
            );
        }

        return $key;
    }

    /**
     * Resuelve la llave privada desde el valor configurado. Soporta tres formas
     * para no atar al operador a una sola: ruta a archivo .pem, PEM en crudo o
     * base64 del PEM. Devuelve el PEM listo para openssl, o null si no resuelve.
     */
    private function resolvePem(string $value): ?string
    {
        // 1) PEM en crudo directamente en el env.
        if (str_contains($value, '-----BEGIN')) {
            return $value;
        }

        // 2) Ruta a un archivo .pem (absoluta, relativa al CWD, o a la raíz).
        $path = is_file($value) ? $value : (is_file(base_path($value)) ? base_path($value) : null);
        if ($path !== null) {
            $contents = trim((string) file_get_contents($path));

            return str_contains($contents, '-----BEGIN')
                ? $contents
                : (base64_decode($contents, true) ?: null);
        }

        // 3) base64 del PEM en el env.
        return base64_decode($value, true) ?: null;
    }

    /**
     * Token de instalación (válido 1h en GitHub; cacheado 50 min para no
     * regenerar en cada request y dejar margen antes de expirar).
     */
    public function installationToken(int $installationId): string
    {
        return Cache::remember(
            "github:installation_token:{$installationId}",
            now()->addMinutes(50),
            function () use ($installationId) {
                $response = $this->appHttp()
                    ->post("/app/installations/{$installationId}/access_tokens");

                $this->assertOk($response, 'installationToken');

                return $response->json('token');
            }
        );
    }

    // ── API con JWT de app ────────────────────────────────────────────────

    /** Metadata de una instalación (cuenta, permisos). */
    public function getInstallation(int $installationId): array
    {
        $response = $this->appHttp()->get("/app/installations/{$installationId}");

        $this->assertOk($response, 'getInstallation');

        return $response->json();
    }

    // ── API con token de instalación ──────────────────────────────────────

    /**
     * Repos accesibles para la instalación. GitHub no soporta búsqueda en
     * este endpoint, así que el filtro se aplica client-side sobre la página.
     */
    public function listRepositories(int $installationId, int $page = 1, int $perPage = 30, ?string $search = null): array
    {
        $response = $this->installationHttp($installationId)
            ->get('/installation/repositories', ['page' => $page, 'per_page' => $perPage]);

        $this->assertOk($response, 'listRepositories');

        $repos = collect($response->json('repositories') ?? [])
            ->when($search, fn ($c) => $c->filter(
                fn ($r) => stripos($r['full_name'] ?? '', $search) !== false
            ))
            ->map(fn ($r) => [
                'full_name'      => $r['full_name'],
                'private'        => $r['private'] ?? false,
                'default_branch' => $r['default_branch'] ?? 'main',
                'language'       => $r['language'] ?? null,
                'pushed_at'      => $r['pushed_at'] ?? null,
            ])
            ->values()
            ->all();

        return [
            'total_count'  => (int) ($response->json('total_count') ?? count($repos)),
            'repositories' => $repos,
        ];
    }

    public function listBranches(int $installationId, string $repoFullName): array
    {
        $response = $this->installationHttp($installationId)
            ->get("/repos/{$repoFullName}/branches", ['per_page' => 100]);

        $this->assertOk($response, 'listBranches');

        return collect($response->json() ?? [])
            ->map(fn ($b) => ['name' => $b['name']])
            ->all();
    }

    /** Contenido de un archivo (contents API). Null si no existe (404). */
    public function getFileContent(int $installationId, string $repoFullName, string $path, ?string $ref = null): ?string
    {
        $response = $this->installationHttp($installationId)
            ->get("/repos/{$repoFullName}/contents/{$path}", array_filter(['ref' => $ref]));

        if ($response->status() === 404) {
            return null;
        }

        $this->assertOk($response, "getFileContent({$path})");

        $body = $response->json();

        // El endpoint devuelve un array cuando el path es un directorio.
        if (! is_array($body) || ! isset($body['content'])) {
            return null;
        }

        return base64_decode($body['content'], true) ?: null;
    }

    /** Nombres de archivos/dirs en la raíz del repo. */
    public function listRootFiles(int $installationId, string $repoFullName, ?string $ref = null): array
    {
        $response = $this->installationHttp($installationId)
            ->get("/repos/{$repoFullName}/contents/", array_filter(['ref' => $ref]));

        if ($response->status() === 404) {
            return [];
        }

        $this->assertOk($response, 'listRootFiles');

        return collect($response->json() ?? [])
            ->map(fn ($f) => $f['name'] ?? null)
            ->filter()
            ->values()
            ->all();
    }

    // ── Helpers HTTP ──────────────────────────────────────────────────────

    private function appHttp(): PendingRequest
    {
        return Http::baseUrl($this->apiBase)
            ->timeout($this->timeout)
            ->withToken($this->appJwt(), 'Bearer')
            ->acceptJson()
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28']);
    }

    private function installationHttp(int $installationId): PendingRequest
    {
        return Http::baseUrl($this->apiBase)
            ->timeout($this->timeout)
            ->withToken($this->installationToken($installationId), 'Bearer')
            ->acceptJson()
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28']);
    }

    private function assertOk(Response $response, string $operation): void
    {
        if ($response->failed()) {
            throw new RuntimeException(
                "GitHub API {$operation} falló (HTTP {$response->status()}): "
                . substr($response->body(), 0, 300)
            );
        }
    }
}
