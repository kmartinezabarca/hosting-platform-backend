<?php

namespace App\Domains\Platform\Listeners;

use App\Domains\Platform\Events\InvoiceGenerated;
use App\Domains\Platform\Events\InvoiceStatusChanged;
use App\Domains\Platform\Events\ReceiptGenerated;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use App\Domains\Platform\Notifications\ServiceNotification;

class CreateInvoiceNotification implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Handles both InvoiceGenerated and ReceiptGenerated events.
     */
    public function handleGenerated(InvoiceGenerated|ReceiptGenerated $event)
    {
        $receipt = $event instanceof ReceiptGenerated ? $event->receipt : $event->invoice;

        $broadcastData = $event->broadcastWith();

        $this->notifyClient($receipt->user, [
            'title'   => 'Nuevo Comprobante Generado',
            'message' => $broadcastData['message'],
            'type'    => 'receipt_generated',
            'data'    => $broadcastData,
        ]);

        $this->notifyAdmins([
            'title'   => 'Comprobante Generado',
            'message' => "Se generó el comprobante #{$receipt->receipt_number} para {$receipt->user->full_name}",
            'type'    => 'admin_receipt_generated',
            'data'    => $broadcastData,
        ]);
    }

    public function handleStatusChanged(InvoiceStatusChanged $event)
    {
        $this->notifyClient($event->invoice->user, [
            'title'   => 'Estado de Comprobante Actualizado',
            'message' => $event->broadcastWith()['message'],
            'type'    => 'invoice_status_changed',
            'data'    => $event->broadcastWith(),
        ]);

        if (in_array($event->newStatus, ['paid', 'cancelled', 'overdue'])) {
            $this->notifyAdmins([
                'title'   => 'Estado de Comprobante Actualizado',
                'message' => "El comprobante #{$event->invoice->receipt_number} cambió a estado: {$event->newStatus}",
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
