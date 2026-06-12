<?php

namespace App\Domains\Platform\Compute\Providers\Coolify;

use App\Domains\Platform\Compute\Models\Project;
use App\Domains\Platform\Compute\Models\Resource;
use App\Domains\Platform\Compute\Providers\Contracts\AppRuntimeDriver;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Driver de Coolify v4 para el plano de cómputo.
 *
 * Cliente HTTP propio (misma config que CoolifyService) en vez de extender
 * el servicio legacy de hosting: ese path sigue sirviendo a HestiaCP-style
 * sites con docker image y no se quiere desestabilizar. Mapping:
 * ROKE project → Coolify project; ROKE environment → Coolify environment;
 * ROKE resource → Coolify application.
 */
class CoolifyDriver implements AppRuntimeDriver
{
    private string $baseUrl;
    private string $serverUuid;

    public function __construct()
    {
        $this->baseUrl    = rtrim((string) config('coolify.base_url', config('coolify.url', '')), '/');
        $this->serverUuid = (string) config('coolify.server_uuid', '');
    }

    public function ensureProject(Project $project): string
    {
        $existing = $project->provider_meta['coolify_project_uuid'] ?? null;
        if ($existing) {
            return $existing;
        }

        $response = $this->http()->post('/api/v1/projects', [
            'name'        => "{$project->team->slug}--{$project->slug}",
            'description' => "ROKE project {$project->uuid}",
        ]);

        $this->assertOk($response, 'ensureProject');

        $uuid = $response->json('uuid');

        $project->update([
            'provider_meta' => array_merge($project->provider_meta ?? [], [
                'coolify_project_uuid' => $uuid,
            ]),
        ]);

        return $uuid;
    }

    public function createApplication(Resource $resource, array $config): string
    {
        $project = $resource->environment->project;

        $payload = [
            'project_uuid'     => $this->ensureProject($project),
            'server_uuid'      => $this->serverUuid,
            'environment_name' => $config['environment_name'] ?? 'production',
            'name'             => $resource->name,
            'git_repository'   => $config['git_url'],
            'git_branch'       => $config['branch'] ?? 'main',
            'build_pack'       => $config['build_pack'] ?? 'nixpacks',
            'ports_exposes'    => (string) ($config['port'] ?? 8080),
            'instant_deploy'   => false,
        ];

        $response = $this->http()->post('/api/v1/applications/public', $payload);

        $this->assertOk($response, 'createApplication');

        return $response->json('uuid');
    }

    public function updateGitRepository(string $appId, string $gitUrl, string $branch): void
    {
        $response = $this->http()->patch("/api/v1/applications/{$appId}", [
            'git_repository' => $gitUrl,
            'git_branch'     => $branch,
        ]);

        $this->assertOk($response, 'updateGitRepository');
    }

    public function syncEnvVars(string $appId, array $vars): void
    {
        if ($vars === []) {
            return;
        }

        $response = $this->http()->patch("/api/v1/applications/{$appId}/envs/bulk", [
            'data' => collect($vars)->map(fn ($value, $key) => [
                'key'        => $key,
                'value'      => (string) $value,
                'is_preview' => false,
            ])->values()->all(),
        ]);

        $this->assertOk($response, 'syncEnvVars');
    }

    public function setDomain(string $appId, string $fqdn): void
    {
        $response = $this->http()->patch("/api/v1/applications/{$appId}", [
            'domains' => "https://{$fqdn}",
        ]);

        $this->assertOk($response, 'setDomain');
    }

    public function triggerDeploy(string $appId): string
    {
        $response = $this->http()->post('/api/v1/deploy', ['uuid' => $appId, 'force' => false]);

        $this->assertOk($response, 'triggerDeploy');

        // v4 responde {"deployments": [{"deployment_uuid": ...}]}; versiones
        // previas devolvían el uuid plano — tolerar ambas.
        $deploymentUuid = $response->json('deployments.0.deployment_uuid')
            ?? $response->json('deployment_uuid');

        if (! $deploymentUuid) {
            throw new RuntimeException('Coolify no devolvió deployment_uuid en triggerDeploy.');
        }

        return $deploymentUuid;
    }

    public function getDeployment(string $deploymentId): array
    {
        $response = $this->http()->get("/api/v1/deployments/{$deploymentId}");

        $this->assertOk($response, 'getDeployment');

        $status = (string) $response->json('status', 'queued');

        // Normalización de estados v4 → contrato del driver.
        $normalized = match ($status) {
            'queued'                            => 'queued',
            'in_progress'                       => 'in_progress',
            'finished'                          => 'finished',
            'failed', 'cancelled-by-user'       => 'failed',
            default                             => 'in_progress',
        };

        return [
            'status' => $normalized,
            'logs'   => $this->flattenLogs($response->json('logs')),
        ];
    }

    public function startApplication(string $appId): void
    {
        $this->assertOk($this->http()->post("/api/v1/applications/{$appId}/start"), 'startApplication');
    }

    public function stopApplication(string $appId): void
    {
        $this->assertOk($this->http()->post("/api/v1/applications/{$appId}/stop"), 'stopApplication');
    }

    public function restartApplication(string $appId): void
    {
        $this->assertOk($this->http()->post("/api/v1/applications/{$appId}/restart"), 'restartApplication');
    }

    public function deleteApplication(string $appId): void
    {
        $response = $this->http()->delete("/api/v1/applications/{$appId}");

        if ($response->status() !== 404) { // ya borrada = idempotente
            $this->assertOk($response, 'deleteApplication');
        }
    }

    /**
     * Coolify guarda logs como JSON de líneas [{output: "..."}]; el contrato
     * del driver entrega texto plano acumulado.
     */
    private function flattenLogs(mixed $logs): string
    {
        if (is_string($logs)) {
            $decoded = json_decode($logs, true);
            if (! is_array($decoded)) {
                return $logs;
            }
            $logs = $decoded;
        }

        if (! is_array($logs)) {
            return '';
        }

        return collect($logs)
            ->map(fn ($line) => is_array($line) ? ($line['output'] ?? '') : (string) $line)
            ->filter()
            ->implode("\n");
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
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
