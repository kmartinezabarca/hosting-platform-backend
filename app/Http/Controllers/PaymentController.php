<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\Models\PaymentMethod;
use App\Models\Transaction;
use App\Models\Invoice;
use Stripe\Stripe;
use Stripe\PaymentIntent;
use Stripe\Exception\ApiErrorException;

class PaymentController extends Controller
{
    public function __construct()
    {
        // Set Stripe API key
        Stripe::setApiKey(env('STRIPE_SECRET'));
    }
    /**
     * Get user payment methods
     */
    public function getPaymentMethods()
    {
        try {
            $user = Auth::user();
            $paymentMethods = PaymentMethod::where('user_id', $user->id)
                ->where('is_active', true)
                ->orderBy('is_default', 'desc')
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $paymentMethods
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching payment methods',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create Setup Intent for adding payment methods securely
     */
    public function createSetupIntent(Request $request)
    {
        try {
            $user = Auth::user();
            
            // Create or get Stripe customer
            $stripeCustomerId = $this->getOrCreateStripeCustomer($user);
            
            $setupIntent = \Stripe\SetupIntent::create([
                'customer' => $stripeCustomerId,
                'payment_method_types' => ['card'],
                'usage' => 'off_session',
                'metadata' => [
                    'user_id' => $user->id,
                ]
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'client_secret' => $setupIntent->client_secret,
                    'setup_intent_id' => $setupIntent->id
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error creating setup intent: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error creating setup intent',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Add new payment method using Stripe Payment Method ID
     */
    public function addPaymentMethod(Request $request)
    {
        try {
            $validated = $request->validate([
                'stripe_payment_method_id' => 'required|string',
                'name' => 'sometimes|string|max:100',
                'is_default' => 'boolean'
            ]);

            $user = Auth::user();
            $stripeCustomerId = $this->getOrCreateStripeCustomer($user);

            // Attach payment method to customer
            $stripePaymentMethod = \Stripe\PaymentMethod::retrieve($validated['stripe_payment_method_id']);
            $stripePaymentMethod->attach(['customer' => $stripeCustomerId]);

            // Get payment method details
            $card = $stripePaymentMethod->card;
            $name = $validated['name'] ?? "**** **** **** {$card->last4}";

            // If this is set as default, unset other defaults
            if ($validated['is_default'] ?? false) {
                PaymentMethod::where('user_id', $user->id)
                    ->update(['is_default' => false]);
            }

            $paymentMethod = PaymentMethod::create([
                'uuid' => \Str::uuid(),
                'user_id' => $user->id,
                'stripe_payment_method_id' => $validated['stripe_payment_method_id'],
                'stripe_customer_id' => $stripeCustomerId,
                'type' => 'card',
                'name' => $name,
                'details' => [
                    'brand' => $card->brand,
                    'last4' => $card->last4,
                    'exp_month' => $card->exp_month,
                    'exp_year' => $card->exp_year,
                    'funding' => $card->funding
                ],
                'is_default' => $validated['is_default'] ?? false,
                'is_active' => true
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Método de pago agregado exitosamente',
                'data' => $paymentMethod
            ]);

        } catch (\Exception $e) {
            Log::error('Error adding payment method: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al agregar método de pago',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get or create Stripe customer for user
     */
    private function getOrCreateStripeCustomer($user)
    {
        if ($user->stripe_customer_id) {
            return $user->stripe_customer_id;
        }

        try {
            $customer = \Stripe\Customer::create([
                'email' => $user->email,
                'name' => $user->name,
                'metadata' => [
                    'user_id' => $user->id
                ]
            ]);

            $user->update(['stripe_customer_id' => $customer->id]);
            return $customer->id;

        } catch (\Exception $e) {
            Log::error('Error creating Stripe customer: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Update payment method
     */
    public function updatePaymentMethod(Request $request, $id)
    {
        try {
            $validated = $request->validate([
                'name' => 'sometimes|string|max:100',
                'is_default' => 'sometimes|boolean',
                'is_active' => 'sometimes|boolean'
            ]);

            $user = Auth::user();
            $paymentMethod = PaymentMethod::where('user_id', $user->id)
                ->where('id', $id)
                ->firstOrFail();

            // If setting as default, unset other defaults
            if (isset($validated['is_default']) && $validated['is_default']) {
                PaymentMethod::where('user_id', $user->id)
                    ->where('id', '!=', $id)
                    ->update(['is_default' => false]);
            }

            $paymentMethod->update($validated);

            return response()->json([
                'success' => true,
                'message' => 'Payment method updated successfully',
                'data' => $paymentMethod->fresh()
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error updating payment method',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete payment method
     */
    public function deletePaymentMethod($id)
    {
        try {
            $user = Auth::user();
            $paymentMethod = PaymentMethod::where('user_id', $user->id)
                ->where('id', $id)
                ->firstOrFail();

            // Detach from Stripe if it exists
            if ($paymentMethod->stripe_payment_method_id) {
                try {
                    $stripePaymentMethod = \Stripe\PaymentMethod::retrieve($paymentMethod->stripe_payment_method_id);
                    $stripePaymentMethod->detach();
                } catch (\Exception $e) {
                    Log::warning('Could not detach Stripe payment method: ' . $e->getMessage());
                }
            }

            // If this was the default method, set another one as default
            if ($paymentMethod->is_default) {
                $nextDefault = PaymentMethod::where('user_id', $user->id)
                    ->where('id', '!=', $id)
                    ->where('is_active', true)
                    ->first();
                
                if ($nextDefault) {
                    $nextDefault->update(['is_default' => true]);
                }
            }

            $paymentMethod->update(['is_active' => false]);

            return response()->json([
                'success' => true,
                'message' => 'Método de pago eliminado exitosamente'
            ]);

        } catch (\Exception $e) {
            Log::error('Error deleting payment method: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar método de pago',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get user transactions
     */
    public function getTransactions(Request $request)
    {
        try {
            $user = Auth::user();
            $perPage = $request->get('per_page', 15);
            
            $transactions = Transaction::where('user_id', $user->id)
                ->with(['invoice', 'paymentMethod'])
                ->orderBy('created_at', 'desc')
                ->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $transactions
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching transactions',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Process payment for invoice or service
     */
    public function processPayment(Request $request)
    {
        try {
            $validated = $request->validate([
                'amount' => 'required|numeric|min:1',
                'currency' => 'sometimes|string|size:3',
                'payment_method_id' => 'sometimes|string',
                'service_id' => 'sometimes|integer',
                'invoice_id' => 'sometimes|exists:invoices,id',
                'description' => 'sometimes|string|max:500'
            ]);

            $user = Auth::user();
            $amount = $validated['amount'];
            $currency = $validated['currency'] ?? 'usd';
            
            // Create Stripe Payment Intent
            try {
                $paymentIntent = PaymentIntent::create([
                    'amount' => $amount * 100, // Convert to cents
                    'currency' => $currency,
                    'metadata' => [
                        'user_id' => $user->id,
                        'service_id' => $validated['service_id'] ?? null,
                        'invoice_id' => $validated['invoice_id'] ?? null,
                    ],
                    'description' => $validated['description'] ?? 'Service payment'
                ]);

                // Create transaction record
                $transaction = [
                    'id' => rand(1000, 9999),
                    'user_id' => $user->id,
                    'payment_intent_id' => $paymentIntent->id,
                    'amount' => $amount,
                    'currency' => $currency,
                    'status' => 'pending',
                    'created_at' => now()
                ];

                // In a real implementation, save to database
                // Transaction::create($transaction);

                return response()->json([
                    'success' => true,
                    'data' => [
                        'payment_intent' => [
                            'id' => $paymentIntent->id,
                            'client_secret' => $paymentIntent->client_secret,
                            'amount' => $paymentIntent->amount,
                            'currency' => $paymentIntent->currency,
                            'status' => $paymentIntent->status
                        ],
                        'transaction' => $transaction
                    ],
                    'message' => 'Payment intent created successfully'
                ]);

            } catch (ApiErrorException $e) {
                Log::error('Stripe API Error: ' . $e->getMessage());
                return response()->json([
                    'success' => false,
                    'message' => 'Payment processing error: ' . $e->getMessage()
                ], 400);
            }

        } catch (\Exception $e) {
            Log::error('Error processing payment: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error processing payment'
            ], 500);
        }
    }

    /**
     * Get payment statistics
     */
    public function getPaymentStats()
    {
        try {
            $user = Auth::user();
            
            $stats = [
                'total_spent' => Transaction::where('user_id', $user->id)
                    ->where('type', 'payment')
                    ->where('status', 'completed')
                    ->sum('amount'),
                'pending_amount' => Invoice::where('user_id', $user->id)
                    ->where('status', 'pending')
                    ->sum('total'),
                'transactions_count' => Transaction::where('user_id', $user->id)
                    ->where('status', 'completed')
                    ->count(),
                'payment_methods_count' => PaymentMethod::where('user_id', $user->id)
                    ->where('is_active', true)
                    ->count(),
                'last_payment' => Transaction::where('user_id', $user->id)
                    ->where('type', 'payment')
                    ->where('status', 'completed')
                    ->latest()
                    ->first()
            ];

            return response()->json([
                'success' => true,
                'data' => $stats
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching payment statistics',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate payment intent for Stripe
     */
    public function createPaymentIntent(Request $request)
    {
        try {
            $validated = $request->validate([
                'amount' => 'required|numeric|min:1',
                'currency' => 'sometimes|string|size:3',
                'service_id' => 'sometimes|integer',
                'description' => 'sometimes|string|max:500'
            ]);

            $user = Auth::user();
            $amount = $validated['amount'];
            $currency = $validated['currency'] ?? 'usd';
            
            try {
                $paymentIntent = PaymentIntent::create([
                    'amount' => $amount * 100, // Convert to cents
                    'currency' => $currency,
                    'metadata' => [
                        'user_id' => $user->id,
                        'service_id' => $validated['service_id'] ?? null,
                    ],
                    'description' => $validated['description'] ?? 'Service payment',
                    'automatic_payment_methods' => [
                        'enabled' => true,
                    ],
                ]);

                return response()->json([
                    'success' => true,
                    'data' => [
                        'id' => $paymentIntent->id,
                        'client_secret' => $paymentIntent->client_secret,
                        'amount' => $paymentIntent->amount,
                        'currency' => $paymentIntent->currency,
                        'status' => $paymentIntent->status
                    ]
                ]);

            } catch (ApiErrorException $e) {
                Log::error('Stripe API Error: ' . $e->getMessage());
                return response()->json([
                    'success' => false,
                    'message' => 'Error creating payment intent: ' . $e->getMessage()
                ], 400);
            }

        } catch (\Exception $e) {
            Log::error('Error creating payment intent: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error creating payment intent'
            ], 500);
        }
    }
}

