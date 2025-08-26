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
     * Add new payment method
     */
    public function addPaymentMethod(Request $request)
    {
        try {
            $validated = $request->validate([
                'type' => 'required|in:card,bank_account,paypal',
                'name' => 'required|string|max:100',
                'details' => 'required|array',
                'is_default' => 'boolean'
            ]);

            $user = Auth::user();

            // If this is set as default, unset other defaults
            if ($validated['is_default'] ?? false) {
                PaymentMethod::where('user_id', $user->id)
                    ->update(['is_default' => false]);
            }

            $paymentMethod = PaymentMethod::create([
                'uuid' => \Str::uuid(),
                'user_id' => $user->id,
                'type' => $validated['type'],
                'name' => $validated['name'],
                'details' => $validated['details'],
                'is_default' => $validated['is_default'] ?? false,
                'is_active' => true
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Payment method added successfully',
                'data' => $paymentMethod
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error adding payment method',
                'error' => $e->getMessage()
            ], 500);
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

            $paymentMethod->update(['is_active' => false]);

            return response()->json([
                'success' => true,
                'message' => 'Payment method deleted successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error deleting payment method',
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

