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
     * POST /api/v2/environments/{environment}/env-vars/import
     *
     * Importa un .env crudo en bloque: parsea KEY=VALUE (soporta comillas,
     * comentarios y `export`) y hace upsert. Marca cada clave como secreta por
     * heurística del nombre (sobreescribible con default_secret). Nunca devuelve
     * valores de secretos. Aplica en el próximo deploy.
     */
    public function import(Request $request, Environment $environment): JsonResponse
    {
        $this->authorize('update', $environment->project);

        $validated = $request->validate([
            'contents'       => ['required', 'string', 'max:65536'],
            'default_secret' => ['sometimes', 'boolean'],
            'overwrite'      => ['sometimes', 'boolean'], // false → no pisa claves existentes
        ]);

        $parsed = $this->parseDotenv($validated['contents']);

        if ($parsed === []) {
            return response()->json(['success' => false, 'message' => 'No se encontraron variables válidas en el contenido.'], 422);
        }
        if (count($parsed) > 200) {
            abort(422, 'Demasiadas variables (máximo 200 por importación).');
        }

        $overwrite = (bool) ($validated['overwrite'] ?? true);
        $existing  = $environment->envVars()->pluck('key')->all();

        $imported = [];
        $skipped  = [];

        foreach ($parsed as $key => $value) {
            if (! $overwrite && in_array($key, $existing, true)) {
                $skipped[] = $key;
                continue;
            }

            $isSecret = array_key_exists('default_secret', $validated)
                ? (bool) $validated['default_secret']
                : $this->looksSecret($key);

            $environment->envVars()->updateOrCreate(
                ['key' => $key],
                ['value_encrypted' => $value, 'is_secret' => $isSecret, 'source' => 'import'],
            );

            $imported[] = ['key' => $key, 'is_secret' => $isSecret];
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'imported'               => $imported,
                'skipped'                => $skipped,
                'count'                  => count($imported),
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

    /**
     * Parsea un .env crudo a [clave => valor]. Soporta `export`, comentarios,
     * líneas en blanco y valores entre comillas simples/dobles. La última
     * aparición de una clave gana. Claves inválidas se omiten.
     *
     * @return array<string, string>
     */
    private function parseDotenv(string $raw): array
    {
        $vars = [];

        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (str_starts_with($line, 'export ')) {
                $line = ltrim(substr($line, 7));
            }

            $eq = strpos($line, '=');
            if ($eq === false) {
                continue;
            }

            $key   = trim(substr($line, 0, $eq));
            $value = trim(substr($line, $eq + 1));

            // Quita comillas envolventes (simples o dobles).
            if (strlen($value) >= 2) {
                $first = $value[0];
                $last  = $value[strlen($value) - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = substr($value, 1, -1);
                }
            }

            if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
                continue;
            }

            $vars[$key] = $value;
        }

        return $vars;
    }

    /** Heurística: ¿el nombre sugiere un secreto? (default seguro: marcar como tal). */
    private function looksSecret(string $key): bool
    {
        return (bool) preg_match(
            '/(SECRET|PASSWORD|PASSWD|PASS|TOKEN|API_?KEY|PRIVATE|CREDENTIAL|DSN|SALT|CIPHER|WEBHOOK)/i',
            $key,
        ) || (bool) preg_match('/_KEY$/i', $key);
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
