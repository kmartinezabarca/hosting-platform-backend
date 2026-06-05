<?php

namespace App\Domains\Platform\Console\Commands;

use App\Domains\Platform\Models\Backup;
use App\Domains\Platform\Models\BackupSchedule;
use App\Domains\Platform\Services\Backup\BackupService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class RunScheduledBackups extends Command
{
    protected $signature = 'backups:run-scheduled {--force : Ejecuta todas las programaciones habilitadas sin importar su próxima ejecución}';

    protected $description = 'Ejecuta las programaciones de respaldo que estén vencidas y aplica la retención.';

    public function handle(BackupService $backups): int
    {
        $schedules = BackupSchedule::where('is_enabled', true)->get();
        $ran = 0;

        foreach ($schedules as $schedule) {
            if (!$this->option('force') && !$schedule->isDue()) {
                continue;
            }

            $this->info("Ejecutando programación: {$schedule->name} [{$schedule->type}]");

            try {
                $backups->create($schedule->type, [
                    'name'        => $schedule->name,
                    'schedule_id' => $schedule->id,
                    'user_id'     => $schedule->scope === 'user' ? $schedule->scope_id : null,
                    'service_id'  => $schedule->scope === 'service' ? $schedule->scope_id : null,
                ]);
            } catch (\Throwable $e) {
                $this->error("Falló la programación {$schedule->uuid}: {$e->getMessage()}");
            }

            // Retención por programación: borra sus backups más viejos.
            $cutoff = Carbon::now()->subDays((int) $schedule->retention_days);
            Backup::where('schedule_id', $schedule->id)
                ->where('created_at', '<', $cutoff)
                ->get()
                ->each(fn (Backup $b) => $backups->delete($b));

            $schedule->update([
                'last_run_at' => now(),
                'next_run_at' => $schedule->computeNextRun(),
            ]);
            $ran++;
        }

        // Retención global para respaldos manuales sin programación.
        $purged = $backups->applyRetention();

        $this->info("Programaciones ejecutadas: {$ran}. Respaldos purgados por retención global: {$purged}.");

        return self::SUCCESS;
    }
}
