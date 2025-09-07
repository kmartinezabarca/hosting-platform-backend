<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use App\Models\User;

class InvoiceGeneratedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public $user;
    public $invoice;
    public $invoiceItems;
    public $downloadUrl;

    /**
     * Create a new message instance.
     */
    public function __construct(User $user, $invoice = null, $invoiceItems = null, $downloadUrl = null)
    {
        $this->user = $user;
        $this->invoice = $invoice;
        $this->invoiceItems = $invoiceItems;
        $this->downloadUrl = $downloadUrl;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Nueva Factura Generada - Roke Industries',
            from: env('MAIL_FROM_ADDRESS', 'soporte@rokeindustries.com'),
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.invoice-generated',
            with: [
                'user' => $this->user,
                'invoice' => $this->invoice,
                'invoiceItems' => $this->invoiceItems,
                'downloadUrl' => $this->downloadUrl,
            ],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
