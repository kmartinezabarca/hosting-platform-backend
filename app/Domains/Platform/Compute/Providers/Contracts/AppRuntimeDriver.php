<?php

namespace App\Domains\Platform\Compute\Providers\Contracts;

use App\Domains\Platform\Compute\Models\Project;
use App\Domains\Platform\Compute\Models\Resource;

/**
 * Contrato del runtime de aplicaciones. ÚNICA capa que habla con el
 * proveedor (Coolify hoy) — los pasos del orquestador dependen de esta
 * interfaz, nunca del proveedor concreto. Los tests la sustituyen por
 * un fake en el contenedor.
 */
interface AppRuntimeDriver
{
    /** Garantiza el proyecto en el runtime y devuelve su id externo. */
    public function ensureProject(Project $project): string;

    /**
     * Crea la aplicación para el recurso y devuelve su id externo.
     *
     * $config: git_url, branch, build_pack (nixpacks|static|dockerfile),
     * port, environment_name.
     */
    public function createApplication(Resource $resource, array $config): string;

    /** Re-apunta el repo (URL tokenizada para repos privados). */
    public function updateGitRepository(string $appId, string $gitUrl, string $branch): void;

    /** @param array<string, string> $vars upsert masivo */
    public function syncEnvVars(string $appId, array $vars): void;

    public function setDomain(string $appId, string $fqdn): void;

    /** Dispara un build/deploy y devuelve el id externo del deployment. */
    public function triggerDeploy(string $appId): string;

    /**
     * Estado de un deployment en el runtime.
     *
     * @return array{status: string, logs: string}  status normalizado:
     *         queued | in_progress | finished | failed
     */
    public function getDeployment(string $deploymentId): array;

    public function startApplication(string $appId): void;

    public function stopApplication(string $appId): void;

    public function restartApplication(string $appId): void;

    public function deleteApplication(string $appId): void;
}
