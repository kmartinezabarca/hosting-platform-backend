<?php

namespace App\Domains\Pet\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Correo de restablecimiento de contraseña para roke.pet.
 *
 * Se envía de forma síncrona desde PasswordResetController (no implementa
 * ShouldQueue a propósito, para garantizar la entrega aunque no haya worker
 * de colas corriendo en el entorno).
 */
class PetPasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $ownerName,
        public readonly string $resetUrl,
        public readonly int    $expiresMinutes = 60,
        public readonly ?string $ipAddress = null,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '🐾 Restablece tu contraseña — roke.pet',
        );
    }

    public function content(): Content
    {
        // Las propiedades públicas se pasan automáticamente a la vista.
        return new Content(view: 'emails.rokepet.password-reset');
    }

    public function attachments(): array
    {
        return [];
    }
}
