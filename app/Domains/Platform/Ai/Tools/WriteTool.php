<?php

namespace App\Domains\Platform\Ai\Tools;

use App\Models\User;

/**
 * Herramienta que produce un efecto secundario (deploy, env var, rollback…).
 *
 * NUNCA se ejecuta dentro del loop del agente: el AgentRunner persiste la
 * solicitud como AiAction (status `proposed`) y `execute()` corre solo cuando
 * el usuario confirma desde el panel. La autorización (policy `operate`) se
 * verifica DOS veces — al proponer (preview) y al ejecutar — defensa en
 * profundidad: una propuesta vieja no puede ejecutarse si el acceso cambió.
 */
interface WriteTool extends Tool
{
    /**
     * Valida la propuesta y devuelve un resumen legible para confirmación.
     * No realiza ningún cambio. El resumen NUNCA incluye valores de secretos.
     *
     * @return array{ok: bool, summary?: string, error?: string}
     */
    public function preview(User $user, array $arguments): array;
}
