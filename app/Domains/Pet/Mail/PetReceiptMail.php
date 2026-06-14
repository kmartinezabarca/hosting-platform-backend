<?php

namespace App\Domains\Pet\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Recibo de pago de la suscripción de ROKE PET. Lo dispara el webhook de Stripe
 * (onInvoicePaid) cuando un cobro se confirma con monto > 0.
 */
class PetReceiptMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly ?string $name,
        public readonly string  $amount,        // ya formateado, ej. "149.00"
        public readonly string  $currency,      // ej. "MXN"
        public readonly string  $invoiceNumber,
        public readonly ?string $invoiceUrl,
        public readonly string  $dateLabel,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "🧾 Recibo de tu pago en ROKE PET ({$this->invoiceNumber})");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.rokepet.receipt');
    }

    public function attachments(): array
    {
        return [];
    }
}
