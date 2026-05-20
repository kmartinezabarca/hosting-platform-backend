<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        // API general
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // Auth endpoints (login, register) — más estricto
        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });

        // Búsqueda global — evitar abuso
        RateLimiter::for('search', function (Request $request) {
            return Limit::perMinute(30)->by($request->user()?->id ?: $request->ip());
        });

        // Reenvío de verificación de correo — evitar spam al buzón del usuario.
        RateLimiter::for('verification-notification', function (Request $request) {
            return Limit::perMinute(6)
                ->by($request->user()?->id ?: $request->ip())
                ->response(fn () => response()->json([
                    'success' => false,
                    'message' => 'Demasiados intentos. Espera un minuto antes de solicitar otro correo de verificación.',
                ], 429));
        });

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(function () {
                    require base_path('routes/api.php');
                    require base_path('routes/auth.php');
                    require base_path('routes/client.php');
                    require base_path('routes/admin.php');
                });

            // roke.pet — completamente aislado del resto de la plataforma
            Route::middleware('api')
                ->prefix('api/rp')
                ->group(base_path('routes/pet.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }
}
