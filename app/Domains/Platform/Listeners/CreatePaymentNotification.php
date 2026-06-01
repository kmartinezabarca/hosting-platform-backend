<?php

namespace App\Domains\Platform\Listeners;

use App\Domains\Platform\Events\PaymentProcessed;
use App\Domains\Platform\Events\PaymentFailed;
use App\Domains\Platform\Events\AutomaticPaymentProcessed;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use App\Domains\Platform\Notifications\PaymentNotification;

class CreatePaymentNotification implements ShouldQueue
{
    use InteractsWithQueue;

    public function handleProcessed(PaymentProcessed $event)
    {
        $user = User::find($event->transaction->user_id);

        if ($user) {
            $this->notifyClient($user, [
                'title'   => 'Pago Procesado',
                'message' => $event->broadcastWith()['message'],
                'type'    => 'payment_processed',
                'data'    => $event->broadcastWith(),
            ]);
        }

        $this->notifyAdmins([
            'title'   => 'Pago Recibido',
            'message' => "Pago de {$event->transaction->amount} {$event->transaction->currency} procesado para el usuario {$user?->full_name}",
            'type'    => 'admin_payment_processed',
            'data'    => $event->broadcastWith(),
        ]);
    }

    public function handleFailed(PaymentFailed $event)
    {
        $user = User::find($event->transaction->user_id);

        if ($user) {
            $this->notifyClient($user, [
                'title'   => 'Pago Fallido',
                'message' => $event->broadcastWith()['message'],
                'type'    => 'payment_failed',
                'data'    => $event->broadcastWith(),
            ]);
        }

        $this->notifyAdmins([
            'title'   => 'Pago Fallido',
            'message' => "Pago fallido de {$event->transaction->amount} {$event->transaction->currency} para el usuario {$user?->full_name}",
            'type'    => 'admin_payment_failed',
            'data'    => $event->broadcastWith(),
        ]);
    }

    public function handleAutomaticProcessed(AutomaticPaymentProcessed $event)
    {
        $user = User::find($event->transaction->user_id);

        if ($user) {
            $this->notifyClient($user, [
                'title'   => 'Pago Automático Procesado',
                'message' => $event->broadcastWith()['message'],
                'type'    => 'automatic_payment_processed',
                'data'    => $event->broadcastWith(),
            ]);
        }

        $this->notifyAdmins([
            'title'   => 'Pago Automático',
            'message' => "Pago automático de {$event->transaction->amount} {$event->transaction->currency} procesado para el usuario {$user?->full_name}",
            'type'    => 'admin_automatic_payment',
            'data'    => $event->broadcastWith(),
        ]);
    }

    private function notifyClient(User $user, array $data): void
    {
        $user->notify(new PaymentNotification(array_merge($data, [
            'target'   => 'client',
            '_channel' => 'user.' . $user->uuid,
        ])));
    }

    private function notifyAdmins(array $data): void
    {
        User::where('role', 'admin')->orWhere('role', 'super_admin')->get()
            ->each(fn ($admin) => $admin->notify(new PaymentNotification(array_merge($data, [
                'target'   => 'admin',
                '_channel' => 'admin.notifications',
            ]))));
    }
}
