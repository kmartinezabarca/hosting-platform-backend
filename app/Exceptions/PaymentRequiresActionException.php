<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a Stripe PaymentIntent requires additional customer action (3DS).
 * The client_secret must be returned to the frontend so it can complete authentication.
 */
class PaymentRequiresActionException extends RuntimeException
{
    public function __construct(public readonly string $clientSecret)
    {
        parent::__construct('Se requiere autenticación adicional del banco.');
    }
}
