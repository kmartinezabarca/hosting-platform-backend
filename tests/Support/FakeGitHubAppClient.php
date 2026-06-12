<?php

namespace Tests\Support;

use App\Domains\Platform\Git\GitHubAppClient;

/**
 * Doble de GitHubAppClient para tests: evita el JWT/HTTP real. Registra los
 * comentarios creados/actualizados y devuelve un token e id ficticios.
 */
class FakeGitHubAppClient extends GitHubAppClient
{
    /** @var array<int, array{method: string, repo: string, ref: int, body: string}> */
    public array $comments = [];

    public int $nextCommentId = 9001;

    public function __construct()
    {
        // No invoca al padre: no hay config de GitHub que cargar en tests.
    }

    public function installationToken(int $installationId): string
    {
        return 'fake-installation-token';
    }

    public function createIssueComment(int $installationId, string $repoFullName, int $issueNumber, string $body): int
    {
        $this->comments[] = ['method' => 'create', 'repo' => $repoFullName, 'ref' => $issueNumber, 'body' => $body];

        return $this->nextCommentId;
    }

    public function updateIssueComment(int $installationId, string $repoFullName, int $commentId, string $body): void
    {
        $this->comments[] = ['method' => 'update', 'repo' => $repoFullName, 'ref' => $commentId, 'body' => $body];
    }

    public function called(string $method): bool
    {
        return collect($this->comments)->contains(fn ($c) => $c['method'] === $method);
    }
}
