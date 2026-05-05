<?php

namespace App\Listeners;

use App\Events\ServicePurchased;
use App\Mail\PurchaseConfirmationMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendPurchaseConfirmationEmail implements ShouldQueue
{
    public function handle(ServicePurchased $event): void
    {
        $service = $event->service->load(['plan', 'user']);
        $user    = $service->user;

        // Buscar la invoice más reciente del servicio
        $invoice = $service->invoices()->latest()->first();

        if (!$invoice) {
            Log::warning('SendPurchaseConfirmationEmail: no se encontró invoice', [
                'service_id' => $service->id,
            ]);
            return;
        }

        try {
            Mail::to($user->email)
                ->send(new PurchaseConfirmationMail($user, $service, $invoice));
        } catch (\Throwable $e) {
            Log::error('SendPurchaseConfirmationEmail: fallo al enviar', [
                'service_id' => $service->id,
                'error'      => $e->getMessage(),
            ]);
        }
    }
}
