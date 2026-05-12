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

    public function handleGenerated(InvoiceGenerated $event)
    {
        $this->notifyClient($event->invoice->user, [
            'title'   => 'Nueva Factura Generada',
            'message' => $event->broadcastWith()['message'],
            'type'    => 'invoice_generated',
            'data'    => $event->broadcastWith(),
        ]);

        $this->notifyAdmins([
            'title'   => 'Factura Generada',
            'message' => "Se generó la factura #{$event->invoice->invoice_number} para {$event->invoice->user->full_name}",
            'type'    => 'admin_invoice_generated',
            'data'    => $event->broadcastWith(),
        ]);
    }

    public function handleStatusChanged(InvoiceStatusChanged $event)
    {
        $this->notifyClient($event->invoice->user, [
            'title'   => 'Estado de Factura Actualizado',
            'message' => $event->broadcastWith()['message'],
            'type'    => 'invoice_status_changed',
            'data'    => $event->broadcastWith(),
        ]);

        if (in_array($event->newStatus, ['paid', 'cancelled', 'overdue'])) {
            $this->notifyAdmins([
                'title'   => 'Estado de Factura Actualizado',
                'message' => "La factura #{$event->invoice->invoice_number} cambió a estado: {$event->newStatus}",
                'type'    => 'admin_invoice_status',
                'data'    => $event->broadcastWith(),
            ]);
        }
    }

    private function notifyClient(User $user, array $data): void
    {
        $user->notify(new ServiceNotification(array_merge($data, [
            'target'   => 'client',
            '_channel' => 'user.' . $user->uuid,
        ])));
    }

    private function notifyAdmins(array $data): void
    {
        User::where('role', 'admin')->orWhere('role', 'super_admin')->get()
            ->each(fn ($admin) => $admin->notify(new ServiceNotification(array_merge($data, [
                'target'   => 'admin',
                '_channel' => 'admin.notifications',
            ]))));
    }
}
