<?php

namespace App\Domains\Platform\Compute\Enums;

enum DeploymentTrigger: string
{
    case Push     = 'push';
    case Manual   = 'manual';
    case Rollback = 'rollback';
    case Ai       = 'ai';
    case PrOpen   = 'pr_open';
    case PrSync   = 'pr_sync';

    /** @return string[] */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
