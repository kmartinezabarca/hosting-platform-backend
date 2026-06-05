<?php

namespace App\Domains\Pet\Console\Commands;

use App\Domains\Pet\Models\ActivationEvent;
use App\Domains\Pet\Models\InboxNotification;
use App\Domains\Pet\Models\OwnerSubscription;
use App\Domains\Pet\Services\PushNotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Degrada al plan gratuito las suscripciones de roke.pet cuyo periodo de gracia
 * por cobro fallido ya venció.
 *
 * Flujo de morosidad (dunning) — roke.pet:
 *   1. invoice.payment_failed → past_due + grace_period_ends_at = now()+5 días.
 *   2. La cuenta sigue activa durante la gracia (un banner avisa al dueño).
 *   3. Este comando, al vencer la gracia sin pago, baja la cuenta al plan 'free'.
 *   4. Si el dueño paga (invoice.paid), el webhook limpia la gracia antes.
 *
 * Programado en app/Console/Kernel.php (hourly).
 */
class ProcessOverduePetSubscriptions extends Command
{
    protected $signature = 'rokepet:process-overdue-subscriptions {--dry-run : Mostrar qué se haría sin aplicar cambios}';

    protected $description = 'Degrada al plan gratuito las suscripciones de roke.pet con periodo de gracia vencido.';

    public function __construct(private readonly PushNotificationService $push)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $count  = 0;

        OwnerSubscription::query()
            ->where('status', 'past_due')
            ->whereNotNull('grace_period_ends_at')
            ->where('grace_period_ends_at', '<=', now())
            ->chunkById(100, function ($subscriptions) use ($dryRun, &$count) {
                foreach ($subscriptions as $sub) {
                    $this->line(" → Degradando a 'free' al dueño {$sub->owner_id} — gracia vencida");

                    if ($dryRun) {
                        $count++;
                        continue;
                    }

                    try {
                        // El plan gratuito vive como 'active' (igual que el alta
                        // gratuita en createCheckoutSession). Se conserva el
                        // stripe_customer_id para que pueda volver a suscribirse
                        // fácilmente; se sueltan la suscripción y el price de Stripe.
                        $sub->update([
                            'plan_code'              => OwnerSubscription::FREE_PLAN_CODE,
                            'status'                 => 'active',
                            'cancel_at_period_end'   => false,
                            'canceled_at'            => now(),
                            'current_period_end'     => null,
                            'stripe_subscription_id' => null,
                            'stripe_price_id'        => null,
                            'payment_failed_at'      => null,
                            'grace_period_ends_at'   => null,
                            'last_payment_error'     => null,
                            'expiry_notified_at'     => null,
                        ]);

                        ActivationEvent::create([
                            'owner_id'    => $sub->owner_id,
                            'event_type'  => 'subscription_downgraded',
                            'source'      => 'billing',
                            'metadata'    => ['reason' => 'payment_overdue', 'plan_code' => OwnerSubscription::FREE_PLAN_CODE],
                            'occurred_at' => now(),
                        ]);

                        $this->notifyOwner($sub->owner_id);
                        $count++;
                    } catch (\Throwable $e) {
                        Log::error('rokepet:process-overdue: error degradando suscripción', [
                            'owner_id' => $sub->owner_id,
                            'error'    => $e->getMessage(),
                        ]);
                    }
                }
            });

        $this->info(($dryRun ? '[DRY-RUN] ' : '') . "Suscripciones degradadas a plan gratuito: {$count}");

        return self::SUCCESS;
    }

    private function notifyOwner(string $ownerId): void
    {
        $title = 'Tu plan pasó al gratuito';
        $body  = 'No pudimos procesar tu pago a tiempo, así que tu cuenta volvió al plan gratuito. '
            . 'Puedes reactivar tu plan cuando quieras desde Plan y facturación.';
        try {
            $this->push->sendToOwner($ownerId, $title, $body, ['url' => '/billing']);
            InboxNotification::createForOwner(
                ownerId:   $ownerId,
                title:     $title,
                body:      $body,
                notifType: 'billing.downgraded',
                url:       '/billing',
                tag:       'billing-downgraded',
            );
        } catch (\Throwable $e) {
            Log::warning('rokepet:process-overdue: no se pudo notificar a ' . $ownerId . ': ' . $e->getMessage());
        }
    }
}
