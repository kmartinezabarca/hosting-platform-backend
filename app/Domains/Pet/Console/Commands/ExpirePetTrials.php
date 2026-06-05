<?php

namespace App\Domains\Pet\Console\Commands;

use App\Domains\Pet\Models\ActivationEvent;
use App\Domains\Pet\Models\InboxNotification;
use App\Domains\Pet\Models\OwnerSubscription;
use App\Domains\Pet\Services\PushNotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Cierra las pruebas gratuitas (trial local) vencidas que NO se convirtieron en
 * un plan de pago, degradando la cuenta al plan gratuito.
 *
 * Modelo de trial LOCAL (sin tarjeta): el trial vive en owner_subscriptions
 * (status='trialing' + trial_ends_at). Al vencer sin que el dueño active un plan
 * de pago, este comando lo pasa a 'free' (active). Si el dueño convirtió a un plan
 * de pago, Stripe maneja su periodo y el status ya no es 'trialing'.
 *
 * Programado en app/Console/Kernel.php (daily). Idempotente.
 */
class ExpirePetTrials extends Command
{
    protected $signature = 'rokepet:expire-trials {--dry-run : Mostrar qué se haría sin aplicar cambios}';

    protected $description = 'Degrada al plan gratuito las pruebas (trial) de roke.pet vencidas sin conversión.';

    public function __construct(private readonly PushNotificationService $push)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $count  = 0;

        OwnerSubscription::query()
            ->where('status', 'trialing')
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '<=', now())
            ->chunkById(100, function ($subs) use ($dryRun, &$count) {
                foreach ($subs as $sub) {
                    $this->line(" → Prueba vencida del dueño {$sub->owner_id} → plan gratuito");

                    if ($dryRun) {
                        $count++;
                        continue;
                    }

                    try {
                        $sub->update([
                            'plan_code'              => OwnerSubscription::FREE_PLAN_CODE,
                            'status'                 => 'active',
                            'trial_ends_at'          => null,
                            'current_period_end'     => null,
                            'cancel_at_period_end'   => false,
                            'stripe_subscription_id' => null,
                            'stripe_price_id'        => null,
                            'expiry_notified_at'     => null,
                        ]);

                        ActivationEvent::create([
                            'owner_id'    => $sub->owner_id,
                            'event_type'  => 'trial_expired',
                            'source'      => 'billing',
                            'metadata'    => ['plan_code' => OwnerSubscription::FREE_PLAN_CODE],
                            'occurred_at' => now(),
                        ]);

                        $this->notifyOwner($sub->owner_id);
                        $count++;
                    } catch (\Throwable $e) {
                        Log::error('rokepet:expire-trials: error degradando prueba', [
                            'owner_id' => $sub->owner_id,
                            'error'    => $e->getMessage(),
                        ]);
                    }
                }
            });

        $this->info(($dryRun ? '[DRY-RUN] ' : '') . "Pruebas vencidas pasadas a plan gratuito: {$count}");

        return self::SUCCESS;
    }

    private function notifyOwner(string $ownerId): void
    {
        $title = 'Tu prueba gratuita terminó';
        $body  = 'Tu período de prueba finalizó y tu cuenta pasó al plan gratuito. '
            . 'Activa un plan cuando quieras para recuperar las funciones premium.';
        try {
            $this->push->sendToOwner($ownerId, $title, $body, ['url' => '/billing']);
            InboxNotification::createForOwner(
                ownerId:   $ownerId,
                title:     $title,
                body:      $body,
                notifType: 'billing.trial_ended',
                url:       '/billing',
                tag:       'billing-trial-ended',
            );
        } catch (\Throwable $e) {
            Log::warning('rokepet:expire-trials: no se pudo notificar a ' . $ownerId . ': ' . $e->getMessage());
        }
    }
}
