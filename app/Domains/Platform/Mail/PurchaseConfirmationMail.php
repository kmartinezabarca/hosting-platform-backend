<?php

namespace App\Domains\Platform\Mail;

use App\Domains\Platform\Models\Invoice;
use App\Domains\Platform\Models\Service;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PurchaseConfirmationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User    $user,
        public readonly Service $service,
        public readonly Invoice $invoice,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Confirmación de compra - {$this->service->name}",
            from: env('MAIL_FROM_ADDRESS', 'soporte@rokeindustries.com'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.purchase-confirmation',
            with: [
                'user'    => $this->user,
                'service' => $this->service,
                'invoice' => $this->invoice,
                'plan'    => $this->service->plan,
                'conn'    => $this->service->connection_details ?? [],
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
