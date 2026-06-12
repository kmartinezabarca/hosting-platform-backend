<?php

namespace App\Domains\Platform\Compute\Detection;

/**
 * RepoFiles en memoria para tests: ['composer.json' => '{"require":{}}', …]
 */
class ArrayRepoFiles implements RepoFiles
{
    /** @param array<string, string> $files path → contenido */
    public function __construct(private readonly array $files = [])
    {
    }

    public function exists(string $path): bool
    {
        return array_key_exists($path, $this->files);
    }

    public function content(string $path): ?string
    {
        return $this->files[$path] ?? null;
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
        return array_values(array_unique(array_map(
            fn (string $path) => explode('/', $path, 2)[0],
            array_keys($this->files)
        )));
    }
}
