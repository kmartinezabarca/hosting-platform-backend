<?php

namespace App\Domains\Platform\Services;

use App\Domains\Platform\Models\PaymentMethod;
use App\Domains\Platform\Models\Receipt;
use App\Domains\Platform\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Stripe\Exception\ApiErrorException;
use Stripe\PaymentIntent;
use Stripe\PaymentMethod as StripePaymentMethod;
use Stripe\SetupIntent;
use Stripe\Customer;

class PaymentService
{
    public function __construct()
    {
        \Stripe\Stripe::setApiKey(config('services.stripe.secret'));
    }

    // ──────────────────────────────────────────────
    // Stripe Customers
    // ──────────────────────────────────────────────

    /**
     * Retrieve or create the Stripe Customer for a given user.
     */
    public function getOrCreateStripeCustomer(User $user): string
    {
        if ($user->stripe_customer_id) {
            return $user->stripe_customer_id;
        }

        $customer = Customer::create([
            'email'    => $user->email,
            'name'     => $user->full_name,
            'metadata' => ['user_id' => $user->id],
        ]);

        $user->update(['stripe_customer_id' => $customer->id]);

        return $customer->id;
    }

    // ──────────────────────────────────────────────
    // Setup Intents
    // ──────────────────────────────────────────────

    /**
     * Create a Stripe SetupIntent so the frontend can securely collect a card.
     */
    public function createSetupIntent(User $user): SetupIntent
    {
        $customerId = $this->getOrCreateStripeCustomer($user);

        return SetupIntent::create([
            'customer'             => $customerId,
            'payment_method_types' => ['card'],
            'usage'                => 'off_session',
            'metadata'             => ['user_id' => $user->id],
        ]);
    }

    // ──────────────────────────────────────────────
    // Payment Methods
    // ──────────────────────────────────────────────

    /**
     * Attach a Stripe PaymentMethod to the user and persist it locally.
     *
     * @throws \RuntimeException|\Stripe\Exception\ApiErrorException
     */
    public function attachPaymentMethod(User $user, string $stripePaymentMethodId, bool $setAsDefault, ?string $name): PaymentMethod
    {
        if (PaymentMethod::where('user_id', $user->id)
            ->where('stripe_payment_method_id', $stripePaymentMethodId)
            ->exists()
        ) {
            throw new \RuntimeException('payment_method_already_saved');
        }

        $customerId = $this->getOrCreateStripeCustomer($user);

        $pm = StripePaymentMethod::retrieve($stripePaymentMethodId);

        // Ownership guard
        if (!empty($pm->customer) && $pm->customer !== $customerId) {
            throw new \RuntimeException('attached_to_other_customer');
        }

        if (empty($pm->customer)) {
            $pm->attach(['customer' => $customerId]);
            $pm = StripePaymentMethod::retrieve($pm->id);
        }

        $card           = $pm->card ?? null;
        $cardholderName = $pm->billing_details->name ?? null;

        // Fecha de vencimiento: último instante del mes de expiración
        $expiresAt = null;
        if ($card && !empty($card->exp_month) && !empty($card->exp_year)) {
            $expiresAt = Carbon::createFromDate((int) $card->exp_year, (int) $card->exp_month, 1)
                ->endOfMonth()
                ->startOfDay();
        }

        if ($setAsDefault) {
            Customer::update($customerId, [
                'invoice_settings' => ['default_payment_method' => $pm->id],
            ]);
            PaymentMethod::where('user_id', $user->id)->update(['is_default' => false]);
        }

        return PaymentMethod::create([
            'uuid'                     => (string) Str::uuid(),
            'user_id'                  => $user->id,
            'stripe_payment_method_id' => $pm->id,
            'stripe_customer_id'       => $customerId,
            'type'                     => $pm->type ?? 'card',
            'provider'                 => 'stripe',
            'provider_id'              => $pm->id,
            'name'                     => $name ?? $this->buildCardName($card),
            'last4'                    => $card?->last4 ?? null,
            'cardholder_name'          => $cardholderName,
            'details'                  => $this->extractCardDetails($card),
            'is_default'               => $setAsDefault,
            'is_active'                => true,
            'expires_at'               => $expiresAt,
        ]);
    }

