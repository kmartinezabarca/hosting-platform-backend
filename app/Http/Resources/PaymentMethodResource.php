<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PaymentMethodResource extends JsonResource
{
    public function toArray($request): array
    {
        $d = $this->details ?? [];

        // Campos con columna dedicada — fallback al JSON por compatibilidad con registros viejos
        $brand          = data_get($d, 'brand');
        $last4          = $this->last4          ?? data_get($d, 'last4');
        $cardholderName = $this->cardholder_name ?? data_get($d, 'cardholder_name');
        $expM           = data_get($d, 'exp_month');
        $expY           = data_get($d, 'exp_year');
        $funding        = data_get($d, 'funding');
        $country        = data_get($d, 'country');
        $network        = data_get($d, 'network');

        $expiringSoon = false;
        if ($expM && $expY) {
            $expiringSoon = now()->startOfMonth()->diffInMonths(
                now()->setMonth((int)$expM)->setYear((int)$expY)->startOfMonth(),
                false
            ) <= 3;
        }

        return [
            'uuid'                     => $this->uuid,
            'stripe_payment_method_id' => $this->stripe_payment_method_id,
            'provider'                 => $this->provider,       // stripe, conekta, etc.
            'provider_id'              => $this->provider_id,    // pm_xxx
            'name'                     => $this->name,           // etiqueta editable por el usuario
            'cardholder_name'          => $cardholderName,       // nombre del titular (billing_details.name)
            'brand'                    => $brand,                // visa/mastercard/amex...
            'last4'                    => $last4,                // últimos 4 dígitos
            'exp_month'                => $expM,
            'exp_year'                 => $expY,
            'expires_at'               => optional($this->expires_at)->toIso8601String(),
            'funding'                  => $funding,              // debit/credit/prepaid
            'country'                  => $country,              // país emisor (ISO2)
            'network'                  => $network,              // red preferida si Stripe la devuelve
            'type'                     => $this->type,           // 'card'
            'is_default'               => (bool) $this->is_default,
            'is_active'                => (bool) $this->is_active,
            'expiring_soon'            => $expiringSoon,
            'created_at'               => optional($this->created_at)->toIso8601String(),
            'updated_at'               => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
