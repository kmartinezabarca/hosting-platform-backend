<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\PaymentMethod;
use App\Models\Transaction;
use App\Models\Invoice;

class PaymentController extends Controller
{
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
     * Process payment for invoice
     */
    public function processPayment(Request $request)
    {
        try {
            $validated = $request->validate([
                'invoice_id' => 'required|exists:invoices,id',
                'payment_method_id' => 'required|exists:payment_methods,id',
                'provider' => 'required|in:stripe,conekta,paypal'
            ]);

            $user = Auth::user();
            
            // Verify invoice belongs to user
            $invoice = Invoice::where('id', $validated['invoice_id'])
                ->where('user_id', $user->id)
                ->where('status', 'pending')
                ->firstOrFail();

            // Verify payment method belongs to user
            $paymentMethod = PaymentMethod::where('id', $validated['payment_method_id'])
                ->where('user_id', $user->id)
                ->where('is_active', true)
                ->firstOrFail();

            // Create transaction record
            $transaction = Transaction::create([
                'uuid' => \Str::uuid(),
                'user_id' => $user->id,
                'invoice_id' => $invoice->id,
                'payment_method_id' => $paymentMethod->id,
                'transaction_id' => 'TXN_' . strtoupper(\Str::random(12)),
                'type' => 'payment',
                'status' => 'pending',
                'amount' => $invoice->total,
                'currency' => 'MXN',
                'provider' => $validated['provider'],
                'description' => "Payment for invoice #{$invoice->invoice_number}"
            ]);

            // Here you would integrate with actual payment providers
            // For now, we'll simulate a successful payment
            $transaction->update([
                'status' => 'completed',
                'provider_transaction_id' => 'MOCK_' . strtoupper(\Str::random(16)),
                'processed_at' => now()
            ]);

            // Update invoice status
            $invoice->update([
                'status' => 'paid',
                'paid_at' => now()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Payment processed successfully',
                'data' => [
                    'transaction' => $transaction,
                    'invoice' => $invoice->fresh()
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error processing payment',
                'error' => $e->getMessage()
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
     * Generate payment intent for Stripe/Conekta
     */
    public function createPaymentIntent(Request $request)
    {
        try {
            $validated = $request->validate([
                'amount' => 'required|numeric|min:1',
                'currency' => 'sometimes|string|size:3',
                'provider' => 'required|in:stripe,conekta'
            ]);

            $user = Auth::user();
            
            // Here you would create actual payment intent with the provider
            // For now, return mock data
            $paymentIntent = [
                'id' => 'pi_' . strtoupper(\Str::random(24)),
                'client_secret' => 'pi_' . strtoupper(\Str::random(24)) . '_secret_' . strtoupper(\Str::random(16)),
                'amount' => $validated['amount'] * 100, // Convert to cents
                'currency' => $validated['currency'] ?? 'mxn',
                'status' => 'requires_payment_method'
            ];

            return response()->json([
                'success' => true,
                'data' => $paymentIntent
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error creating payment intent',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}

