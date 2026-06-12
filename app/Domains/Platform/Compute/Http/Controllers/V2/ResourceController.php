<?php

namespace App\Domains\Platform\Compute\Http\Controllers\V2;

use App\Domains\Platform\Compute\Enums\ResourceStatus;
use App\Domains\Platform\Compute\Http\Requests\CreateResourceRequest;
use App\Domains\Platform\Compute\Models\Environment;
use App\Domains\Platform\Compute\Models\Orchestration;
use App\Domains\Platform\Compute\Models\Resource;
use App\Domains\Platform\Compute\Orchestrator\Flows\ProvisionAppFlow;
use App\Domains\Platform\Compute\Orchestrator\Flows\ProvisionDatabaseFlow;
use App\Domains\Platform\Compute\Orchestrator\OrchestrationService;
use App\Domains\Platform\Compute\Plans\PlanLimits;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ResourceController extends Controller
{
    public function __construct(
        private readonly OrchestrationService $orchestrator,
        private readonly PlanLimits $planLimits,
    ) {
    }

    /**
     * POST /api/v2/environments/{environment}/resources → 202 + orchestration.
     */
    public function store(CreateResourceRequest $request, Environment $environment): JsonResponse
    {
        $project = $environment->project;

        // Crear recursos requiere rol developer+ en el equipo del proyecto.
        $this->authorize('update', $project);

        $kind        = $request->validated('kind');
        $isDataStore = in_array($kind, ['database', 'redis'], true);

        // Las apps necesitan repo para construirse; los data stores no.
        if (! $isDataStore && ! $project->repo_full_name) {
            abort(422, 'Conecta un repositorio de GitHub al proyecto antes de crear una app.');
        }

        // Enforcement de plan: conteo de recursos y tope de RAM por recurso.
        $ramMb = (int) data_get($request->validated('spec'), 'ram_mb', 512);
        if ($error = $this->planLimits->check($project->team, $ramMb)) {
            abort(422, $error);
        }

        $resource = $environment->resources()->create([
            'kind'   => $kind,
            'name'   => $request->validated('name'),
            'status' => ResourceStatus::Creating,
            'spec'   => array_merge(
                ['ram_mb' => 512, 'cpu' => 0.5],
                $request->validated('spec') ?? [],
            ),
        ]);

        $orchestration = $this->orchestrator->start(
            $isDataStore ? ProvisionDatabaseFlow::key() : ProvisionAppFlow::key(),
            $resource,
            context: ['initiated_by_user_id' => $request->user()->id],
        );

        return response()->json([
            'success' => true,
            'data'    => [
                'resource'      => $this->transform($resource),
                'orchestration' => $this->transformOrchestration($orchestration),
            ],
        ], 202);
    }

    /**
     * GET /api/v2/resources/{resource}
     */
    public function show(Request $request, Resource $resource): JsonResponse
    {
        $this->authorize('view', $resource);

        $resource->load(['environment.project', 'deployments' => fn ($q) => $q->latest()->limit(1)]);

        return response()->json([
            'success' => true,
            'data'    => $this->transform($resource, detailed: true),
        ]);
    }

    /**
     * GET /api/v2/orchestrations/{orchestration} — progreso de la saga.
     */
    public function orchestration(Request $request, Orchestration $orchestration): JsonResponse
    {
        if ($orchestration->resource) {
            $this->authorize('view', $orchestration->resource);
        }

        return response()->json([
            'success' => true,
            'data'    => $this->transformOrchestration($orchestration),
        ]);
    }

    private function transform(Resource $resource, bool $detailed = false): array
    {
        $data = [
            'uuid'   => $resource->uuid,
            'kind'   => $resource->kind,
            'name'   => $resource->name,
            'status' => $resource->status,
            'url'    => isset($resource->spec['fqdn']) ? 'https://' . $resource->spec['fqdn'] : null,
        ];

        if ($detailed) {
            // spec es seguro de exponer (estado deseado, sin ids de proveedor).
            $data['spec']   = $resource->spec;
            $data['health'] = $resource->health;
            $data['environment'] = [
                'uuid' => $resource->environment->uuid,
                'slug' => $resource->environment->slug,
            ];
            $data['latest_deployment'] = $resource->deployments->first()
                ? $this->deploymentSummary($resource->deployments->first())
                : null;

            // Data stores: conexión sin la contraseña (write-only, como los
            // secretos de env). El usuario la consume vía detection bindings.
            if ($resource->isDataStore() && ($conn = $resource->connection())) {
                $data['connection'] = [
                    'host'     => $conn['host'] ?? null,
                    'port'     => $conn['port'] ?? null,
                    'database' => $conn['database'] ?? null,
                    'username' => $conn['username'] ?? null,
                    'password' => null, // nunca se devuelve
                ];
            }
        }

        return $data;
    }

    private function deploymentSummary($deployment): array
    {
        return [
            'uuid'        => $deployment->uuid,
            'status'      => $deployment->status,
            'trigger'     => $deployment->trigger,
            'commit_sha'  => $deployment->commit_sha,
            'branch'      => $deployment->branch,
            'created_at'  => $deployment->created_at,
            'finished_at' => $deployment->finished_at,
        ];
    }

    private function transformOrchestration(Orchestration $orchestration): array
    {
        return [
            'uuid'   => $orchestration->uuid,
            'flow'   => $orchestration->flow,
            'state'  => $orchestration->state,
            'steps'  => collect($orchestration->steps)->map(fn ($s) => [
                'step'   => class_basename($s['step']),
                'status' => $s['status'],
                'error'  => $s['error'],
            ])->values(),
            'completed_at' => $orchestration->completed_at,
            'failed_at'    => $orchestration->failed_at,
        ];
    }
}
