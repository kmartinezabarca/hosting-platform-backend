<?php

namespace App\Domains\Platform\Compute\Enums;

enum EnvironmentType: string
{
    case Production  = 'production';
    case Staging     = 'staging';
    case Preview     = 'preview';
    case Development = 'development';

    /** @return string[] */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
