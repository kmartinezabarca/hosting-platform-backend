<?php

namespace App\Jobs;

use App\Models\Service;
use App\Models\User;
use App\Services\Minecraft\MinecraftServerConfigurationService;
use App\Services\Pterodactyl\PterodactylService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Job de auto-sanación de Java para servidores de juego.
 *
 * Se encola automáticamente en dos momentos:
 *   1. Después de que StartServerAfterInstall enciende el servidor (60 s de delay).
 *   2. Desde MonitorGameServerHealth cuando detecta un servidor offline.
 *
 * Flujo:
 *   a) Leer estado actual del servidor en Pterodactyl.
 *   b) Si está running → todo bien, terminar.
 *   c) Si está offline / crashed → leer logs → buscar errores de Java.
 *   d) Si hay error de Java → fixJavaVersion() + restart.
 *   e) Dispatch de sí mismo (con delay) para verificar que el restart funcionó.
 *   f) Si no hay error de Java → notificar al admin (el crash no es de Java).
 *   g) Si está starting/installing → release(30) y reintentar.
 */
class CheckAndFixJavaCompatibilityJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Número máximo de intentos antes de rendirse.
     * Cada intento espera 30 segundos → máximo ~5 minutos de espera.
     */
    public int $tries = 10;

    /**
     * Backoff entre intentos (en segundos).
     * Si el servidor está en "starting" o "installing", reintentamos aquí.
     */
    public array $backoff = [30, 45, 60, 60, 60, 90, 90, 120, 120, 120];

    /**
     * Si este job es una verificación post-fix (para confirmar que el restart funcionó).
     * En ese caso no aplicamos otra corrección — solo notificamos.
     */
    public function __construct(
        protected Service $service,
        protected bool    $isVerification = false
    ) {}

    public function handle(
        PterodactylService                    $pterodactyl,
        MinecraftServerConfigurationService   $javaService
    ): void {
        $this->service->refresh();

        // No actuar sobre servicios terminados, fallidos o sin servidor asignado
        if (! in_array($this->service->status, ['active', 'pending'], true)) {
            Log::info('CheckAndFixJava: servicio no activo, saltando', [
                'service_id' => $this->service->id,
                'status'     => $this->service->status,
            ]);
            return;
        }

        $identifier = $this->service->connection_details['identifier'] ?? null;

        if (! $identifier) {
            Log::warning('CheckAndFixJava: sin identifier, saltando', [
                'service_id' => $this->service->id,
            ]);
            return;
        }

        // ── 1. Consultar estado del servidor ─────────────────────────────────
        try {
            $resources = $pterodactyl->getServerResources($identifier);
            $state     = $resources['current_state'] ?? 'offline';
        } catch (\Throwable $e) {
            Log::warning('CheckAndFixJava: no se pudo consultar estado del servidor', [
                'service_id' => $this->service->id,
                'error'      => $e->getMessage(),
            ]);
            // Reintentar
            $this->release(30);
            return;
        }

        Log::info('CheckAndFixJava: estado del servidor', [
            'service_id'      => $this->service->id,
            'state'           => $state,
            'is_verification' => $this->isVerification,
        ]);

        // ── 2. Servidor corriendo — todo bien ────────────────────────────────
        if ($state === 'running') {
            if ($this->isVerification) {
                Log::info('CheckAndFixJava: verificación exitosa — servidor funcionando correctamente', [
                    'service_id' => $this->service->id,
                ]);
            }
            return;
        }

        // ── 3. Servidor arrancando o instalando — esperar ────────────────────
        if (in_array($state, ['starting', 'installing'], true)) {
            Log::info("CheckAndFixJava: servidor en estado '{$state}', reintentando en 30s", [
                'service_id' => $this->service->id,
            ]);
            $this->release(30);
            return;
        }

        // ── 4. Servidor offline / crashed ─────────────────────────────────────
        // Es posible que se haya caído por un error de Java.

        if ($this->isVerification) {
            // Ya intentamos corregir y el servidor sigue caído → notificar al admin
            Log::error('CheckAndFixJava: el servidor sigue offline después del fix de Java', [
                'service_id' => $this->service->id,
            ]);
            $this->notifyAdminFixFailed(
                'El servidor sigue offline después de corregir la versión de Java. Requiere revisión manual.'
            );
            return;
        }

        // ── 5. Leer logs y detectar error de Java ────────────────────────────
        try {
            $detection = $javaService->detectJavaCompatibilityFromLogs($this->service);
        } catch (\Throwable $e) {
            Log::warning('CheckAndFixJava: no se pudo leer el log del servidor', [
                'service_id' => $this->service->id,
                'error'      => $e->getMessage(),
            ]);
            // Log no disponible aún → reintento
            $this->release(30);
            return;
        }

        if (! $detection['has_error']) {
            // No es un error de Java. El servidor crasheó por otra razón.
            // Notificar al admin para revisión manual.
            Log::warning('CheckAndFixJava: servidor offline pero sin error de Java en logs', [
                'service_id' => $this->service->id,
                'message'    => $detection['message'],
            ]);
            $this->notifyAdminFixFailed(
                'El servidor está offline pero no se detectaron errores de Java en los logs. ' .
                'Puede ser un error de configuración o de mods/plugins. Revisión manual requerida.'
            );
            return;
        }

        // ── 6. Error de Java detectado — corregir automáticamente ────────────
        Log::info('CheckAndFixJava: error de Java detectado, aplicando corrección automática', [
            'service_id'     => $this->service->id,
            'error_type'     => $detection['error_type'],
            'current_java'   => $detection['current_java'],
            'required_java'  => $detection['required_java'],
            'log_snippet'    => $detection['log_snippet'],
        ]);

        try {
            $fix = $javaService->fixJavaVersion($this->service, $detection['required_java']);
        } catch (\Throwable $e) {
            Log::error('CheckAndFixJava: fixJavaVersion falló', [
                'service_id' => $this->service->id,
                'error'      => $e->getMessage(),
            ]);
            $this->notifyAdminFixFailed(
                "Se detectó incompatibilidad de Java (necesita Java {$detection['required_java']}) " .
                "pero no se pudo aplicar la corrección automática: {$e->getMessage()}"
            );
            return;
        }

        if (! $fix['fixed']) {
            Log::info('CheckAndFixJava: fixJavaVersion reportó que ya era correcto', [
                'service_id' => $this->service->id,
                'message'    => $fix['message'],
            ]);
            return;
        }

        // ── 7. Reiniciar el servidor con la nueva imagen Docker ───────────────
        try {
            $pterodactyl->sendPowerSignal($identifier, 'start');
            Log::info('CheckAndFixJava: servidor reiniciado con la nueva imagen Docker', [
                'service_id'   => $this->service->id,
                'old_java'     => $fix['old_java'],
                'new_java'     => $fix['new_java'],
                'docker_image' => $fix['docker_image'],
            ]);
        } catch (\Throwable $e) {
            Log::error('CheckAndFixJava: no se pudo enviar señal de inicio después del fix', [
                'service_id' => $this->service->id,
                'error'      => $e->getMessage(),
            ]);
        }

        // ── 8. Notificar al dueño del servicio ───────────────────────────────
        $this->notifyOwner(
            $fix['old_java'],
            $fix['new_java'],
            $fix['docker_image']
        );

        // ── 9. Encolar verificación: confirmar que el servidor arrancó bien ───
        // 90 segundos de delay: tiempo suficiente para que el servidor inicie.
        static::dispatch($this->service, isVerification: true)
            ->delay(now()->addSeconds(90));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function notifyOwner(int $oldJava, int $newJava, string $dockerImage): void
    {
        try {
            $user = $this->service->user;
            if (! $user) {
                return;
            }

            Notification::send($user, new \App\Notifications\ServiceNotification([
                'title'   => 'Versión de Java corregida automáticamente',
                'message' => "Tu servidor '{$this->service->name}' necesitaba Java {$newJava} (tenía Java {$oldJava}). "
                           . 'La corrección fue aplicada automáticamente y el servidor está reiniciando.',
                'type'    => 'service.java_fixed',
                'data'    => [
                    'service_id'   => $this->service->uuid,
                    'old_java'     => $oldJava,
                    'new_java'     => $newJava,
                    'docker_image' => $dockerImage,
                ],
            ]));
        } catch (\Throwable $e) {
            Log::warning('CheckAndFixJava: no se pudo notificar al usuario', [
                'service_id' => $this->service->id,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    private function notifyAdminFixFailed(string $reason): void
    {
        try {
            $admins = User::whereIn('role', ['admin', 'super_admin'])->get();

            if ($admins->isEmpty()) {
                return;
            }

            Notification::send($admins, new \App\Notifications\ServiceNotification([
                'title'   => 'Servidor offline — intervención requerida',
                'message' => "Servicio #{$this->service->id} ({$this->service->name}): {$reason}",
                'type'    => 'service.needs_manual_fix',
                'data'    => [
                    'service_id'   => $this->service->uuid,
                    'service_name' => $this->service->name,
                    'reason'       => $reason,
                ],
            ]));
        } catch (\Throwable $e) {
            Log::warning('CheckAndFixJava: no se pudo notificar a admins', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
