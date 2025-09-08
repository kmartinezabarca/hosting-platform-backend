<?php

namespace App\Listeners;

use App\Events\PaymentProcessed;
use App\Events\PaymentFailed;
use App\Events\AutomaticPaymentProcessed;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use App\Notifications\PaymentNotification;

class CreatePaymentNotification implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Handle payment processed event.
     */
    public function handleProcessed(PaymentProcessed $event)
    {
        $user = User::find($event->transaction->user_id);
        
        if ($user) {
            $this->createNotification($user, [
                'title' => 'Pago Procesado',
                'message' => $event->broadcastWith()['message'],
                'type' => 'payment_processed',
                'data' => $event->broadcastWith(),
            ]);
        }

        // Notificar a administradores
        $this->notifyAdmins([
            'title' => 'Pago Recibido',
            'message' => "Pago de {$event->transaction->amount} {$event->transaction->currency} procesado para el usuario {$user->full_name}",
            'type' => 'admin_payment_processed',
            'data' => $event->broadcastWith(),
        ]);
    }

    /**
     * Handle payment failed event.
     */
    public function handleFailed(PaymentFailed $event)
    {
        $user = User::find($event->transaction->user_id);
        
        if ($user) {
            $this->createNotification($user, [
                'title' => 'Pago Fallido',
                'message' => $event->broadcastWith()['message'],
                'type' => 'payment_failed',
                'data' => $event->broadcastWith(),
            ]);
        }

        // Notificar a administradores
        $this->notifyAdmins([
            'title' => 'Pago Fallido',
            'message' => "Pago fallido de {$event->transaction->amount} {$event->transaction->currency} para el usuario {$user->full_name}",
            'type' => 'admin_payment_failed',
            'data' => $event->broadcastWith(),
        ]);
    }

    /**
     * Handle automatic payment processed event.
     */
    public function handleAutomaticProcessed(AutomaticPaymentProcessed $event)
    {
        $user = User::find($event->transaction->user_id);
        
        if ($user) {
            $this->createNotification($user, [
                'title' => 'Pago Automático Procesado',
                'message' => $event->broadcastWith()['message'],
                'type' => 'automatic_payment_processed',
                'data' => $event->broadcastWith(),
            ]);
        }

        // Notificar a administradores
        $this->notifyAdmins([
            'title' => 'Pago Automático',
            'message' => "Pago automático de {$event->transaction->amount} {$event->transaction->currency} procesado para el usuario {$user->full_name}",
            'type' => 'admin_automatic_payment',
            'data' => $event->broadcastWith(),
        ]);
    }

    /**
     * Create notification for a specific user.
     */
    private function createNotification(User $user, array $data)
    {
        $user->notify(new PaymentNotification($data));
    }

    /**
     * Notify all administrators.
     */
    private function notifyAdmins(array $data)
    {
        $admins = User::where('role', 'admin')
                     ->orWhere('role', 'super_admin')
                     ->get();

        foreach ($admins as $admin) {
            $admin->notify(new PaymentNotification($data));
        }
    }
}

