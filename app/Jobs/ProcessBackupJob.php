<?php

namespace App\Jobs;

use App\Models\Backup;
use App\Services\Backup\BackupService;
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

        try {
            $result = $backupService->runType($this->backup->type, $this->backup, $this->opts);

            $this->backup->update([
                'status'       => 'completed',
                'path'         => $result['path'],
                'size_bytes'   => $result['size'] ?? 0,
                'completed_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $this->backup->update([
                'status'       => 'failed',
                'error'        => Str::limit($e->getMessage(), 1000),
                'completed_at' => now(),
            ]);
            Log::error('ProcessBackupJob falló', [
                'backup' => $this->backup->uuid,
                'type'   => $this->backup->type,
                'error'  => $e->getMessage(),
            ]);
        }
    }
}
