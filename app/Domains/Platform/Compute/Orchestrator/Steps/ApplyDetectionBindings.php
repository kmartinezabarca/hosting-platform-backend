<?php

namespace App\Domains\Platform\Compute\Orchestrator\Steps;

use App\Domains\Platform\Compute\Enums\ResourceKind;
use App\Domains\Platform\Compute\Models\Environment;
use App\Domains\Platform\Compute\Models\Orchestration;
use App\Domains\Platform\Compute\Orchestrator\Step;
use App\Domains\Platform\Compute\Orchestrator\StepResult;

/**
 * Materializa el `env_template` del stack detectado en variables de entorno
 * (mes 2, detection bindings). Tres tipos de directiva:
 *
 *  - generate: genera un valor (p.ej. APP_KEY) UNA sola vez.
 *  - value:    fija un valor estático.
 *  - bind:     resuelve `database.*`/`redis.*` contra el data store del mismo
 *              ambiente y conecta host/credenciales reales.
 *
 * Regla de oro: nunca pisa una variable que el usuario definió (source
 * user/import/ai) — el binding solo gestiona sus propias filas (source
 * `binding`) y las claves que aún no existen. Idempotente.
 */
class ApplyDetectionBindings implements Step
{
    /** Fuentes que el usuario controla: el binding jamás las sobreescribe. */
    private const USER_OWNED = ['user', 'import', 'ai'];

    public function execute(Orchestration $orchestration): StepResult
    {
        $resource    = $orchestration->resource;
        $environment = $resource->environment;
        $template    = data_get($environment->project->detected_stack, 'env_template', []);

        if (! is_array($template) || $template === []) {
            return StepResult::completed();
        }

        $existing = $environment->envVars()->pluck('source', 'key'); // key => source

        foreach ($template as $directive) {
            $key = $directive['key'] ?? null;
            if (! $key || in_array($existing[$key] ?? null, self::USER_OWNED, true)) {
                continue; // el usuario manda
            }

            [$value, $isSecret] = $this->resolve($directive, $environment, $existing->has($key));

            if ($value === null) {
                continue; // generate ya existe, o bind sin data store disponible
            }

            $environment->envVars()->updateOrCreate(
                ['key' => $key],
                ['value_encrypted' => $value, 'is_secret' => $isSecret, 'source' => 'binding'],
            );
        }

        return StepResult::completed();
    }

    /**
     * @return array{0: ?string, 1: bool} [valor (null = omitir), is_secret]
     */
    private function resolve(array $directive, Environment $environment, bool $keyExists): array
    {
        if (isset($directive['generate'])) {
            // Se genera una sola vez; nunca se regenera (rompería sesiones/cifrado).
            return $keyExists ? [null, true] : [$this->generate($directive['generate']), true];
        }

        if (array_key_exists('value', $directive)) {
            return [(string) $directive['value'], false];
        }

        if (isset($directive['bind'])) {
            return $this->resolveBinding((string) $directive['bind'], $environment);
        }

        return [null, false];
    }

    /** Genera el valor para una directiva `generate`. */
    private function generate(string $kind): string
    {
        return match ($kind) {
            'laravel_key' => 'base64:' . base64_encode(random_bytes(32)),
            'wp_salt'     => bin2hex(random_bytes(32)), // 64 chars, como los salts de WP
            default       => bin2hex(random_bytes(16)),
        };
    }

    /**
     * Resuelve `database.host` / `redis.password` etc. contra el data store del
     * mismo ambiente. Devuelve [null, …] si todavía no hay data store listo.
     *
     * @return array{0: ?string, 1: bool}
     */
    private function resolveBinding(string $bind, Environment $environment): array
    {
        [$kindKey, $attr] = array_pad(explode('.', $bind, 2), 2, null);

        $kind = match ($kindKey) {
            'database' => ResourceKind::Database,
            'redis'    => ResourceKind::Redis,
            default    => null,
        };
        if ($kind === null || $attr === null) {
            return [null, false];
        }

        $store = $environment->resources()
            ->where('kind', $kind->value)
            ->get()
            ->first(fn ($r) => $r->connection() !== null);

        $conn = $store?->connection();
        if ($conn === null) {
            return [null, false]; // el usuario aún no creó/aprovisionó el data store
        }

        // name → nombre lógico de la base; el resto mapea 1:1.
        $value = match ($attr) {
            'name'     => $conn['database'] ?? null,
            'host'     => $conn['host'] ?? null,
            'port'     => isset($conn['port']) ? (string) $conn['port'] : null,
            'username' => $conn['username'] ?? null,
            'password' => $conn['password'] ?? null,
            default    => $conn[$attr] ?? null,
        };

        return [$value === null ? null : (string) $value, $attr === 'password'];
    }
}
