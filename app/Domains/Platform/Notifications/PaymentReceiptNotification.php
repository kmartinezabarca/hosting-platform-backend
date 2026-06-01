<?php

namespace App\Domains\Platform\Notifications;

use App\Domains\Platform\Models\Receipt;
use App\Domains\Platform\Services\PaymentReceiptService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class PaymentReceiptNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Receipt $invoice) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $invoice = $this->invoice;
        $currency = strtoupper($invoice->currency ?? 'MXN');
        $total = number_format((float) ($invoice->total ?? 0), 2);
        $mail = (new MailMessage)
            ->subject("Comprobante de pago #{$invoice->invoice_number} - {$this->appName()}")
            ->view('emails.notification', [
                'notifiable' => $notifiable,
                'title' => 'Comprobante de pago',
                'subtitle' => 'Tu pago fue procesado exitosamente',
                'intro' => 'Hemos procesado tu pago correctamente. Adjuntamos tu comprobante en PDF para que lo conserves.',
                'detailsTitle' => 'Detalles del comprobante',
                'details' => [
                    'Folio' => $invoice->invoice_number,
                    'Total' => "\${$total} {$currency}",
                    'Fecha' => ($invoice->paid_at?->format('d/m/Y H:i') ?? now()->format('d/m/Y H:i')) . ' hrs',
                    'Método de pago' => $invoice->payment_method ?? 'No disponible',
                ],
                'noticeTitle' => 'Aviso fiscal',
                'notice' => 'Tienes 72 horas desde el momento del pago para solicitar tu Factura Electrónica (CFDI) con tus datos fiscales. Pasado ese plazo se emitirá a nombre de Público en General.',
                'actionUrl' => '/client/invoices/' . $invoice->uuid,
                'actionText' => 'Ver comprobante',
                'footerNote' => "Gracias por confiar en {$this->appName()}.",
            ]);

        // Attach the PDF if it was generated successfully
        try {
            $content = app(PaymentReceiptService::class)->getContent($invoice->fresh(['user', 'items']));
            if ($content) {
                $mail->attachData($content, "comprobante-{$invoice->invoice_number}.pdf", [
                    'mime' => 'application/pdf',
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('PaymentReceiptNotification: could not attach PDF', [
                'invoice_id' => $invoice->id,
                'error'      => $e->getMessage(),
            ]);
        }

        return $mail;
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'           => 'payment.receipt',
            'title'          => 'Comprobante de pago generado',
            'message'        => "Tu comprobante #{$this->invoice->invoice_number} está listo. Revisa tu correo.",
            'invoice_uuid'   => $this->invoice->uuid,
            'invoice_number' => $this->invoice->invoice_number,
            'total'          => $this->invoice->total,
            'currency'       => $this->invoice->currency,
            'action_url'     => '/dashboard/billing/invoices/' . $this->invoice->uuid,
            'action_text'    => 'Ver comprobante',
            'icon'           => 'document-check',
            'color'          => 'success',
        ];
    }

    private function appName(): string
    {
        return config('app.name', 'ROKE Industries');
    }
}
