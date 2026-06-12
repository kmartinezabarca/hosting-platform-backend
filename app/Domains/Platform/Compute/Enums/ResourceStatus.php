<?php

namespace App\Domains\Platform\Compute\Enums;

/**
 * Estado de un Resource. Las transiciones son responsabilidad exclusiva del
 * orquestador (OrchestrationRunner) — los controladores nunca escriben status.
 */
enum ResourceStatus: string
{
    case Creating     = 'creating';
    case Provisioning = 'provisioning';
    case Running      = 'running';
    case Stopped      = 'stopped';
    case Sleeping     = 'sleeping';   // free tier: dormido por inactividad
    case Degraded     = 'degraded';
    case Failed       = 'failed';
    case Suspended    = 'suspended';  // billing: impago
    case Deleting     = 'deleting';

    /** @return string[] */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
