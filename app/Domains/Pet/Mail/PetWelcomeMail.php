<?php

namespace App\Domains\Pet\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Correo de bienvenida al crear una cuenta de dueño en ROKE PET.
 * Se envía (best-effort) desde AuthController al registrarse (email o Google).
 */
class PetWelcomeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly ?string $name = null) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: '🐾 ¡Bienvenido a ROKE PET!');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.rokepet.welcome');
    }

    public function attachments(): array
    {
        return [];
    }
}
