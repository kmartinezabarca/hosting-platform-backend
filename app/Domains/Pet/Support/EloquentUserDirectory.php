<?php

namespace App\Domains\Pet\Support;

use App\Domains\Pet\Contracts\UserDirectory;
use App\Models\User;

/**
 * Implementación actual: hosting y roke.pet comparten la tabla `users` (misma
 * BD mysql), así que resolvemos vía el modelo Eloquent User. El día que roke.pet
 * sea otra entidad, esta clase se reemplaza por una que consulte el proveedor de
 * identidad externo (API/SSO) sin cambiar el dominio.
 */
class EloquentUserDirectory implements UserDirectory
{
    public function getEmail(string $uuid): ?string
    {
        return User::where('uuid', $uuid)->value('email');
    }
}
