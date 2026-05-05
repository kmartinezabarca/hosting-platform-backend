<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Timbrado automático de CFDIs pendientes y Público en General vencidos
        $schedule->command('cfdi:stamp-pending')
            ->hourly()
            ->withoutOverlapping()
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/cfdi-stamp.log'));

        // Actualización nocturna de versiones de software de Minecraft (Pterodactyl Eggs)
        $schedule->command('software:refresh-versions')
            ->dailyAt('03:00')
            ->withoutOverlapping()
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/software-versions.log'));

        // Sincronización del catálogo de juegos (nests/eggs) desde Pterodactyl.
        // Se ejecuta cada hora para reflejar cualquier egg nuevo o eliminado en el panel.
        $schedule->command('pterodactyl:sync-eggs')
            ->hourly()
            ->withoutOverlapping()
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/pterodactyl-sync.log'));

        // Monitoreo de salud de servidores de juego.
        // Cada 5 minutos escanea todos los servidores activos; si alguno está offline
        // encola CheckAndFixJavaCompatibilityJob para auto-detección y corrección de Java.
        $schedule->command('game-servers:monitor-health')
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/game-server-health.log'));
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
