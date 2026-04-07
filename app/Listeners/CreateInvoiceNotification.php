<?php

namespace App\Listeners;

use App\Events\InvoiceGenerated;
use App\Events\InvoiceStatusChanged;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use App\Notifications\ServiceNotification;

class CreateInvoiceNotification implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Handle invoice generated event.
     */
    public function handleGenerated(InvoiceGenerated $event)
    {
        $this->createNotification($event->invoice->user, [
            'title' => 'Nueva Factura Generada',
            'message' => $event->broadcastWith()['message'],
            'type' => 'invoice_generated',
            'data' => $event->broadcastWith(),
        ]);

        // Notificar a administradores
        $this->notifyAdmins([
            'title' => 'Factura Generada',
            'message' => "Se generó la factura #{$event->invoice->invoice_number} para {$event->invoice->user->full_name}",
            'type' => 'admin_invoice_generated',
            'data' => $event->broadcastWith(),
        ]);
    }

    /**
     * Handle invoice status changed event.
     */
    public function handleStatusChanged(InvoiceStatusChanged $event)
    {
        $this->createNotification($event->invoice->user, [
            'title' => 'Estado de Factura Actualizado',
            'message' => $event->broadcastWith()['message'],
            'type' => 'invoice_status_changed',
            'data' => $event->broadcastWith(),
        ]);

        // Notificar a administradores si es relevante
        if (in_array($event->newStatus, ['paid', 'cancelled', 'overdue'])) {
            $this->notifyAdmins([
                'title' => 'Estado de Factura Actualizado',
                'message' => "La factura #{$event->invoice->invoice_number} cambió a estado: {$event->newStatus}",
                'type' => 'admin_invoice_status',
                'data' => $event->broadcastWith(),
            ]);
        }
    }

    /**
     * Create notification for a specific user.
     */
    private function createNotification(User $user, array $data)
    {
        $user->notify(new ServiceNotification($data));
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
            $admin->notify(new ServiceNotification($data));
        }
    }
}

