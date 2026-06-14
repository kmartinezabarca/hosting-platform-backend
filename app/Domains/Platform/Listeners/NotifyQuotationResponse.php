<?php

namespace App\Domains\Platform\Listeners;

use App\Domains\Platform\Events\QuotationAccepted;
use App\Domains\Platform\Events\QuotationRejected;
use App\Domains\Platform\Models\Quotation;
use App\Domains\Platform\Support\AdminNotifier;

/**
 * Avisa al equipo (panel admin + correo) cuando un cliente RESPONDE una
 * cotización en la página pública. El audit-log ya lo registra QuotationService;
 * esto es el aviso accionable para que ventas dé seguimiento (aceptada) o sepa
 * del cierre perdido (rechazada).
 *
 * Vista (QuotationViewed) y Reabierta (QuotationReopened) NO notifican: quedan
 * solo en el audit-log para no generar ruido (decisión de producto).
 */
class NotifyQuotationResponse
{
    public function handleAccepted(QuotationAccepted $event): void
    {
        $q = $event->quotation;

        AdminNotifier::notify(
            'Cotización aceptada',
            "{$q->client_name} aceptó la cotización «{$q->title}» por {$this->money($q)}.",
            'quotation.accepted',
            ['quotation_uuid' => $q->uuid],
            [
                'email'       => true,
                'action_url'  => "/admin/quotations/{$q->uuid}",
                'action_text' => 'Ver cotización',
                'subtitle'    => 'Cotización aceptada',
            ],
        );
    }

    public function handleRejected(QuotationRejected $event): void
    {
        $q = $event->quotation;

        AdminNotifier::notify(
            'Cotización rechazada',
            "{$q->client_name} rechazó la cotización «{$q->title}».",
            'quotation.rejected',
            ['quotation_uuid' => $q->uuid],
            [
                'email'       => true,
                'action_url'  => "/admin/quotations/{$q->uuid}",
                'action_text' => 'Ver cotización',
            ],
        );
    }

    private function money(Quotation $q): string
    {
        return number_format((float) $q->total, 2) . ' ' . ($q->currency ?? 'MXN');
    }
}
