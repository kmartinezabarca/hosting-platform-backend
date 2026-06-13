<?php

namespace App\Domains\Platform;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

/**
 * Frontera del dominio Platform (hosting, game servers, VPS, bases de datos,
 * billing, dominios). Es el núcleo de negocio de ROKE Industries; vive bajo
 * app/Domains/Platform/ y comparte con el resto solo el núcleo (User, Controller
 * base, kernels, middleware, Support, Traits, Policies) que permanece en App\.
 *
 * Las rutas de platform siguen cargándose desde RouteServiceProvider
 * (routes/api.php, auth.php, client.php, admin.php) — los controladores ya
 * apuntan a App\Domains\Platform\Http\Controllers\*.
 */
class PlatformServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Driver del runtime de apps (plano de cómputo). Los pasos del
        // orquestador dependen del contrato; los tests lo sustituyen por
        // un fake en el contenedor.
        $this->app->singleton(
            \App\Domains\Platform\Compute\Providers\Contracts\AppRuntimeDriver::class,
            \App\Domains\Platform\Compute\Providers\Coolify\CoolifyDriver::class,
        );

        // Driver de bases de datos administradas (self-service de DB, mes 2).
        $this->app->singleton(
            \App\Domains\Platform\Compute\Providers\Contracts\DatabaseDriver::class,
            \App\Domains\Platform\Compute\Providers\Coolify\CoolifyDatabaseDriver::class,
        );

        // Generador de páginas con IA (SiteBuilder). El proveedor se elige por
        // env (PAGE_GENERATOR_DRIVER) — cambiar de proveedor no toca lógica.
        // Driver inválido = falla clara al resolver, no un default silencioso.
        $this->app->singleton(
            \App\Domains\Platform\SiteBuilder\Contracts\PageGeneratorProvider::class,
            function ($app) {
                return match (config('page_generator.driver')) {
                    'ollama' => $app->make(\App\Domains\Platform\SiteBuilder\Providers\OllamaPageGenerator::class),
                    // 'claude' => $app->make(\App\Domains\Platform\SiteBuilder\Providers\ClaudePageGenerator::class), // fase 3
                    default  => throw new \InvalidArgumentException(
                        'PAGE_GENERATOR_DRIVER inválido o no soportado: ' . (config('page_generator.driver') ?? 'null')
                    ),
                };
            },
        );
    }

    public function boot(): void
    {
        // Los modelos viven en App\Domains\Platform\Models\* (no en App\Models\), así
        // que hay que enseñarle a las factories la resolución en ambos sentidos:
        //  - modelo → factory (Model::factory()): por basename → Database\Factories\XFactory.
        //  - factory → modelo (cuando la factory no fija $model): buscar el modelo en los
        //    namespaces de dominio (Platform, App\Models para User, Pet).
        Factory::guessFactoryNamesUsing(
            fn (string $model) => 'Database\\Factories\\' . class_basename($model) . 'Factory'
        );

        Factory::guessModelNamesUsing(function (Factory $factory) {
            $name = Str::replaceLast('Factory', '', class_basename($factory));
            foreach ([
                'App\\Domains\\Platform\\Models\\',
                'App\\Models\\',            // núcleo compartido (User)
                'App\\Domains\\Pet\\Models\\',
            ] as $ns) {
                if (class_exists($ns . $name)) {
                    return $ns . $name;
                }
            }
            return 'App\\Domains\\Platform\\Models\\' . $name;
        });

        // Comandos del dominio (antes auto-cargados desde app/Console/Commands).
        if ($this->app->runningInConsole()) {
            $dir = __DIR__ . '/Console/Commands';
            $commands = [];
            foreach (glob($dir . '/*.php') as $file) {
                $commands[] = __NAMESPACE__ . '\\Console\\Commands\\' . basename($file, '.php');
            }
            $this->commands($commands);
        }
    }
}
