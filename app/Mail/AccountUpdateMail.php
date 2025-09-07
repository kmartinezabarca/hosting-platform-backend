<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use App\Models\User;

class AccountUpdateMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public $user;
    public $updateType;
    public $updateDate;
    public $ipAddress;
    public $userAgent;
    public $requiresAction;
    public $actionUrl;
    public $actionText;
    public $changes;

    /**
     * Create a new message instance.
     */
    public function __construct(User $user, $updateType = null, $updateDate = null, $ipAddress = null, $userAgent = null, $requiresAction = false, $actionUrl = null, $actionText = null, $changes = [])
    {
        $this->user = $user;
        $this->updateType = $updateType;
        $this->updateDate = $updateDate;
        $this->ipAddress = $ipAddress;
        $this->userAgent = $userAgent;
        $this->requiresAction = $requiresAction;
        $this->actionUrl = $actionUrl;
        $this->actionText = $actionText;
        $this->changes = $changes;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Actualización de Cuenta - Roke Industries',
            from: env('MAIL_FROM_ADDRESS', 'soporte@rokeindustries.com'),
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.account-update',
            with: [
                'user' => $this->user,
                'updateType' => $this->updateType,
                'updateDate' => $this->updateDate,
                'ipAddress' => $this->ipAddress,
                'userAgent' => $this->userAgent,
                'requiresAction' => $this->requiresAction,
                'actionUrl' => $this->actionUrl,
                'actionText' => $this->actionText,
                'changes' => $this->changes,
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
