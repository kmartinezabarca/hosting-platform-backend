<?php

namespace App\Domains\Platform\Compute\Enums;

/**
 * Intervalo de facturación de un plan de cómputo. El anual cobra por adelantado
 * 12 meses (normalmente con descuento) — ver Compute\Plans\PlanCatalog.
 */
enum BillingInterval: string
{
    case Monthly = 'monthly';
    case Annual  = 'annual';

    /** Meses que cubre un periodo de este intervalo. */
    public function months(): int
    {
        return $this === self::Annual ? 12 : 1;
    }

    /** @return string[] */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
