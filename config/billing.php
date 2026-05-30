<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Moneda e impuestos
    |--------------------------------------------------------------------------
    |
    | Usados por CheckoutQuoteService y ServiceContractingService como única
    | fuente de verdad del IVA y la moneda. NO confiar en el frontend.
    */
    'currency'         => env('BILLING_CURRENCY', 'MXN'),
    'tax_rate_percent' => (float) env('BILLING_TAX_RATE_PERCENT', 16.00),

    /*
    |--------------------------------------------------------------------------
    | Periodo de gracia por pago fallido (dunning)
    |--------------------------------------------------------------------------
    |
    | Días que el servicio permanece activo tras un pago de renovación fallido
    | antes de ser suspendido automáticamente por subscriptions:process-overdue.
    */
    'grace_period_days' => (int) env('BILLING_GRACE_PERIOD_DAYS', 5),

];
