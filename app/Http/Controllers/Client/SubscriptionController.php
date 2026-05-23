<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;

use App\Models\Service;
use App\Models\ServicePlan;
use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Stripe\Stripe;
use Stripe\Customer;
use Stripe\Subscription as StripeSubscription;
use Stripe\Price;
use Stripe\Exception\ApiErrorException;

class SubscriptionController extends Controller
{
    public function __construct()
    {
        // Set Stripe API key
        Stripe::setApiKey(config('services.stripe.secret'));
    }

    /**
     * Get user's subscriptions
     */
    public function getUserSubscriptions(): JsonResponse
    {
        try {
            $user = Auth::user();
            
            $subscriptions = Subscription::where('user_id', $user->id)
                ->with('service')
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $subscriptions
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching user subscriptions: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error fetching subscriptions'
            ], 500);
        }
    }

    /**
     * Create a new subscription
     */
    public function createSubscription(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'price_id'          => 'required|string',
                'service_id'        => 'required|integer',
                'payment_method_id' => 'required|string',
                'trial_days'        => 'sometimes|integer|min:0|max:365',
            ]);

            $user = Auth::user();

            // ── Ownership check: service must belong to the authenticated user ──
            $service = Service::where('id', $validated['service_id'])
                ->where('user_id', $user->id)
                ->first();

            if (! $service) {
                return response()->json(['success' => false, 'message' => 'Servicio no encontrado.'], 404);
            }

            // ── Pricing check: price_id must exist in our catalog ──
            $plan = ServicePlan::where('stripe_price_id', $validated['price_id'])->first();

            if (! $plan) {
                return response()->json(['success' => false, 'message' => 'El precio seleccionado no es válido.'], 422);
            }

            try {
                // Create or get Stripe customer — use stored ID when available
                $customer = $this->getOrCreateStripeCustomer($user);

                // Verify the payment method belongs to this customer
                $paymentMethod = \Stripe\PaymentMethod::retrieve($validated['payment_method_id']);
                if ($paymentMethod->customer && $paymentMethod->customer !== $customer->id) {
                    return response()->json(['success' => false, 'message' => 'El método de pago no pertenece a esta cuenta.'], 422);
                }

                // Attach if not already attached
                if (! $paymentMethod->customer) {
                    $paymentMethod->attach(['customer' => $customer->id]);
                }

                // Set as default payment method
                Customer::update($customer->id, [
                    'invoice_settings' => ['default_payment_method' => $validated['payment_method_id']],
                ]);

                // Create subscription
                $subscriptionData = [
                    'customer' => $customer->id,
                    'items'    => [['price' => $validated['price_id']]],
                    'payment_behavior' => 'default_incomplete',
                    'payment_settings' => ['save_default_payment_method' => 'on_subscription'],
                    'expand'   => ['latest_invoice.payment_intent'],
                    'metadata' => ['user_id' => $user->id, 'service_id' => $service->id],
                ];

                if (! empty($validated['trial_days']) && $validated['trial_days'] > 0) {
                    $subscriptionData['trial_period_days'] = $validated['trial_days'];
                }

                $stripeSubscription = StripeSubscription::create($subscriptionData);

                // Get price details
                $price = Price::retrieve($validated['price_id']);

                // Create local subscription record
                $subscription = Subscription::create([
                    'user_id'                => $user->id,
                    'service_id'             => $service->id,
                    'stripe_subscription_id' => $stripeSubscription->id,
                    'stripe_customer_id'     => $customer->id,
                    'stripe_price_id'        => $validated['price_id'],
                    'name'                   => $price->nickname ?? ($plan->name . ' Subscription'),
                    'status'                 => $stripeSubscription->status,
                    'amount'                 => $price->unit_amount / 100,
                    'currency'               => strtoupper($price->currency),
                    'billing_cycle'          => $this->getBillingCycleFromInterval($price->recurring->interval ?? 'month'),
                    'current_period_start'   => $stripeSubscription->current_period_start
                        ? \Carbon\Carbon::createFromTimestamp($stripeSubscription->current_period_start) : null,
                    'current_period_end'     => $stripeSubscription->current_period_end
                        ? \Carbon\Carbon::createFromTimestamp($stripeSubscription->current_period_end) : null,
                    'trial_start'            => $stripeSubscription->trial_start
                        ? \Carbon\Carbon::createFromTimestamp($stripeSubscription->trial_start) : null,
                    'trial_end'              => $stripeSubscription->trial_end
                        ? \Carbon\Carbon::createFromTimestamp($stripeSubscription->trial_end) : null,
                ]);

                // Persist Stripe customer ID on the user if not already saved
                if (! $user->stripe_customer_id) {
                    $user->update(['stripe_customer_id' => $customer->id]);
                }

                return response()->json([
                    'success' => true,
                    'message' => 'Suscripción creada exitosamente.',
                    'data'    => [
                        'subscription'     => $subscription,
                        'client_secret'    => $stripeSubscription->latest_invoice->payment_intent->client_secret ?? null,
                    ],
                ]);

            } catch (ApiErrorException $e) {
                Log::error('Stripe API Error (createSubscription): ' . $e->getMessage(), ['user_id' => $user->id]);
                return response()->json([
                    'success' => false,
                    'message' => 'Error de Stripe: ' . $e->getMessage(),
                ], 400);
            }

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['success' => false, 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            Log::error('Error creating subscription: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Error al crear la suscripción.'], 500);
        }
    }

    /**
     * Cancel a subscription
     */
    public function cancelSubscription(Request $request, $subscriptionId): JsonResponse
    {
        try {
            $user = Auth::user();
            
            $subscription = Subscription::where('user_id', $user->id)
                ->where('id', $subscriptionId)
                ->firstOrFail();

            try {
                // Cancel in Stripe
                $stripeSubscription = StripeSubscription::retrieve($subscription->stripe_subscription_id);
                $stripeSubscription->cancel();

                // Update local record
                $subscription->update([
                    'status' => 'canceled',
                    'canceled_at' => now(),
                    'ends_at' => $stripeSubscription->current_period_end ? 
                        \Carbon\Carbon::createFromTimestamp($stripeSubscription->current_period_end) : now()
                ]);

                return response()->json([
                    'success' => true,
                    'data' => $subscription->fresh(),
                    'message' => 'Subscription canceled successfully'
                ]);

            } catch (ApiErrorException $e) {
                Log::error('Stripe API Error: ' . $e->getMessage());
                return response()->json([
                    'success' => false,
                    'message' => 'Subscription cancellation error: ' . $e->getMessage()
                ], 400);
            }

        } catch (\Exception $e) {
            Log::error('Error canceling subscription: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error canceling subscription'
            ], 500);
        }
    }

    /**
     * Resume a canceled subscription
     */
    public function resumeSubscription($subscriptionId): JsonResponse
    {
        try {
            $user = Auth::user();
            
            $subscription = Subscription::where('user_id', $user->id)
                ->where('id', $subscriptionId)
                ->firstOrFail();

            try {
                // Resume in Stripe (create new subscription with same details)
                $customer = Customer::retrieve($subscription->stripe_customer_id);
                
                $stripeSubscription = StripeSubscription::create([
                    'customer' => $customer->id,
                    'items' => [
                        ['price' => $subscription->stripe_price_id]
                    ],
                    'metadata' => [
                        'user_id' => $user->id,
                        'service_id' => $subscription->service_id
                    ]
                ]);

                // Update local record
                $subscription->update([
                    'stripe_subscription_id' => $stripeSubscription->id,
                    'status' => $stripeSubscription->status,
                    'canceled_at' => null,
                    'ends_at' => null,
                    'current_period_start' => \Carbon\Carbon::createFromTimestamp($stripeSubscription->current_period_start),
                    'current_period_end' => \Carbon\Carbon::createFromTimestamp($stripeSubscription->current_period_end)
                ]);

                return response()->json([
                    'success' => true,
                    'data' => $subscription->fresh(),
                    'message' => 'Subscription resumed successfully'
                ]);

            } catch (ApiErrorException $e) {
                Log::error('Stripe API Error: ' . $e->getMessage());
                return response()->json([
                    'success' => false,
                    'message' => 'Subscription resume error: ' . $e->getMessage()
                ], 400);
            }

        } catch (\Exception $e) {
            Log::error('Error resuming subscription: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error resuming subscription'
            ], 500);
        }
    }

    /**
     * Get subscription details
     */
    public function getSubscriptionDetails($subscriptionId): JsonResponse
    {
        try {
            $user = Auth::user();
            
            $subscription = Subscription::where('user_id', $user->id)
                ->where('id', $subscriptionId)
                ->with('service')
                ->firstOrFail();

            return response()->json([
                'success' => true,
                'data' => $subscription
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching subscription details: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error fetching subscription details'
            ], 500);
        }
    }

    /**
     * Get or create Stripe customer for user.
     * Always prefers the stored stripe_customer_id to avoid duplicates.
     */
    private function getOrCreateStripeCustomer($user): Customer
    {
        try {
            // Use persisted Stripe customer ID when available
            if ($user->stripe_customer_id) {
                return Customer::retrieve($user->stripe_customer_id);
            }

            // Create a new customer and persist the ID
            $customer = Customer::create([
                'email'    => $user->email,
                'name'     => trim("{$user->first_name} {$user->last_name}"),
                'metadata' => ['user_id' => $user->id],
            ]);

            $user->update(['stripe_customer_id' => $customer->id]);

            return $customer;

        } catch (ApiErrorException $e) {
            throw new \RuntimeException('Error al gestionar el cliente de Stripe: ' . $e->getMessage());
        }
    }

    /**
     * Convert Stripe interval to billing cycle
     */
    private function getBillingCycleFromInterval($interval): string
    {
        switch ($interval) {
            case 'day':
                return 'daily';
            case 'week':
                return 'weekly';
            case 'month':
                return 'monthly';
            case 'year':
                return 'yearly';
            default:
                return 'monthly';
        }
    }
}

