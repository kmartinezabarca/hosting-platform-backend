<?php

namespace App\Enums;

enum QuotationStatus: string
{
    case Draft           = 'draft';
    case Sent            = 'sent';
    case Viewed          = 'viewed';
    case Accepted        = 'accepted';
    case Rejected        = 'rejected';
    case Expired         = 'expired';
    case Cancelled       = 'cancelled';
    case PendingRevision = 'pending_revision';

    // Valid state machine transitions
    private const TRANSITIONS = [
        'draft'            => ['sent', 'cancelled'],
        'sent'             => ['viewed', 'accepted', 'rejected', 'expired', 'cancelled'],
        'viewed'           => ['accepted', 'rejected', 'expired', 'cancelled'],
        'accepted'         => ['pending_revision'],
        'rejected'         => ['draft', 'cancelled'],
        'expired'          => ['draft', 'cancelled'],
        'pending_revision' => ['draft', 'sent', 'cancelled'],
        'cancelled'        => [],
    ];

    public function canTransitionTo(self $target): bool
    {
        return in_array($target->value, self::TRANSITIONS[$this->value] ?? [], true);
    }

    public function isModifiable(): bool
    {
        return in_array($this, [
            self::Draft,
            self::Sent,
            self::Viewed,
            self::PendingRevision,
        ]);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Accepted, self::Cancelled]);
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft           => 'Borrador',
            self::Sent            => 'Enviada',
            self::Viewed          => 'Vista',
            self::Accepted        => 'Aceptada',
            self::Rejected        => 'Rechazada',
            self::Expired         => 'Expirada',
            self::Cancelled       => 'Cancelada',
            self::PendingRevision => 'Pendiente de Revisión',
        };
    }
}
