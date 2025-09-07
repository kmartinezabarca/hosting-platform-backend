<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use App\Models\User;

class PaymentSuccessMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public $user;
    public $payment;
    public $subscription;
    public $services;
    public $invoiceUrl;
    public $isRecurring;

    /**
     * Create a new message instance.
     */
    public function __construct(User $user, $payment = null, $subscription = null, $services = null, $invoiceUrl = null, $isRecurring = false)
    {
        $this->user = $user;
        $this->payment = $payment;
        $this->subscription = $subscription;
        $this->services = $services;
        $this->invoiceUrl = $invoiceUrl;
        $this->isRecurring = $isRecurring;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Pago Procesado - Roke Industries',
            from: env('MAIL_FROM_ADDRESS', 'soporte@rokeindustries.com'),
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.payment-success',
            with: [
                'user' => $this->user,
                'payment' => $this->payment,
                'subscription' => $this->subscription,
                'services' => $this->services,
                'invoiceUrl' => $this->invoiceUrl,
                'isRecurring' => $this->isRecurring,
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