    /**
     * Detach a payment method from Stripe and mark it inactive locally.
     */
    public function detachPaymentMethod(PaymentMethod $paymentMethod): void
    {
        if ($paymentMethod->stripe_payment_method_id) {
            try {
                $pm = StripePaymentMethod::retrieve($paymentMethod->stripe_payment_method_id);
                $pm->detach();
            } catch (ApiErrorException $e) {
                \Illuminate\Support\Facades\Log::warning('Could not detach Stripe PM: ' . $e->getMessage());
            }
        }

        // Reassign default if needed
        if ($paymentMethod->is_default) {
            $next = PaymentMethod::where('user_id', $paymentMethod->user_id)
                ->where('uuid', '!=', $paymentMethod->uuid)
                ->where('is_active', true)
                ->first();
            $next?->update(['is_default' => true]);
        }

        $paymentMethod->update(['is_active' => false]);
    }

    // ──────────────────────────────────────────────
    // Payment Intents
    // ──────────────────────────────────────────────

    /**
     * Create a PaymentIntent for a given amount.
     */
    public function createPaymentIntent(
        User $user,
        int  $amountCents,
        string $currency,
        array $metadata = [],
        ?string $description = null
    ): PaymentIntent {
        return PaymentIntent::create([
            'amount'      => $amountCents,
            'currency'    => strtolower($currency),
            'metadata'    => array_merge(['user_id' => $user->id], $metadata),
            'description' => $description ?? 'ROKE Industries — service payment',
        ]);
    }

    // ──────────────────────────────────────────────
    // Transactions
    // ──────────────────────────────────────────────

    /**
     * Persist a completed transaction record linked to an invoice.
     */
    public function recordTransaction(
        User    $user,
        Receipt $receipt,
        string  $paymentIntentId,
        float   $amount,
        string  $currency,
        ?int    $localPaymentMethodId,
        array   $providerData
    ): Transaction {
        return Transaction::create([
            'uuid'                    => (string) Str::uuid(),
            'user_id'                 => $user->id,
            'invoice_id'              => $receipt->id,
            'payment_method_id'       => $localPaymentMethodId,
            'transaction_id'          => 'TRX-' . Str::upper(Str::random(10)),
            'provider_transaction_id' => $paymentIntentId,
            'type'                    => 'payment',
            'status'                  => 'completed',
            'amount'                  => $amount,
            'currency'                => strtoupper($currency),
            'fee_amount'              => 0,
            'provider'                => 'stripe',
            'provider_data'           => $providerData,
            'description'             => 'Pago de contratación de servicio',
            'failure_reason'          => null,
            'processed_at'            => now(),
        ]);
    }

    // ──────────────────────────────────────────────
    // Statistics
    // ──────────────────────────────────────────────

    /**
     * Aggregated payment stats for a given user.
     */
    public function getUserStats(User $user): array
    {
        return [
            'total_spent'           => Transaction::where('user_id', $user->id)->where('type', 'payment')->where('status', 'completed')->sum('amount'),
            'pending_amount'        => Receipt::where('user_id', $user->id)->whereIn('status', ['sent', 'processing', 'overdue'])->sum('total'),
            'transactions_count'    => Transaction::where('user_id', $user->id)->where('status', 'completed')->count(),
            'payment_methods_count' => PaymentMethod::where('user_id', $user->id)->where('is_active', true)->count(),
            'last_payment'          => Transaction::where('user_id', $user->id)->where('type', 'payment')->where('status', 'completed')->latest()->first(),
        ];
    }

    // ──────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────

    /**
     * Devuelve el nombre/marca de la tarjeta para el campo `name`.
     * Solo la marca — los últimos 4 dígitos se guardan por separado en `last4`.
     * Ejemplos: "Visa", "Mastercard", "American Express".
     */
    private function buildCardName(?\Stripe\StripeObject $card): string
    {
        return match (strtolower($card?->brand ?? '')) {
            'visa'             => 'Visa',
            'mastercard'       => 'Mastercard',
            'amex'             => 'American Express',
            'discover'         => 'Discover',
            'diners'           => 'Diners Club',
            'diners_club'      => 'Diners Club',
            'jcb'              => 'JCB',
            'unionpay'         => 'UnionPay',
            'cartes_bancaires' => 'Cartes Bancaires',
            default            => ucfirst($card?->brand ?? 'Tarjeta'),
        };
    }

    private function extractCardDetails(?\Stripe\StripeObject $card): array
    {
        if (!$card) {
            return [];
        }

        return [
            'brand'       => $card->brand ?? null,
            'last4'       => $card->last4 ?? null,
            'exp_month'   => $card->exp_month ?? null,
            'exp_year'    => $card->exp_year ?? null,
            'funding'     => $card->funding ?? null,
            'country'     => $card->country ?? null,
            'network'     => $card->networks->preferred ?? null,
            'fingerprint' => $card->fingerprint ?? null,
        ];
    }
}
