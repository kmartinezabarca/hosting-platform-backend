<?php

namespace App\Domains\Pet\Contracts;

/**
 * Contrato de identidad para el dominio roke.pet.
 *
 * Pet comparte únicamente la tabla `users` con el hosting. En lugar de consultar
 * esa tabla con `DB::connection('mysql')` directo, el dominio depende de esta
 * abstracción. Es el "seam" para el día que roke.pet se separe a su propio
 * servicio/identidad (SSO): bastará con cambiar la implementación enlazada en
 * PetServiceProvider, sin tocar el resto del dominio.
 */
interface UserDirectory
{
    /** Devuelve el email del usuario dado su uuid, o null si no existe. */
    public function getEmail(string $uuid): ?string;
}
