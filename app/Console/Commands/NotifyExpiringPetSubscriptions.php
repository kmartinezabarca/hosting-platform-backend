<?php

namespace App\Console\Commands;

use App\Models\Pet\InboxNotification;
use App\Models\Pet\OwnerSubscription;
use App\Services\Pet\PushNotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Avisa al dueño (push + inbox) cuando su período de prueba o su suscripción
 * está por vencer dentro de los próximos N días.
 *
 * Cubre dos casos:
 *   - trialing  → el trial termina pronto (trial_ends_at).
 *   - active + cancel_at_period_end → el plan NO se renovará (current_period_end).
 *
 * Las suscripciones que se renuevan solas (active sin cancelación) no se avisan:
 * Stripe las cobra automáticamente. Si el cobro falla, entra el flujo de gracia.
 *
 * expiry_notified_at evita reenviar el aviso cada hora; el webhook lo reinicia
 * cuando la suscripción se renueva o se reactiva.
 *
 * Programado en app/Console/Kernel.php (daily).
 */
class NotifyExpiringPetSubscriptions extends Command
{
    /** Días de anticipación con que se avisa el vencimiento. */
    private const WARN_WITHIN_DAYS = 3;

    protected $signature = 'rokepet:notify-expiring-subscriptions {--dry-run : Mostrar qué se haría sin aplicar cambios}';

    protected $description = 'Avisa a los dueños cuyo trial o suscripción de roke.pet está por vencer (push + inbox).';

    public function __construct(private readonly PushNotificationService $push)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun   = (bool) $this->option('dry-run');
        $deadline = now()->addDays(self::WARN_WITHIN_DAYS);
        $count    = 0;

        // ── Trials por vencer ─────────────────────────────────────────────────
        OwnerSubscription::query()
            ->where('status', 'trialing')
            ->whereNotNull('trial_ends_at')
            ->whereBetween('trial_ends_at', [now(), $deadline])
            ->whereNull('expiry_notified_at')
            ->chunkById(100, function ($subs) use ($dryRun, &$count) {
                foreach ($subs as $sub) {
                    $count += $this->sendWarning(
                        $sub,
                        '⏳ Tu prueba está por terminar',
                        'Tu período de prueba de ROKE PET vence el ' . $this->fmt($sub->trial_ends_at)
                            . '. Activa tu plan para no perder el acceso premium.',
                        'billing.trial_expiring',
                        $dryRun,
                    );
                }
            });

        // ── Suscripciones marcadas para no renovarse ──────────────────────────
        OwnerSubscription::query()
            ->where('status', 'active')
            ->where('cancel_at_period_end', true)
            ->whereNotNull('current_period_end')
            ->whereBetween('current_period_end', [now(), $deadline])
            ->whereNull('expiry_notified_at')
            ->chunkById(100, function ($subs) use ($dryRun, &$count) {
                foreach ($subs as $sub) {
                    $count += $this->sendWarning(
                        $sub,
                        '🔔 Tu suscripción vence pronto',
                        'Tu plan de ROKE PET termina el ' . $this->fmt($sub->current_period_end)
                            . ' y no se renovará. Reactívalo para mantener tus funciones premium.',
                        'billing.subscription_expiring',
                        $dryRun,
                    );
                }
            });

        $this->info(($dryRun ? '[DRY-RUN] ' : '') . "Avisos de vencimiento enviados: {$count}");

        return self::SUCCESS;
    }

    private function sendWarning(OwnerSubscription $sub, string $title, string $body, string $type, bool $dryRun): int
    {
        $this->line(" → Avisando vencimiento al dueño {$sub->owner_id} ({$type})");

        if ($dryRun) {
            return 1;
        }

        try {
            $this->push->sendToOwner($sub->owner_id, $title, $body, ['url' => '/billing']);
            InboxNotification::createForOwner(
                ownerId:   $sub->owner_id,
                title:     $title,
                body:      $body,
                notifType: $type,
                url:       '/billing',
                tag:       'billing-expiring',
            );
            $sub->update(['expiry_notified_at' => now()]);
            return 1;
        } catch (\Throwable $e) {
            Log::warning('rokepet:notify-expiring: no se pudo avisar a ' . $sub->owner_id . ': ' . $e->getMessage());
            return 0;
        }
    }

    private function fmt(?Carbon $date): string
    {
        return $date ? $date->translatedFormat('j \d\e F') : 'pronto';
    }
}
