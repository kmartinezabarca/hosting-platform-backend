<?php

namespace App\Domains\Platform\Compute\Detection;

/**
 * Abstracción de lectura de archivos de un repo para el motor de detección.
 * La implementación real lee de la API de GitHub (GithubRepoFiles); los
 * tests usan ArrayRepoFiles sin red.
 */
interface RepoFiles
{
    public function exists(string $path): bool;

    public function content(string $path): ?string;

    /** Decodifica un archivo JSON; null si no existe o es inválido. */
    public function json(string $path): ?array;

    /** @return string[] nombres de archivos/directorios en la raíz */
    public function rootFiles(): array;
}
