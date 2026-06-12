<?php

namespace App\Domains\Platform\Compute\Http\Controllers\V2;

use App\Domains\Platform\Compute\Enums\DeploymentStatus;
use App\Domains\Platform\Compute\Enums\DeploymentTrigger;
use App\Domains\Platform\Compute\Enums\ResourceStatus;
use App\Domains\Platform\Compute\Models\Deployment;
use App\Domains\Platform\Compute\Models\Resource;
use App\Domains\Platform\Compute\Orchestrator\Flows\DeployFlow;
use App\Domains\Platform\Compute\Orchestrator\OrchestrationService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeploymentController extends Controller
{
    public function __construct(private readonly OrchestrationService $orchestrator)
    {
    }

    /**
     * GET /api/v2/resources/{resource}/deployments — historial.
     */
    public function index(Request $request, Resource $resource): JsonResponse
    {
        $this->authorize('view', $resource);

        $deployments = $resource->deployments()
            ->with('initiatedBy:id,uuid,first_name,last_name')
            ->latest()
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data'    => collect($deployments->items())->map(fn ($d) => $this->transform($d)),
            'meta'    => [
                'current_page' => $deployments->currentPage(),
                'last_page'    => $deployments->lastPage(),
                'total'        => $deployments->total(),
            ],
        ]);
    }

    /**
     * POST /api/v2/resources/{resource}/deployments — deploy manual → 202.
     */
    public function store(Request $request, Resource $resource): JsonResponse
    {
        $this->authorize('operate', $resource);

        if (! in_array($resource->status, [ResourceStatus::Running, ResourceStatus::Stopped, ResourceStatus::Degraded, ResourceStatus::Failed], true)) {
            abort(409, 'El recurso aún se está aprovisionando.');
        }

        $request->validate(['branch' => ['nullable', 'string', 'max:255']]);

        $deployment = $resource->deployments()->create([
            'trigger'              => DeploymentTrigger::Manual,
            'status'               => DeploymentStatus::Queued,
            'branch'               => $request->input('branch')
                ?? $resource->environment->branch
                ?? $resource->environment->project->default_branch,
            'initiated_by_user_id' => $request->user()->id,
        ]);

        $orchestration = $this->orchestrator->start(DeployFlow::key(), $resource, $deployment);

        return response()->json([
            'success' => true,
            'data'    => [
                'deployment'    => $this->transform($deployment),
                'orchestration' => ['uuid' => $orchestration->uuid],
            ],
        ], 202);
    }

    /**
     * GET /api/v2/deployments/{deployment}/logs?stream=build&after_seq=0
     */
    public function logs(Request $request, Deployment $deployment): JsonResponse
    {
        $this->authorize('view', $deployment->resource);

        $request->validate([
            'stream'    => ['sometimes', 'in:build,deploy,runtime'],
            'after_seq' => ['sometimes', 'integer', 'min:0'],
        ]);

        $chunks = $deployment->logs()
            ->when($request->query('stream'), fn ($q, $s) => $q->where('stream', $s))
            ->when($request->query('after_seq'), fn ($q, $seq) => $q->where('seq', '>', (int) $seq))
            ->get(['seq', 'stream', 'chunk', 'created_at']);

        return response()->json([
            'success' => true,
            'data'    => [
                'deployment' => $deployment->uuid,
                'status'     => $deployment->status,
                'chunks'     => $chunks,
            ],
        ]);
    }

    private function transform(Deployment $deployment): array
    {
        return [
            'uuid'           => $deployment->uuid,
            'trigger'        => $deployment->trigger,
            'status'         => $deployment->status,
            'commit_sha'     => $deployment->commit_sha,
            'commit_message' => $deployment->commit_message,
            'branch'         => $deployment->branch,
            'initiated_by'   => $deployment->initiatedBy?->uuid,
            'initiated_by_ai' => $deployment->initiated_by_ai,
            'build_seconds'  => $deployment->build_seconds,
            'error_summary'  => $deployment->error_summary,
            'created_at'     => $deployment->created_at,
            'finished_at'    => $deployment->finished_at,
        ];
    }
}
