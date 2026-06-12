<?php

namespace App\Domains\Platform\Compute\Enums;

/**
 * Tier comercial del equipo. Los límites concretos (apps, RAM, build minutes)
 * se resuelven en config/compute.php para poder ajustarlos sin migración.
 */
enum PlanTier: string
{
    case Free    = 'free';
    case Starter = 'starter';
    case Pro     = 'pro';
    case Team    = 'team';
    case Agency  = 'agency';

    /** @return string[] */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
