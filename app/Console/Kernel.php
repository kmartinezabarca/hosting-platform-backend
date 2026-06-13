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

        // Sincronización del catálogo de nodos desde Pterodactyl → server_nodes.
        // Diario: los nodos cambian con poca frecuencia. --prune marca offline los eliminados.
        $schedule->command('pterodactyl:sync-nodes --prune')
            ->dailyAt('03:30')
            ->withoutOverlapping()
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/pterodactyl-sync.log'));

        // Expiración de servicios en periodo de prueba (trial).
        // Se ejecuta cada hora; suspende servicios cuyo trial_ends_at haya vencido.
        $schedule->command('services:expire-trials')
            ->hourly()
            ->withoutOverlapping()
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/trial-expiration.log'));

        // Morosidad (dunning): suspende servicios cuyo periodo de gracia por pago
        // fallido ya venció y reconcilia reactivaciones perdidas. Cada hora.
        $schedule->command('subscriptions:process-overdue')
            ->hourly()
            ->withoutOverlapping()
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/subscriptions-overdue.log'));

        // Reintenta aprovisionamientos pendientes/fallidos (Pterodactyl/Coolify)
        // con backoff. Cada 5 minutos para una recuperación rápida tras un fallo.
        $schedule->command('provisioning:process-pending')
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/provisioning-pending.log'));

        // Reconciliación de proxies FRP: game servers activos con servidor
        // Pterodactyl pero sin proxy (fallos tardíos de FRP). Reintenta solo
        // el paso FRP; notifica al admin tras 3 intentos fallidos.
        $schedule->command('game-servers:reconcile-frp')
            ->hourly()
            ->withoutOverlapping()
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/frp-reconcile.log'));

        // Health check de sitios de hosting (Coolify): uptime + latencia REAL.
        // Coolify no expone CPU/RAM, así que medimos disponibilidad con un GET HTTP.
        $schedule->command('hosting:check-health')
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/hosting-health.log'));

        // roke.pet — recordatorios: vacunas, desparasitaciones y consultas (email + push)
        $schedule->command('rokepet:send-pet-reminders')
            ->dailyAt('08:00')
            ->withoutOverlapping()
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/rokepet-reminders.log'));

        // roke.pet — morosidad: degrada al plan gratuito las suscripciones cuyo
        // periodo de gracia por pago fallido ya venció. Cada hora.
        $schedule->command('rokepet:process-overdue-subscriptions')
            ->hourly()
            ->withoutOverlapping()
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/rokepet-overdue.log'));

        // roke.pet — avisa a los dueños cuyo trial o suscripción está por vencer
        // (3 días antes). Una vez al día.
        $schedule->command('rokepet:notify-expiring-subscriptions')
            ->dailyAt('09:00')
            ->withoutOverlapping()
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/rokepet-expiring.log'));

        // roke.pet — cierra (reinicia) conversaciones de soporte inactivas >24h.
        // El chat no es eterno: tras la ventana se auto-cierra. Cada hora.
        $schedule->command('rokepet:expire-stale-chats')
            ->hourly()
            ->withoutOverlapping()
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/rokepet-chat-expire.log'));

        // roke.pet — cierra pruebas (trial local) vencidas sin conversión → plan
        // gratuito. Una vez al día.
        $schedule->command('rokepet:expire-trials')
            ->dailyAt('06:00')
            ->withoutOverlapping()
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/rokepet-expire-trials.log'));

        // Historial de ping (latencia) de servidores de juego.
        // Muestrea el SLP de cada game server activo y persiste en game_server_pings.
        $schedule->command('game-servers:collect-pings')
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/game-server-pings.log'));

        // Monitoreo de salud de servidores de juego.
        // Cada 5 minutos escanea todos los servidores activos; si alguno está offline
        // encola CheckAndFixJavaCompatibilityJob para auto-detección y corrección de Java.
        $schedule->command('game-servers:monitor-health')
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/game-server-health.log'));

        // Respaldos programados al NAS + retención.
        // Corre cada 10 min y dispara las programaciones vencidas.
        $schedule->command('backups:run-scheduled')
            ->everyTenMinutes()
            ->withoutOverlapping()
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/backups.log'));

        // Historial de métricas de recursos (CPU, RAM, disco, red) de game servers.
        // Muestrea todos los servidores activos cada 5 min y persiste en service_metrics.
        $schedule->command('services:collect-metrics')
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/service-metrics.log'));

        // Sincronización de runtime status (Pterodactyl / Coolify) → live_status / live_metrics.
        // Snapshot cacheado: la UI en vivo usa Wings/Reverb; aquí evitamos costo lineal por minuto.
        $schedule->command('services:sync-status')
            ->everyFiveMinutes()
            ->withoutOverlapping(5)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/service-status-sync.log'));

        // Alertas de vencimiento de dominios.
        // Notifica a los clientes a los 30, 15 y 7 días antes del vencimiento.
        // También marca como 'expired' los dominios cuya fecha ya pasó.
        $schedule->command('domains:send-expiry-alerts')
            ->dailyAt('09:00')
            ->withoutOverlapping()
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/domain-expiry.log'));

        // Verificación diaria de certificados SSL de todos los servicios de hosting activos.
        // Actualiza la tabla ssl_certificates y envía alertas a los 30, 15 y 7 días.
        $schedule->command('ssl:check-certificates')
            ->dailyAt('08:00')
            ->withoutOverlapping()
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/ssl-check.log'));

        // Barrido de ambientes preview de PR vencidos (red de seguridad por si
        // el webhook de cierre nunca llegó).
        $schedule->command('compute:prune-previews')
            ->hourly()
            ->withoutOverlapping()
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/prune-previews.log'));

        // Retención de trazas HTTP técnicas. Evita que api_request_logs crezca
        // indefinidamente cuando se registra cada petición API.
        $schedule->command('api-logs:prune')
            ->dailyAt('02:30')
            ->withoutOverlapping()
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/api-request-logs-prune.log'));
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
