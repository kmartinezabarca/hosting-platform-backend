<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use App\Models\User;

class ServiceNotificationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public $user;
    public $notificationType;
    public $message;
    public $actionRequired;
    public $actionUrl;
    public $actionText;
    public $additionalData;

    /**
     * Create a new message instance.
     */
    public function __construct(User $user, $notificationType = null, $message = null, $actionRequired = false, $actionUrl = null, $actionText = null, $additionalData = [])
    {
        $this->user = $user;
        $this->notificationType = $notificationType;
        $this->message = $message;
        $this->actionRequired = $actionRequired;
        $this->actionUrl = $actionUrl;
        $this->actionText = $actionText;
        $this->additionalData = $additionalData;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Notificación de Servicio - Roke Industries',
            from: env('MAIL_FROM_ADDRESS', 'soporte@rokeindustries.com'),
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.service-notification',
            with: array_merge([
                'user' => $this->user,
                'notificationType' => $this->notificationType,
                'message' => $this->message,
                'actionRequired' => $this->actionRequired,
                'actionUrl' => $this->actionUrl,
                'actionText' => $this->actionText,
            ], $this->additionalData),
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
