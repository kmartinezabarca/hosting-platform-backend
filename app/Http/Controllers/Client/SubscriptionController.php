<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\Models\Subscription;
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
        Stripe::setApiKey(env('STRIPE_SECRET'));
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
            $request->validate([
                'price_id' => 'required|string',
                'service_id' => 'required|integer',
                'payment_method_id' => 'required|string',
                'trial_days' => 'sometimes|integer|min:0|max:365'
            ]);

            $user = Auth::user();
            
            try {
                // Create or get Stripe customer
                $customer = $this->getOrCreateStripeCustomer($user);
                
                // Attach payment method to customer
                $paymentMethod = \Stripe\PaymentMethod::retrieve($request->payment_method_id);
                $paymentMethod->attach(['customer' => $customer->id]);
                
                // Set as default payment method
                $customer->invoice_settings = ['default_payment_method' => $request->payment_method_id];
                $customer->save();

                // Create subscription
                $subscriptionData = [
                    'customer' => $customer->id,
                    'items' => [
                        ['price' => $request->price_id]
                    ],
                    'payment_behavior' => 'default_incomplete',
                    'payment_settings' => [
                        'save_default_payment_method' => 'on_subscription'
                    ],
                    'expand' => ['latest_invoice.payment_intent'],
                    'metadata' => [
                        'user_id' => $user->id,
                        'service_id' => $request->service_id
                    ]
                ];

                // Add trial period if specified
                if ($request->has('trial_days') && $request->trial_days > 0) {
                    $subscriptionData['trial_period_days'] = $request->trial_days;
                }

                $stripeSubscription = StripeSubscription::create($subscriptionData);

                // Get price details
                $price = Price::retrieve($request->price_id);

                // Create local subscription record
                $subscription = Subscription::create([
                    'user_id' => $user->id,
                    'service_id' => $request->service_id,
                    'stripe_subscription_id' => $stripeSubscription->id,
                    'stripe_customer_id' => $customer->id,
                    'stripe_price_id' => $request->price_id,
                    'name' => $price->nickname ?? 'Service Subscription',
                    'status' => $stripeSubscription->status,
                    'amount' => $price->unit_amount / 100, // Convert from cents
                    'currency' => strtoupper($price->currency),
                    'billing_cycle' => $this->getBillingCycleFromInterval($price->recurring->interval),
                    'current_period_start' => $stripeSubscription->current_period_start ? 
                        \Carbon\Carbon::createFromTimestamp($stripeSubscription->current_period_start) : null,
                    'current_period_end' => $stripeSubscription->current_period_end ? 
                        \Carbon\Carbon::createFromTimestamp($stripeSubscription->current_period_end) : null,
                    'trial_start' => $stripeSubscription->trial_start ? 
                        \Carbon\Carbon::createFromTimestamp($stripeSubscription->trial_start) : null,
                    'trial_end' => $stripeSubscription->trial_end ? 
                        \Carbon\Carbon::createFromTimestamp($stripeSubscription->trial_end) : null,
                ]);

                return response()->json([
                    'success' => true,
                    'data' => [
                        'subscription' => $subscription,
                        'stripe_subscription' => $stripeSubscription,
                        'client_secret' => $stripeSubscription->latest_invoice->payment_intent->client_secret ?? null
                    ],
                    'message' => 'Subscription created successfully'
                ]);

            } catch (ApiErrorException $e) {
                Log::error('Stripe API Error: ' . $e->getMessage());
                return response()->json([
                    'success' => false,
                    'message' => 'Subscription creation error: ' . $e->getMessage()
                ], 400);
            }

        } catch (\Exception $e) {
            Log::error('Error creating subscription: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error creating subscription'
            ], 500);
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
     * Get or create Stripe customer for user
     */
    private function getOrCreateStripeCustomer($user)
    {
        try {
            // Try to find existing customer
            $customers = Customer::all(['email' => $user->email, 'limit' => 1]);
            
            if (!empty($customers->data)) {
                return $customers->data[0];
            }

            // Create new customer
            return Customer::create([
                'email' => $user->email,
                'name' => $user->name,
                'metadata' => [
                    'user_id' => $user->id
                ]
            ]);

        } catch (ApiErrorException $e) {
            throw new \Exception('Error creating Stripe customer: ' . $e->getMessage());
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

