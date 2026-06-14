<?php

namespace App\Domains\Pet\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Aviso a la lista de espera: la app móvil de ROKE PET ya está disponible.
 * Lo envía el comando rokepet:notify-waitlist a cada lead con correo.
 */
class PetAppLaunchMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly ?string $name = null) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: '🐾 ¡La app de ROKE PET ya está disponible!');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.rokepet.app-launch');
    }

    public function attachments(): array
    {
        return [];
    }
}
