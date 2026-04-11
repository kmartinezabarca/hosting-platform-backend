<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\PaymentMethod;
use App\Models\Transaction;
use App\Models\User;
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
        $customerId = $this->getOrCreateStripeCustomer($user);

        // Prevent duplicates
        if (PaymentMethod::where('user_id', $user->id)
            ->where('stripe_payment_method_id', $stripePaymentMethodId)
            ->exists()
        ) {
            throw new \RuntimeException('payment_method_already_saved');
        }

        $pm = StripePaymentMethod::retrieve($stripePaymentMethodId);

        // Ownership guard
        if (!empty($pm->customer) && $pm->customer !== $customerId) {
            throw new \RuntimeException('attached_to_other_customer');
        }

        if (empty($pm->customer)) {
            $pm->attach(['customer' => $customerId]);
            $pm = StripePaymentMethod::retrieve($pm->id);
        }

        $card = $pm->card ?? null;

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
            'name'                     => $name ?? ($card ? "**** **** **** {$card->last4}" : 'Método de pago'),
            'details'                  => $this->extractCardDetails($card),
            'is_default'               => $setAsDefault,
            'is_active'                => true,
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
        Invoice $invoice,
        string  $paymentIntentId,
        float   $amount,
        string  $currency,
        ?int    $localPaymentMethodId,
        array   $providerData
    ): Transaction {
        return Transaction::create([
            'uuid'                    => (string) Str::uuid(),
            'user_id'                 => $user->id,
            'invoice_id'              => $invoice->id,
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
            'pending_amount'        => Invoice::where('user_id', $user->id)->where('status', 'pending')->sum('total'),
            'transactions_count'    => Transaction::where('user_id', $user->id)->where('status', 'completed')->count(),
            'payment_methods_count' => PaymentMethod::where('user_id', $user->id)->where('is_active', true)->count(),
            'last_payment'          => Transaction::where('user_id', $user->id)->where('type', 'payment')->where('status', 'completed')->latest()->first(),
        ];
    }

    // ──────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────

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
