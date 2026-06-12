<?php

namespace App\Domains\Platform\Compute\Http\Controllers\V2;

use App\Domains\Platform\Compute\Models\Environment;
use App\Domains\Platform\Compute\Models\EnvVar;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Variables de entorno de un ambiente. Contrato write-only para secretos:
 * los valores marcados is_secret NUNCA se devuelven (ni enmascarados) — el
 * cliente solo ve el nombre. Los cambios aplican en el siguiente deploy
 * (SyncEnvVars corre dentro de DeployFlow/ProvisionAppFlow).
 */
class EnvVarController extends Controller
{
    /**
     * GET /api/v2/environments/{environment}/env-vars
     */
    public function index(Request $request, Environment $environment): JsonResponse
    {
        $this->authorize('view', $environment->project);

        return response()->json([
            'success' => true,
            'data'    => $environment->envVars()
                ->orderBy('key')
                ->get()
                ->map(fn (EnvVar $var) => $this->transform($var)),
        ]);
    }

    /**
     * PUT /api/v2/environments/{environment}/env-vars — upsert masivo.
     * Solo toca las claves enviadas; borrar es un endpoint explícito.
     */
    public function upsert(Request $request, Environment $environment): JsonResponse
    {
        $this->authorize('update', $environment->project);

        $validated = $request->validate([
            'vars'             => ['required', 'array', 'min:1', 'max:100'],
            'vars.*.key'       => ['required', 'string', 'max:255', 'regex:/^[A-Za-z_][A-Za-z0-9_]*$/'],
            'vars.*.value'     => ['required', 'string', 'max:8192'],
            'vars.*.is_secret' => ['sometimes', 'boolean'],
        ]);

        foreach ($validated['vars'] as $var) {
            $environment->envVars()->updateOrCreate(
                ['key' => $var['key']],
                [
                    'value_encrypted' => $var['value'],
                    'is_secret'       => (bool) ($var['is_secret'] ?? true),
                    'source'          => 'user',
                ],
            );
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'vars' => $environment->envVars()->orderBy('key')->get()
                    ->map(fn (EnvVar $var) => $this->transform($var)),
                // El runtime se actualiza en el próximo deploy.
                'applies_on_next_deploy' => true,
            ],
        ]);
    }

    /**
     * DELETE /api/v2/environments/{environment}/env-vars/{key}
     */
    public function destroy(Request $request, Environment $environment, string $key): JsonResponse
    {
        $this->authorize('update', $environment->project);

        $environment->envVars()->where('key', $key)->delete();

        return response()->json(['success' => true]);
    }

    private function transform(EnvVar $var): array
    {
        return [
            'key'       => $var->key,
            // Secretos: write-only, ni siquiera enmascarados. No-secretos
            // (APP_ENV, configuración no sensible) sí se muestran.
            'value'     => $var->is_secret ? null : (string) $var->value_encrypted,
            'is_secret' => $var->is_secret,
            'source'    => $var->source,
            'updated_at' => $var->updated_at,
        ];
    }
}
