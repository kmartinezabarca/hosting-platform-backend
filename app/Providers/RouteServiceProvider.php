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

        // Sync manual contra proveedores externos — caro; permitirlo, pero sin spam.
        RateLimiter::for('sync-status', function (Request $request) {
            return Limit::perMinute(3)
                ->by($request->user()?->id ?: $request->ip())
                ->response(fn () => response()->json([
                    'success' => false,
                    'message' => 'Demasiadas sincronizaciones. Espera un minuto antes de volver a intentar.',
                ], 429));
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

        // Indicador de "escribiendo…" del chat de soporte.
        // Escopado por usuario + ticket para que el ruido de un usuario en un
        // ticket no afecte a otros usuarios ni a sus otros chats. El límite es
        // holgado para un heartbeat legítimo (~1 cada 2s) y a la vez corta en
        // seco a un cliente con un bucle desbocado. El front debe disparar este
        // endpoint en modo "fire-and-forget" e ignorar los 429.
        RateLimiter::for('chat-typing', function (Request $request) {
            // ThrottleRequests corre antes que SubstituteBindings, así que el
            // parámetro de ruta llega como valor crudo; lo normalizamos por si
            // en algún flujo ya viniera resuelto como modelo.
            $ticket   = $request->route('ticket');
            $ticketId = is_object($ticket) ? $ticket->getKey() : $ticket;
            $owner    = $request->user()?->id ?: $request->ip();

            return Limit::perMinute(30)
                ->by('chat-typing:' . $owner . ':' . $ticketId)
                ->response(fn () => response()->json([
                    'success' => false,
                    'message' => 'Demasiadas señales de escritura.',
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
                    require base_path('routes/api_v2.php');
                });

            // roke.pet (prefijo /api/rp) ahora lo carga App\Domains\Pet\PetServiceProvider.

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }
}
