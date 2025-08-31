<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PaymentMethodResource extends JsonResource
{
    public function toArray($request): array
    {
        $d = $this->details ?? [];

        // helpers
        $brand   = data_get($d, 'brand');
        $last4   = data_get($d, 'last4');
        $expM    = data_get($d, 'exp_month');
        $expY    = data_get($d, 'exp_year');
        $funding = data_get($d, 'funding');
        $country = data_get($d, 'country');
        $network = data_get($d, 'network');

        $expiringSoon = false;
        if ($expM && $expY) {
            $expiringSoon = now()->startOfMonth()->diffInMonths(
                now()->setMonth((int)$expM)->setYear((int)$expY)->startOfMonth(),
                false
            ) <= 3;
        }

        return [
            'id'    => $this->id,
            'stripe_payment_method_id' => $this->stripe_payment_method_id,
            'name'       => $this->name,      // etiqueta editable por el usuario
            'brand'      => $brand,           // visa/mastercard/amex...
            'last4'      => $last4,           // **** 6109
            'exp_month'  => $expM,
            'exp_year'   => $expY,
            'funding'    => $funding,         // debit/credit/prepaid
            'country'    => $country,         // país emisor (ISO2)
            'network'    => $network,         // red preferida si Stripe la devuelve
            'type'       => $this->type,      // 'card'
            'is_default' => (bool) $this->is_default,
            'is_active'  => (bool) $this->is_active,
            'expiring_soon' => $expiringSoon,
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
