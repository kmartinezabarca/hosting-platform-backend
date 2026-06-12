<?php

namespace App\Domains\Platform\Compute\Orchestrator\Steps;

use App\Domains\Platform\Compute\Models\Orchestration;
use App\Domains\Platform\Compute\Orchestrator\Step;
use App\Domains\Platform\Compute\Orchestrator\StepResult;
use App\Domains\Platform\Compute\Providers\Contracts\AppRuntimeDriver;

/**
 * Subdominio gratuito {project}-{env}-{hash}.roke.app. La zona *.roke.app
 * es wildcard en Cloudflare apuntando al edge, así que no se crea registro
 * DNS por app — solo se configura el dominio en el runtime (Traefik emite
 * el certificado).
 */
class AttachDefaultDomain implements Step
{
    public function __construct(private readonly AppRuntimeDriver $driver)
    {
    }

    public function execute(Orchestration $orchestration): StepResult
    {
        $resource = $orchestration->resource;

        $fqdn = $resource->spec['fqdn'] ?? null;

        if ($fqdn === null) {
            $project     = $resource->environment->project;
            $environment = $resource->environment;
            // Sufijo corto del uuid: evita colisiones entre equipos con
            // slugs de proyecto iguales sin exponer ids internos.
            $suffix = substr($resource->uuid, 0, 6);

            $fqdn = "{$project->slug}-{$environment->slug}-{$suffix}." . config('compute.app_domain');

            $resource->update(['spec' => array_merge($resource->spec, ['fqdn' => $fqdn])]);
        }

        $this->driver->setDomain(
            $resource->providerRef('coolify')->external_id,
            $fqdn
        );

        return StepResult::completed();
    }
}
