<?php

namespace App\Domains\Platform\Ai\Tools;

use App\Models\User;

/**
 * Herramienta del agente de IA. Reglas (blueprint doc 03):
 *
 * 1. Se ejecuta SIEMPRE como el usuario de la conversación — la autorización
 *    pasa por las mismas policies que la API; el scoping ocurre en queries,
 *    no en el prompt.
 * 2. El resultado jamás incluye nombres/IDs de proveedores (Coolify,
 *    Pterodactyl) ni valores de env vars.
 * 3. El tier (ToolTier) decide si se auto-ejecuta (`read`) o pasa por el gate
 *    de confirmación del usuario (`safe_write`/`destructive`). Las herramientas
 *    de escritura implementan además WriteTool.
 */
interface Tool
{
    public function name(): string;

    public function description(): string;

    /** Nivel de confianza: read se auto-ejecuta; el resto requiere confirmación. */
    public function tier(): ToolTier;

    /** JSON Schema de los argumentos (formato input_schema de Anthropic). */
    public function schema(): array;

    /**
     * Ejecuta como $user. Devuelve datos serializables; los errores de
     * autorización/not-found se devuelven como ['error' => ...] para que el
     * modelo pueda explicárselos al usuario.
     */
    public function execute(User $user, array $arguments): array;
}
