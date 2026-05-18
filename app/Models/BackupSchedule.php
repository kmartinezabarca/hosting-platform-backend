<?php

namespace App\Models;

use App\Traits\HasUuidColumn;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BackupSchedule extends Model
{
    use HasFactory, HasUuidColumn;

    protected $fillable = [
        'uuid',
        'name',
        'type',
        'scope',
        'scope_id',
        'frequency',
        'cron_expression',
        'run_at_time',
        'run_at_day',
        'retention_days',
        'is_enabled',
        'last_run_at',
        'next_run_at',
    ];

    protected $casts = [
        'is_enabled'  => 'boolean',
        'last_run_at' => 'datetime',
        'next_run_at' => 'datetime',
    ];

    public function backups()
    {
        return $this->hasMany(Backup::class, 'schedule_id');
    }

    /**
     * Calcula la próxima ejecución a partir de la frecuencia configurada.
     */
    public function computeNextRun(?Carbon $from = null): Carbon
    {
        $from = $from ? $from->copy() : now();
        [$h, $m] = array_pad(explode(':', $this->run_at_time ?: '03:00'), 2, 0);

        return match ($this->frequency) {
            'weekly' => $from->next((int) ($this->run_at_day ?? 1))
                             ->setTime((int) $h, (int) $m),
            'monthly' => $from->copy()
                              ->addMonthNoOverflow()
                              ->day(min((int) ($this->run_at_day ?? 1), 28))
                              ->setTime((int) $h, (int) $m),
            'cron' => $this->nextFromCron($from),
            default /* daily */ => $from->copy()
                              ->addDay()
                              ->setTime((int) $h, (int) $m),
        };
    }

    private function nextFromCron(Carbon $from): Carbon
    {
        // Soporte básico de cron sin dependencias externas: si la expresión
        // no es válida, se cae a diario. Para crons complejos conviene
        // instalar dragonmantank/cron-expression.
        if (class_exists(\Cron\CronExpression::class) && $this->cron_expression) {
            try {
                return Carbon::instance(
                    (new \Cron\CronExpression($this->cron_expression))
                        ->getNextRunDate($from)
                );
            } catch (\Throwable) {
                // cae a diario
            }
        }
        return $from->copy()->addDay();
    }

    public function isDue(): bool
    {
        return $this->is_enabled
            && $this->next_run_at
            && $this->next_run_at->lessThanOrEqualTo(now());
    }
}
