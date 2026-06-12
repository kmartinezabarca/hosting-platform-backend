<?php

namespace App\Domains\Platform\Ai\Tools;

/**
 * Nivel de confianza de una herramienta del agente (blueprint doc 07 §6.3,
 * "trust ladder"). `read` se auto-ejecuta; `safe_write` y `destructive`
 * pasan SIEMPRE por el gate de confirmación del usuario antes de correr.
 */
enum ToolTier: string
{
    case Read        = 'read';
    case SafeWrite   = 'safe_write';
    case Destructive = 'destructive';

    /** ¿Requiere confirmación explícita del usuario antes de ejecutarse? */
    public function requiresConfirmation(): bool
    {
        return $this !== self::Read;
    }
}
