<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Facturama — Timbrado CFDI 4.0
    |--------------------------------------------------------------------------
    | Documentación: https://apisandbox.facturama.mx/docs
    |
    | Credenciales: crear cuenta en https://facturama.mx
    | Sandbox:      https://dev.facturama.mx (registro gratuito)
    */

    'user'     => env('FACTURAMA_USER', ''),
    'password' => env('FACTURAMA_PASSWORD', ''),
    'sandbox'  => env('FACTURAMA_SANDBOX', true),

    // URLs base
    'base_url'         => env('FACTURAMA_SANDBOX', true)
        ? 'https://apisandbox.facturama.mx'
        : 'https://api.facturama.mx',

    /*
    |--------------------------------------------------------------------------
    | Datos del Emisor (tu empresa)
    |--------------------------------------------------------------------------
    */
    'issuer' => [
        'rfc'            => env('FACTURAMA_ISSUER_RFC', ''),
        'name'           => env('FACTURAMA_ISSUER_NAME', ''),
        'regimen_fiscal' => env('FACTURAMA_ISSUER_REGIMEN', '601'),
        'lugar_expedicion' => env('FACTURAMA_ISSUER_ZIP', ''),  // CP del domicilio fiscal
    ],

    /*
    |--------------------------------------------------------------------------
    | Defaults de comprobante
    |--------------------------------------------------------------------------
    */
    'serie'           => env('FACTURAMA_SERIE', 'F'),
    'forma_pago'      => env('FACTURAMA_FORMA_PAGO', '03'),   // 03 = Transferencia
    'metodo_pago'     => 'PUE',   // Pago en una sola exhibición
    'moneda'          => 'MXN',
    'tipo_comprobante'=> 'I',     // Ingreso
    'clave_prod_serv' => env('FACTURAMA_CLAVE_PROD_SERV', '81112100'), // Servicios de cómputo
    'clave_unidad'    => 'E48',   // Unidad de servicio SAT
    'unidad'          => 'Servicio',
    'tasa_iva'        => 0.160000,

    /*
    |--------------------------------------------------------------------------
    | Timeout HTTP (segundos)
    |--------------------------------------------------------------------------
    */
    'timeout' => 30,
];
