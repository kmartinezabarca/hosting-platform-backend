<?php

namespace App\Domains\Platform\Compute\Enums;

/**
 * Rol de un miembro dentro de un Team. La jerarquía de permisos se resuelve
 * vía atLeast() — las policies comparan rangos, no listas de roles.
 */
enum TeamRole: string
{
    case Owner     = 'owner';
    case Admin     = 'admin';
    case Developer = 'developer';
    case Billing   = 'billing';
    case Viewer    = 'viewer';

    public function rank(): int
    {
        return match ($this) {
            self::Owner     => 50,
            self::Admin     => 40,
            self::Developer => 30,
            self::Billing   => 20,
            self::Viewer    => 10,
        };
    }

    public function atLeast(self $other): bool
    {
        return $this->rank() >= $other->rank();
    }

    /** @return string[] */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
