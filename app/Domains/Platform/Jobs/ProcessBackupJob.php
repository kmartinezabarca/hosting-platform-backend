<?php

namespace App\Domains\Platform\Jobs;

use App\Domains\Platform\Events\BackupStatusChanged;
use App\Domains\Platform\Models\Backup;
use App\Models\User;
use App\Domains\Platform\Notifications\BackupFailedAlert;
use App\Domains\Platform\Services\Backup\BackupService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Ejecuta un respaldo en segundo plano.
 *
 * El BackupController crea el registro con status='pending' y despacha
 * este job. El worker actualiza el status a 'running' → 'completed'/'failed'
 * mientras el frontend hace polling automático cada 15 s.
 */
class ProcessBackupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Tiempo máximo de ejecución en segundos (mysqldump + zip + upload). */
    public int $timeout = 600;

    /** Sin reintentos automáticos — un fallo es definitivo y se reporta al admin. */
    public int $tries = 1;

    public function __construct(
        public readonly Backup $backup,
        public readonly array  $opts = [],
    ) {}

    public function handle(BackupService $backupService): void
    {
        $this->backup->update([
            'status'     => 'running',
            'started_at' => now(),
        ]);
        BackupStatusChanged::dispatch($this->backup->fresh());

        try {
            $result = $backupService->runType($this->backup->type, $this->backup, $this->opts);

            $this->backup->update([
                'status'       => 'completed',
                'path'         => $result['path'],
                'size_bytes'   => $result['size'] ?? 0,
                'completed_at' => now(),
            ]);
            BackupStatusChanged::dispatch($this->backup->fresh());
        } catch (\Throwable $e) {
            $this->backup->update([
                'status'       => 'failed',
                'error'        => Str::limit($e->getMessage(), 1000),
                'completed_at' => now(),
            ]);
            BackupStatusChanged::dispatch($this->backup->fresh());
            Log::error('ProcessBackupJob falló', [
                'backup' => $this->backup->uuid,
                'type'   => $this->backup->type,
                'error'  => $e->getMessage(),
            ]);

            $this->alertIfConsecutiveFails();
        }
    }

    /**
     * Si los últimos N backups completados del mismo servicio son todos 'failed',
     * notifica a los administradores via BackupFailedAlert.
     * El umbral es 2 fallos consecutivos (evita falsas alarmas por fallos únicos).
     */
    private function alertIfConsecutiveFails(int $threshold = 2): void
    {
        try {
            // Tomamos los últimos $threshold+1 backups finalizados para este servicio.
            // Solo contamos 'completed' y 'failed' — ignoramos 'running'/'pending'.
            $recentStatuses = Backup::where('service_id', $this->backup->service_id)
                ->whereIn('status', ['completed', 'failed'])
                ->whereNotNull('completed_at')
                ->orderByDesc('completed_at')
                ->limit($threshold + 1)
                ->pluck('status');

            // Contar cuántos consecutivos desde el más reciente son 'failed'
            $consecutiveFails = 0;
            foreach ($recentStatuses as $status) {
                if ($status === 'failed') {
                    $consecutiveFails++;
                } else {
                    break; // se rompe la racha
                }
            }

            if ($consecutiveFails < $threshold) {
                return;
            }

            // Notificar a todos los administradores
            $admins = User::where('role', 'admin')->get();

            if ($admins->isEmpty()) {
                Log::warning('BackupFailedAlert: no hay administradores a quien notificar.', [
                    'backup' => $this->backup->uuid,
                ]);
                return;
            }

            $admins->each(function (User $admin) use ($consecutiveFails) {
                $admin->notify(new BackupFailedAlert($this->backup, $consecutiveFails));
            });

            Log::warning('BackupFailedAlert enviado', [
                'backup'            => $this->backup->uuid,
                'service_id'        => $this->backup->service_id,
                'consecutive_fails' => $consecutiveFails,
                'admins_notified'   => $admins->count(),
            ]);
        } catch (\Throwable $e) {
            // No propagar errores de notificación — el fallo del backup ya fue registrado.
            Log::error('No se pudo enviar BackupFailedAlert', [
                'backup' => $this->backup->uuid,
                'error'  => $e->getMessage(),
            ]);
        }
    }
}
