<?php

namespace App\Domains\Pet;

use App\Domains\Pet\Console\Commands\NotifyExpiringPetSubscriptions;
use App\Domains\Pet\Console\Commands\ProcessOverduePetSubscriptions;
use App\Domains\Pet\Console\Commands\SendPetReminders;
use App\Domains\Pet\Contracts\UserDirectory;
use App\Domains\Pet\Support\EloquentUserDirectory;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Frontera explícita del dominio roke.pet dentro del monolito.
 *
 * Este provider es el ÚNICO punto de enganche del dominio con el resto de la
 * plataforma: carga sus rutas, registra sus comandos y enlaza sus contratos.
 * Todo lo de pet vive bajo app/Domains/Pet/ y usa su propia BD (conexión
 * roke_pet) — comparte únicamente la tabla `users` (vía UserDirectory).
 *
 * NOTA migraciones: las migraciones de pet viven en database/migrations/roke_pet
 * y se rastrean en SU PROPIA tabla `migrations` (BD roke_pet). Por eso NO se usa
 * loadMigrationsFrom() aquí (haría que `php artisan migrate` las registrara en la
 * tabla del hosting y reintentara recrearlas). Se aplican con:
 *   php artisan migrate --path=database/migrations/roke_pet --database=roke_pet
 */
class PetServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Contrato de identidad (seam para futura separación a servicio propio).
        $this->app->bind(UserDirectory::class, EloquentUserDirectory::class);
    }

    public function boot(): void
    {
        // Rutas del dominio — prefijo /api/rp, aisladas del resto.
        Route::middleware('api')
            ->prefix('api/rp')
            ->group(__DIR__ . '/routes/api.php');

        // Comandos del dominio (antes auto-cargados desde app/Console/Commands).
        if ($this->app->runningInConsole()) {
            $this->commands([
                SendPetReminders::class,
                ProcessOverduePetSubscriptions::class,
                NotifyExpiringPetSubscriptions::class,
            ]);
        }
    }
}
