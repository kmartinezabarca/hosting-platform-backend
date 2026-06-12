<?php

namespace App\Domains\Platform\Compute\Enums;

enum ResourceKind: string
{
    case App        = 'app';
    case StaticSite = 'static_site';
    case Database   = 'database';
    case Redis      = 'redis';
    case GameServer = 'game_server';
    case Compose    = 'compose';

    /** @return string[] */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
