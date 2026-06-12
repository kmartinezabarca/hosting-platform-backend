<?php

namespace App\Domains\Platform\Compute\Http\Controllers\V2;

use App\Domains\Platform\Compute\Detection\DetectionEngine;
use App\Domains\Platform\Compute\Detection\GithubRepoFiles;
use App\Domains\Platform\Compute\Enums\EnvironmentType;
use App\Domains\Platform\Compute\Http\Requests\StoreProjectRequest;
use App\Domains\Platform\Compute\Jobs\AnalyzeProjectRepo;
use App\Domains\Platform\Compute\Models\GithubInstallation;
use App\Domains\Platform\Compute\Models\Project;
use App\Domains\Platform\Compute\Models\Team;
use App\Domains\Platform\Git\GitHubAppClient;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProjectController extends Controller
{
    /**
     * GET /api/v2/projects?team={uuid} — proyectos visibles para el usuario.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Project::forUser($request->user())
            ->with('team:id,uuid,name')
            ->whereNull('archived_at')
            ->orderByDesc('updated_at');

        if ($teamUuid = $request->query('team')) {
            $query->whereHas('team', fn ($q) => $q->where('uuid', $teamUuid));
        }

        return response()->json([
            'success' => true,
            'data'    => $query->get()->map(fn (Project $p) => $this->transform($p)),
        ]);
    }

    /**
     * POST /api/v2/projects — crea proyecto + ambiente production por defecto.
     */
    public function store(StoreProjectRequest $request): JsonResponse
    {
        /** @var Team $team */
        $team = Team::where('uuid', $request->validated('team'))->firstOrFail();

        $this->authorize('create', [Project::class, $team]);

        // La instalación de GitHub debe pertenecer al mismo equipo.
        $installation = null;
        if ($installationId = $request->validated('github_installation')) {
            $installation = GithubInstallation::where('id', $installationId)
                ->where('team_id', $team->id)
                ->first();

            if (! $installation) {
                abort(422, 'La instalación de GitHub no pertenece a este equipo.');
            }
        }

        $project = DB::transaction(function () use ($request, $team, $installation) {
            $project = Project::create([
                'team_id'                => $team->id,
                'name'                   => $request->validated('name'),
                'slug'                   => $this->uniqueSlug($team, $request->validated('name')),
                'repo_full_name'         => $request->validated('repo_full_name'),
                'default_branch'         => $request->validated('default_branch'),
                'github_installation_id' => $installation?->id,
            ]);

            // Todo proyecto nace con production; staging/previews se crean
            // explícitamente (o por la GitHub App en PRs).
            $project->environments()->create([
                'name' => 'Production',
                'slug' => 'production',
                'type' => EnvironmentType::Production,
            ]);

            return $project;
        });

        // Con repo conectado, la detección arranca sola en background; el
        // frontend hace polling a show() hasta que detected_stack aparezca.
        if ($project->repo_full_name && $project->github_installation_id) {
            AnalyzeProjectRepo::dispatch($project->id);
        }

        return response()->json([
            'success' => true,
            'data'    => $this->transform($project->fresh(['team', 'environments'])),
        ], 201);
    }

    /**
     * POST /api/v2/projects/{project}/analyze — re-detección en línea.
     */
    public function analyze(Request $request, Project $project, GitHubAppClient $github, DetectionEngine $engine): JsonResponse
    {
        $this->authorize('update', $project);

        if (! $project->repo_full_name || ! $project->githubInstallation) {
            abort(422, 'El proyecto no tiene repositorio de GitHub conectado.');
        }

        $stack = $engine->detect(new GithubRepoFiles(
            $github,
            $project->githubInstallation->installation_id,
            $project->repo_full_name,
            $project->default_branch,
        ));

        $project->update(['detected_stack' => $stack]);

        return response()->json([
            'success' => true,
            'data'    => ['detected_stack' => $stack],
        ]);
    }

    /**
     * GET /api/v2/projects/{project} — binding por uuid (HasUuidColumn).
     */
    public function show(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $project->load(['team:id,uuid,name', 'environments']);

        return response()->json([
            'success' => true,
            'data'    => $this->transform($project, detailed: true),
        ]);
    }

    private function transform(Project $project, bool $detailed = false): array
    {
        $data = [
            'uuid'           => $project->uuid,
            'name'           => $project->name,
            'slug'           => $project->slug,
            'repo_full_name' => $project->repo_full_name,
            'default_branch' => $project->default_branch,
            'team'           => $project->relationLoaded('team') ? [
                'uuid' => $project->team->uuid,
                'name' => $project->team->name,
            ] : null,
            'created_at'     => $project->created_at,
            'updated_at'     => $project->updated_at,
        ];

        if ($detailed) {
            $data['detected_stack'] = $project->detected_stack;
            $data['environments']   = $project->environments->map(fn ($env) => [
                'uuid'        => $env->uuid,
                'name'        => $env->name,
                'slug'        => $env->slug,
                'type'        => $env->type,
                'branch'      => $env->branch,
                'auto_deploy' => $env->auto_deploy,
            ]);
        }

        return $data;
    }

    private function uniqueSlug(Team $team, string $name): string
    {
        $base = Str::slug($name) ?: 'project';
        $slug = $base;
        $i    = 2;

        while ($team->projects()->where('slug', $slug)->exists()) {
            $slug = $base . '-' . $i++;
        }

        return $slug;
    }
}
