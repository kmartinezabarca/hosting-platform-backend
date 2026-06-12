<?php

namespace App\Domains\Platform\Ai\Tools;

use App\Domains\Platform\Ai\Troubleshooting\FailureClassifier;
use App\Domains\Platform\Compute\Enums\DeploymentStatus;
use App\Domains\Platform\Compute\Enums\DeploymentTrigger;
use App\Domains\Platform\Compute\Enums\ResourceStatus;
use App\Domains\Platform\Compute\Models\Deployment;
use App\Domains\Platform\Compute\Orchestrator\Flows\DeployFlow;
use App\Domains\Platform\Compute\Orchestrator\OrchestrationService;
use App\Models\User;

/**
 * "Apply fix" (mes 2): aplica la remediación determinista que la taxonomía de
 * fallas marca como auto-fixable. Solo cubre fixes de altísima confianza y sin
 * pérdida de datos; el resto requiere intervención manual del usuario.
 * Requiere confirmación antes de ejecutarse (igual que toda escritura).
 */
class ApplyFix implements WriteTool
{
    /** Códigos de auto_fix → descripción legible del efecto. */
    private const FIX_LABELS = [
        'generate_app_key' => 'Generar una APP_KEY nueva y volver a desplegar',
    ];

    public function __construct(
        private readonly FailureClassifier $classifier,
        private readonly OrchestrationService $orchestrator,
    ) {
    }

    public function name(): string
    {
        return 'apply_fix';
    }

    public function description(): string
    {
        return 'Aplica el fix automático de un deployment fallido cuando el diagnóstico marca can_auto_fix=true. '
            . 'Requiere confirmación del usuario. Llama antes a diagnose_failure; si no es auto-fixable, no la uses.';
    }

    public function tier(): ToolTier
    {
        return ToolTier::SafeWrite;
    }

    public function schema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'deployment' => ['type' => 'string', 'description' => 'UUID del deployment fallido a reparar'],
            ],
            'required'   => ['deployment'],
        ];
    }

    public function preview(User $user, array $arguments): array
    {
        $resolved = $this->resolve($user, $arguments);
        if (is_string($resolved)) {
            return ['ok' => false, 'error' => $resolved];
        }

        [$deployment, $code] = $resolved;

        if ($code === null) {
            return ['ok' => false, 'error' => 'Esta falla no tiene un fix automático; requiere tu intervención manual.'];
        }

        $label = self::FIX_LABELS[$code];

        return [
            'ok'      => true,
            'summary' => "{$label} «{$deployment->resource->name}».",
        ];
    }

    public function execute(User $user, array $arguments): array
    {
        $resolved = $this->resolve($user, $arguments);
        if (is_string($resolved)) {
            return ['error' => $resolved];
        }

        [$deployment, $code] = $resolved;

        return match ($code) {
            'generate_app_key' => $this->generateAppKey($user, $deployment),
            default            => ['error' => 'Esta falla no tiene un fix automático.'],
        };
    }

    /**
     * Genera una APP_KEY válida de Laravel, la fija como variable secreta y
     * lanza un redeploy. El valor jamás se devuelve.
     */
    private function generateAppKey(User $user, Deployment $deployment): array
    {
        $resource = $deployment->resource;

        $resource->environment->envVars()->updateOrCreate(
            ['key' => 'APP_KEY'],
            [
                'value_encrypted' => 'base64:' . base64_encode(random_bytes(32)),
                'is_secret'       => true,
                'source'          => 'ai',
            ],
        );

        $redeploy = $resource->deployments()->create([
            'trigger'              => DeploymentTrigger::Ai,
            'status'               => DeploymentStatus::Queued,
            'branch'               => $deployment->branch
                ?? $resource->environment->branch
                ?? $resource->environment->project->default_branch,
            'initiated_by_user_id' => $user->id,
            'initiated_by_ai'      => true,
        ]);

        $orchestration = $this->orchestrator->start(DeployFlow::key(), $resource, $redeploy);

        return [
            'ok'            => true,
            'applied'       => 'generate_app_key',
            'deployment'    => $redeploy->uuid,
            'orchestration' => $orchestration->uuid,
        ];
    }

    /**
     * @return array{0: Deployment, 1: ?string}|string
     *   [deployment, código auto_fix|null] o mensaje de error.
     */
    private function resolve(User $user, array $arguments): array|string
    {
        $deployment = Deployment::with('resource.environment.project')
            ->where('uuid', $arguments['deployment'] ?? '')
            ->first();

        if (! $deployment || ! $user->can('view', $deployment->resource)) {
            return 'Deployment no encontrado o sin acceso.';
        }
        if (! $user->can('operate', $deployment->resource)) {
            return 'No tienes permisos para aplicar fixes en este recurso.';
        }
        if (! in_array($deployment->resource->status, [ResourceStatus::Running, ResourceStatus::Stopped, ResourceStatus::Degraded, ResourceStatus::Failed], true)) {
            return 'El recurso aún se está aprovisionando; no se puede reparar todavía.';
        }

        $logs   = $deployment->logs()->orderBy('seq')->pluck('chunk')->implode('');
        $window = $this->classifier->errorWindow($logs);
        $code   = $this->classifier->classify($window)['auto_fix'];

        return [$deployment, $code];
    }
}
