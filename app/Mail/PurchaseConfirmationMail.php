<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use App\Models\User;

class PurchaseConfirmationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public $user;
    public $order;
    public $items;
    public $total;
    public $paymentMethod;
    public $dashboardUrl;
    public $serviceName;
    public $serviceDescription;

    /**
     * Create a new message instance.
     */
    public function __construct(User $user, $order = null, $items = null, $total = 0, $paymentMethod = null, $dashboardUrl = null, $serviceName = null, $serviceDescription = null)
    {
        $this->user = $user;
        $this->order = $order;
        $this->items = $items;
        $this->total = $total;
        $this->paymentMethod = $paymentMethod;
        $this->dashboardUrl = $dashboardUrl;
        $this->serviceName = $serviceName;
        $this->serviceDescription = $serviceDescription;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Confirmación de Compra - Roke Industries',
            from: env('MAIL_FROM_ADDRESS', 'soporte@rokeindustries.com'),
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.purchase-confirmation',
            with: [
                'user' => $this->user,
                'order' => $this->order,
                'items' => $this->items,
                'total' => $this->total,
                'paymentMethod' => $this->paymentMethod,
                'dashboardUrl' => $this->dashboardUrl,
                'serviceName' => $this->serviceName,
                'serviceDescription' => $this->serviceDescription,
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
