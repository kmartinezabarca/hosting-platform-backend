<?php

namespace App\Domains\Platform\Console\Commands;

use App\Domains\Platform\Models\Domain;
use App\Domains\Platform\Notifications\DomainExpiryAlert;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Envía alertas de vencimiento de dominios a los propietarios.
 *
 * Se envía cuando quedan exactamente 30, 15 o 7 días para el vencimiento.
 * Usar "exactamente" evita enviar duplicados si el scheduler se ejecuta más
 * de una vez al día por cualquier razón.
 *
 * Horario recomendado: diariamente a las 09:00
 *   → php artisan domains:send-expiry-alerts
 */
class SendDomainExpiryAlerts extends Command
{
    protected $signature = 'domains:send-expiry-alerts
                            {--force : Enviar a todos los dominios que vencen en ≤30 días, sin importar si ya se notificó}
                            {--dry-run : Mostrar qué se enviaría sin enviar nada}';

    protected $description = 'Envía alertas de vencimiento de dominios (30/15/7 días antes).';

    /** Días antes del vencimiento en que se envían alertas. */
    private const ALERT_DAYS = [30, 15, 7];

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $force  = $this->option('force');

        $sent   = 0;
        $errors = 0;

        foreach (self::ALERT_DAYS as $days) {
            // Buscar dominios activos cuya expiration_date cae en exactamente $days días
            // (ventana de ±1 día para tolerar ejecuciones con pequeño desfase)
            $domains = Domain::with('user')
                ->where('status', 'active')
                ->whereBetween('expiration_date', [
                    now()->addDays($days - 1)->startOfDay(),
                    now()->addDays($days)->endOfDay(),
                ])
                ->get();

            if ($domains->isEmpty()) {
                continue;
            }

            $this->line("Dominios que vencen en ~{$days} días: {$domains->count()}");

            foreach ($domains as $domain) {
                if (! $domain->user) {
                    continue;
                }

                if ($dryRun) {
                    $this->line("  [DRY] {$domain->domain_name} → {$domain->user->email} ({$days} días)");
                    $sent++;
                    continue;
                }

                try {
                    $domain->user->notify(new DomainExpiryAlert($domain, $days));
                    \App\Domains\Platform\Support\AdminNotifier::notify(
                        'Dominio por vencer',
                        "El dominio {$domain->domain_name} de {$domain->user->full_name} vence en ~{$days} días.",
                        'admin_domain_expiring',
                        ['domain' => $domain->domain_name, 'user_id' => $domain->user_id, 'days' => $days],
                    );
                    $sent++;

                    Log::info('DomainExpiryAlert enviada', [
                        'domain'  => $domain->domain_name,
                        'user_id' => $domain->user_id,
                        'days'    => $days,
                    ]);
                } catch (\Throwable $e) {
                    $errors++;
                    Log::error('Error enviando DomainExpiryAlert', [
                        'domain'  => $domain->domain_name,
                        'user_id' => $domain->user_id,
                        'error'   => $e->getMessage(),
                    ]);
                }
            }
        }

        // Marcar como expirados los dominios que ya vencieron
        $expired = Domain::where('status', 'active')
            ->where('expiration_date', '<', now()->startOfDay())
            ->update(['status' => 'expired']);

        if ($expired > 0) {
            $this->warn("{$expired} dominio(s) marcados como expirados.");
            Log::info("SendDomainExpiryAlerts: {$expired} dominios marcados como expirados.");
        }

        $this->newLine();
        $this->table(
            ['Alertas enviadas', 'Errores', 'Expirados marcados'],
            [[$sent, $errors, $expired]]
        );

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
