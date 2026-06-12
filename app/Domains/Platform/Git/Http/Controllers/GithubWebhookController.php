<?php

namespace App\Domains\Platform\Git\Http\Controllers;

use App\Domains\Platform\Compute\Models\GithubInstallation;
use App\Domains\Platform\Git\Jobs\HandlePullRequestEvent;
use App\Domains\Platform\Git\Jobs\HandlePushEvent;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Receptor de webhooks de la GitHub App (POST /api/webhooks/github).
 *
 * Toda entrega se verifica con HMAC-SHA256 (X-Hub-Signature-256) antes de
 * tocar nada. Las filas de github_installations las CREA solo el flujo de
 * claim (que conoce el equipo); el webhook únicamente las mantiene
 * (suspend/unsuspend/delete) — una instalación nunca llega aquí primero
 * con un equipo válido.
 */
class GithubWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $this->verifySignature($request);

        $event   = (string) $request->header('X-GitHub-Event', '');
        $payload = $request->json()->all();

        match ($event) {
            'installation' => $this->handleInstallation($payload),
            'push'         => $this->handlePush($payload),
            'pull_request' => $this->handlePullRequest($payload),
            'ping'         => null,
            default        => Log::debug("GitHub webhook ignorado: {$event}"),
        };

        return response()->json(['success' => true], 202);
    }

    private function verifySignature(Request $request): void
    {
        $secret = (string) config('github.webhook_secret');
        $header = (string) $request->header('X-Hub-Signature-256', '');

        $expected = 'sha256=' . hash_hmac('sha256', $request->getContent(), $secret);

        if ($secret === '' || ! hash_equals($expected, $header)) {
            abort(401, 'Firma de webhook inválida.');
        }
    }

    private function handleInstallation(array $payload): void
    {
        $installationId = (int) ($payload['installation']['id'] ?? 0);
        $action         = $payload['action'] ?? '';

        $installation = GithubInstallation::where('installation_id', $installationId)->first();

        if (! $installation) {
            // Instalación aún no reclamada por ningún equipo — nada que mantener.
            return;
        }

        match ($action) {
            'deleted'   => $installation->delete(),
            'suspend'   => $installation->update(['suspended_at' => now()]),
            'unsuspend' => $installation->update(['suspended_at' => null]),
            default     => null,
        };
    }

    private function handlePush(array $payload): void
    {
        $installationId = (int) ($payload['installation']['id'] ?? 0);
        $repoFullName   = $payload['repository']['full_name'] ?? null;
        $ref            = $payload['ref'] ?? ''; // "refs/heads/main"

        if (! $installationId || ! $repoFullName || ! str_starts_with($ref, 'refs/heads/')) {
            return;
        }

        HandlePushEvent::dispatch(
            $installationId,
            $repoFullName,
            substr($ref, strlen('refs/heads/')),
            $payload['after'] ?? null,
            $payload['head_commit']['message'] ?? null,
        );
    }

    private function handlePullRequest(array $payload): void
    {
        $installationId = (int) ($payload['installation']['id'] ?? 0);
        $repoFullName   = $payload['repository']['full_name'] ?? null;
        $action         = (string) ($payload['action'] ?? '');
        $prNumber       = (int) ($payload['number'] ?? 0);
        $head           = $payload['pull_request']['head'] ?? [];

        if (! $installationId || ! $repoFullName || ! $prNumber || ($head['ref'] ?? null) === null) {
            return;
        }

        HandlePullRequestEvent::dispatch(
            $installationId,
            $repoFullName,
            $action,
            $prNumber,
            (string) $head['ref'],
            $head['sha'] ?? null,
            $payload['pull_request']['title'] ?? null,
        );
    }
}
