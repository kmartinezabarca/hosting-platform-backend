<?php

namespace App\Domains\Platform\Git\Http\Controllers\V2;

use App\Domains\Platform\Compute\Enums\TeamRole;
use App\Domains\Platform\Compute\Models\GithubInstallation;
use App\Domains\Platform\Compute\Models\Team;
use App\Domains\Platform\Git\GitHubAppClient;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

class GithubController extends Controller
{
    public function __construct(private readonly GitHubAppClient $github)
    {
    }

    /**
     * GET /api/v2/github/install-url?team={uuid}
     *
     * URL de instalación de la GitHub App con `state` firmado (Crypt) que
     * liga la instalación al equipo. El usuario instala en GitHub, GitHub
     * redirige al frontend con installation_id + state, y el frontend llama
     * a /claim para completar el vínculo.
     */
    public function installUrl(Request $request): JsonResponse
    {
        $request->validate(['team' => ['required', 'uuid', 'exists:teams,uuid']]);

        $team = Team::where('uuid', $request->query('team'))->firstOrFail();

        if (! ($team->roleFor($request->user())?->atLeast(TeamRole::Admin) ?? false)) {
            abort(403, 'Solo admin/owner del equipo pueden conectar GitHub.');
        }

        $state = Crypt::encryptString(json_encode([
            'team' => $team->uuid,
            'user' => $request->user()->id,
            'exp'  => now()->addMinutes(30)->timestamp,
        ]));

        $slug = config('github.app_slug');

        return response()->json([
            'success' => true,
            'data'    => [
                'install_url' => "https://github.com/apps/{$slug}/installations/new?state=" . urlencode($state),
            ],
        ]);
    }

    /**
     * POST /api/v2/github/installations/claim { installation_id, state }
     *
     * Verifica el state firmado y liga la instalación al equipo. La metadata
     * (account_login) se lee de la API de GitHub con el JWT de la App — el
     * installation_id que manda el cliente no se confía sin validar.
     */
    public function claim(Request $request): JsonResponse
    {
        $request->validate([
            'installation_id' => ['required', 'integer'],
            'state'           => ['required', 'string'],
        ]);

        try {
            $state = json_decode(Crypt::decryptString($request->input('state')), true);
        } catch (\Throwable) {
            abort(422, 'State inválido.');
        }

        if (($state['exp'] ?? 0) < now()->timestamp) {
            abort(422, 'State expirado. Reinicia la conexión con GitHub.');
        }

        if ((int) ($state['user'] ?? 0) !== (int) $request->user()->id) {
            abort(403, 'El state pertenece a otro usuario.');
        }

        $team = Team::where('uuid', $state['team'] ?? '')->firstOrFail();

        if (! ($team->roleFor($request->user())?->atLeast(TeamRole::Admin) ?? false)) {
            abort(403);
        }

        // Confirma contra GitHub que la instalación existe y obtiene la cuenta.
        $info = $this->github->getInstallation((int) $request->input('installation_id'));

        $installation = GithubInstallation::updateOrCreate(
            ['installation_id' => (int) $request->input('installation_id')],
            [
                'team_id'       => $team->id,
                'account_login' => $info['account']['login'] ?? 'unknown',
                'suspended_at'  => null,
            ]
        );

        return response()->json([
            'success' => true,
            'data'    => $this->transform($installation),
        ], 201);
    }

    /**
     * GET /api/v2/github/installations?team={uuid}
     */
    public function installations(Request $request): JsonResponse
    {
        $request->validate(['team' => ['required', 'uuid', 'exists:teams,uuid']]);

        $team = Team::where('uuid', $request->query('team'))->firstOrFail();

        if (! $team->hasMember($request->user())) {
            abort(403);
        }

        return response()->json([
            'success' => true,
            'data'    => $team->githubInstallations->map(fn ($i) => $this->transform($i)),
        ]);
    }

    /**
     * GET /api/v2/github/installations/{installation}/repos?search=&page=
     */
    public function repos(Request $request, GithubInstallation $installation): JsonResponse
    {
        if (! $installation->team->hasMember($request->user())) {
            abort(403);
        }

        $result = $this->github->listRepositories(
            $installation->installation_id,
            max(1, (int) $request->query('page', 1)),
            30,
            $request->query('search')
        );

        return response()->json(['success' => true, 'data' => $result]);
    }

    /**
     * GET /api/v2/github/installations/{installation}/branches?repo=owner/name
     */
    public function branches(Request $request, GithubInstallation $installation): JsonResponse
    {
        if (! $installation->team->hasMember($request->user())) {
            abort(403);
        }

        $request->validate(['repo' => ['required', 'string', 'regex:#^[\w.-]+/[\w.-]+$#']]);

        return response()->json([
            'success' => true,
            'data'    => $this->github->listBranches(
                $installation->installation_id,
                $request->query('repo')
            ),
        ]);
    }

    private function transform(GithubInstallation $installation): array
    {
        return [
            'id'            => $installation->id,
            'account_login' => $installation->account_login,
            'suspended'     => $installation->suspended_at !== null,
            'connected_at'  => $installation->created_at,
        ];
    }
}
