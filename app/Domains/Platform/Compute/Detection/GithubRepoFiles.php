<?php

namespace App\Domains\Platform\Compute\Detection;

use App\Domains\Platform\Git\GitHubAppClient;

/**
 * RepoFiles respaldado por la API de contents de GitHub, con memoización
 * por path para que los detectores puedan re-preguntar sin re-fetch.
 */
class GithubRepoFiles implements RepoFiles
{
    /** @var array<string, ?string> */
    private array $cache = [];

    private ?array $rootCache = null;

    public function __construct(
        private readonly GitHubAppClient $github,
        private readonly int $installationId,
        private readonly string $repoFullName,
        private readonly ?string $ref = null,
    ) {
    }

    public function exists(string $path): bool
    {
        // Para archivos de raíz basta el listado (1 request cubre todo).
        if (! str_contains($path, '/')) {
            return in_array($path, $this->rootFiles(), true);
        }

        return $this->content($path) !== null;
    }

    public function content(string $path): ?string
    {
        if (! array_key_exists($path, $this->cache)) {
            $this->cache[$path] = $this->github->getFileContent(
                $this->installationId,
                $this->repoFullName,
                $path,
                $this->ref
            );
        }

        return $this->cache[$path];
    }

    public function json(string $path): ?array
    {
        $raw = $this->content($path);
        if ($raw === null) {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    public function rootFiles(): array
    {
        return $this->rootCache ??= $this->github->listRootFiles(
            $this->installationId,
            $this->repoFullName,
            $this->ref
        );
    }
}
