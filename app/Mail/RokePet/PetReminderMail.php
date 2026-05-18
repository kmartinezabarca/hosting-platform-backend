<?php

namespace App\Mail\RokePet;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PetReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    // 'vaccine' | 'deworming' | 'checkup'
    public readonly string $typeLabel;
    public readonly string $typeEmoji;

    public function __construct(
        public readonly string $ownerName,
        public readonly string $petName,
        public readonly string $eventName,
        public readonly string $dueDate,
        public readonly int    $daysUntilDue,
        public readonly string $type,
    ) {
        $this->typeLabel = match ($type) {
            'vaccine'  => 'Vacuna',
            'deworming'=> 'Desparasitación',
            'checkup'  => 'Consulta de seguimiento',
            default    => 'Recordatorio médico',
        };

        $this->typeEmoji = match ($type) {
            'vaccine'  => '💉',
            'deworming'=> '🐛',
            'checkup'  => '🩺',
            default    => '📋',
        };
    }

    public function envelope(): Envelope
    {
        $subject = $this->daysUntilDue === 0
            ? "{$this->typeEmoji} {$this->typeLabel} de {$this->petName} vence hoy"
            : "{$this->typeEmoji} {$this->typeLabel} de {$this->petName} vence en {$this->daysUntilDue} día(s)";

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.rokepet.pet-reminder');
    }

    public function attachments(): array
    {
        return [];
    }
}
