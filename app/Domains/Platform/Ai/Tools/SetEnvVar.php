<?php

namespace App\Domains\Platform\Ai\Tools;

use App\Domains\Platform\Compute\Models\Resource;
use App\Models\User;

/**
 * Define o actualiza una variable de entorno en el ambiente del recurso.
 * Aplica en el próximo deploy (igual que el endpoint de env vars). El valor
 * jamás se lee de vuelta ni se incluye en el resumen de confirmación.
 */
class SetEnvVar implements WriteTool
{
    public function name(): string
    {
        return 'set_env_var';
    }

    public function description(): string
    {
        return 'Define o actualiza una variable de entorno de un recurso. Aplica en el próximo deploy. '
            . 'Requiere confirmación del usuario antes de ejecutarse. Úsala cuando falte una variable o el '
            . 'usuario pida configurarla; nunca inventes valores de secretos.';
    }

    public function tier(): ToolTier
    {
        return ToolTier::SafeWrite;
    }

    public function schema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'resource'  => ['type' => 'string', 'description' => 'UUID del recurso'],
                'key'       => ['type' => 'string', 'description' => 'Nombre de la variable (A-Z, 0-9, _)'],
                'value'     => ['type' => 'string', 'description' => 'Valor de la variable'],
                'is_secret' => ['type' => 'boolean', 'description' => '¿Es un secreto? (default true)'],
            ],
            'required'   => ['resource', 'key', 'value'],
        ];
    }

    public function preview(User $user, array $arguments): array
    {
        $resource = $this->resolveResource($user, $arguments);
        if (is_string($resource)) {
            return ['ok' => false, 'error' => $resource];
        }

        $key = (string) ($arguments['key'] ?? '');
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
            return ['ok' => false, 'error' => "Nombre de variable inválido: {$key}"];
        }

        return [
            'ok'      => true,
            // Sin valor: el secreto nunca se muestra, ni en la confirmación.
            'summary' => "Definir la variable {$key} en «{$resource->name}» (aplica en el próximo deploy).",
        ];
    }

    public function execute(User $user, array $arguments): array
    {
        $resource = $this->resolveResource($user, $arguments);
        if (is_string($resource)) {
            return ['error' => $resource];
        }

        $key = (string) ($arguments['key'] ?? '');
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
            return ['error' => "Nombre de variable inválido: {$key}"];
        }

        $resource->environment->envVars()->updateOrCreate(
            ['key' => $key],
            [
                'value_encrypted' => (string) ($arguments['value'] ?? ''),
                'is_secret'       => (bool) ($arguments['is_secret'] ?? true),
                'source'          => 'ai',
            ],
        );

        return ['ok' => true, 'key' => $key, 'applies_on_next_deploy' => true];
    }

    /** @return Resource|string Recurso, o mensaje de error. */
    private function resolveResource(User $user, array $arguments): Resource|string
    {
        $resource = Resource::with('environment')
            ->where('uuid', $arguments['resource'] ?? '')
            ->first();

        if (! $resource || ! $user->can('view', $resource)) {
            return 'Recurso no encontrado o sin acceso.';
        }
        if (! $user->can('operate', $resource)) {
            return 'No tienes permisos para modificar variables de este recurso.';
        }

        return $resource;
    }
}
