<?php

namespace App\Mail\RokePet;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class VaccineReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $ownerName,
        public readonly string $petName,
        public readonly string $vaccineName,
        public readonly string $dueDate,
        public readonly int $daysUntilDue,
    ) {}

    public function envelope(): Envelope
    {
        $subject = $this->daysUntilDue === 0
            ? "Vacuna de {$this->petName} vence hoy"
            : "Vacuna de {$this->petName} vence en {$this->daysUntilDue} día(s)";

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.rokepet.vaccine-reminder');
    }

    public function attachments(): array
    {
        return [];
    }
}
