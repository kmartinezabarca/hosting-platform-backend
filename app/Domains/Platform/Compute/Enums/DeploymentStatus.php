<?php

namespace App\Domains\Platform\Compute\Enums;

enum DeploymentStatus: string
{
    case Queued     = 'queued';
    case Building   = 'building';
    case Deploying  = 'deploying';
    case Running    = 'running';
    case Success    = 'success';
    case Failed     = 'failed';
    case Cancelled  = 'cancelled';
    case RolledBack = 'rolled_back';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Success, self::Failed, self::Cancelled, self::RolledBack], true);
    }

    /** @return string[] */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
