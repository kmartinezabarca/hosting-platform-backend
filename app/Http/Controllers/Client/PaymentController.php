<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\Models\PaymentMethod;
use App\Models\Transaction;
use App\Models\Invoice;
use Stripe\Stripe;
use Stripe\PaymentIntent;
use Stripe\Customer;
use Stripe\Exception\ApiErrorException;
use App\Http\Resources\PaymentMethodResource;
use App\Models\ActivityLog; // Importar el modelo ActivityLog

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

            $paymentMethods = \App\Models\PaymentMethod::query()
                ->where('user_id', $user->id)
                ->where('is_active', true)
                ->orderByDesc('is_default')
                ->orderByDesc('created_at')
                ->get([
                    'uuid',
                    'user_id',
                    'name',
                    'type',
                    'is_default',
                    'is_active',
                    'details',
                    'stripe_payment_method_id',
                    'created_at',
                    'updated_at',
                ]);

            return response()->json([
                'success' => true,
                'data'    => PaymentMethodResource::collection($paymentMethods),
            ]);
        } catch (\Throwable $e) {
            \Log::error('Error fetching payment methods: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error fetching payment methods',
                'error'   => $e->getMessage(),
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

            ActivityLog::record(
                'Creación de Setup Intent',
                'Setup Intent creado para agregar método de pago.',
                'payment_method',
                ['user_id' => $user->id, 'setup_intent_id' => $setupIntent->id],
                $user->id
            );

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
                'name'        => 'sometimes|string|max:100',
                'is_default'  => 'boolean',
            ]);

            $user = Auth::user();
            $stripeCustomerId = $this->getOrCreateStripeCustomer($user);

            if (\App\Models\PaymentMethod::where('user_id', $user->id)
                ->where('stripe_payment_method_id', $validated['stripe_payment_method_id'])
                ->exists()
            ) {
                ActivityLog::record(
                    'Intento de agregar método de pago existente',
                    'El usuario intentó agregar un método de pago ya registrado.',
                    'payment_method',
                    ['user_id' => $user->id, 'stripe_pm_id' => $validated['stripe_payment_method_id'], 'status' => 'failed_duplicate'],
                    $user->id
                );
                return response()->json([
                    'success' => false,
                    'message' => 'Este método de pago ya está registrado en tu cuenta.',
                    'error'   => 'payment_method_already_saved',
                ], 422);
            }

            $pm = \Stripe\PaymentMethod::retrieve($validated['stripe_payment_method_id']);

            if (!empty($pm->customer)) {
                if ($pm->customer !== $stripeCustomerId) {
                    ActivityLog::record(
                        'Intento de agregar método de pago de otra cuenta',
                        'El usuario intentó agregar un método de pago vinculado a otra cuenta.',
                        'payment_method',
                        ['user_id' => $user->id, 'stripe_pm_id' => $validated['stripe_payment_method_id'], 'status' => 'failed_other_customer'],
                        $user->id
                    );
                    return response()->json([
                        'success' => false,
                        'message' => 'Este método de pago ya está vinculado a otra cuenta.',
                        'error'   => 'attached_to_other_customer',
                    ], 422);
                }
            } else {
                $pm->attach(['customer' => $stripeCustomerId]);
                $pm = \Stripe\PaymentMethod::retrieve($pm->id);
            }

            $card = $pm->card ?? null;
            $name = $validated['name'] ?? ($card ? "**** **** **** {$card->last4}" : 'Método de pago');

            $details = [
                'brand'       => $card->brand ?? null,      // 'visa','mastercard','amex',...
                'last4'       => $card->last4 ?? null,
                'exp_month'   => $card->exp_month ?? null,
                'exp_year'    => $card->exp_year ?? null,
                'funding'     => $card->funding ?? null,    // 'debit','credit','prepaid'
                'country'     => $card->country ?? null,    // país emisor (ISO2)
                'network'     => $card->networks->preferred ?? null, // si viene
                'fingerprint' => $card->fingerprint ?? null, // útil para deduplicar
            ];

            if (!empty($validated['is_default'])) {
                \Stripe\Customer::update($stripeCustomerId, [
                    'invoice_settings' => ['default_payment_method' => $pm->id],
                ]);

                \App\Models\PaymentMethod::where('user_id', $user->id)
                    ->update(['is_default' => false]);
            }

            $paymentMethod = \App\Models\PaymentMethod::create([
                'uuid'                    => \Str::uuid(),
                'user_id'                 => $user->id,
                'stripe_payment_method_id' => $pm->id,
                'stripe_customer_id'      => $stripeCustomerId,
                'type'                    => $pm->type ?? 'card',
                'name'                    => $name,
                'details'                 => $details,
                'is_default'              => (bool) ($validated['is_default'] ?? false),
                'is_active'               => true,
            ]);

            ActivityLog::record(
                'Método de pago agregado',
                'Nuevo método de pago ' . ($card ? $card->brand . ' ****' . $card->last4 : 'genérico') . ' agregado.',
                'payment_method',
                ['user_id' => $user->id, 'payment_method_id' => $paymentMethod->id, 'stripe_pm_id' => $pm->id],
                $user->id
            );

            return response()->json([
                'success' => true,
                'message' => 'Método de pago agregado exitosamente',
                'data'    => $paymentMethod,
            ]);
        } catch (\Stripe\Exception\ApiErrorException $e) {
            Log::error('Stripe error adding payment method: ' . $e->getMessage());
            ActivityLog::record(
                'Error al agregar método de pago (Stripe)',
                'Error de Stripe: ' . $e->getMessage(),
                'payment_method',
                ['user_id' => $user->id, 'error' => $e->getMessage()],
                $user->id
            );
            return response()->json([
                'success' => false,
                'message' => 'Error de Stripe al agregar método de pago',
                'error'   => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('Error adding payment method: ' . $e->getMessage());
            ActivityLog::record(
                'Error al agregar método de pago',
                'Error general: ' . $e->getMessage(),
                'payment_method',
                ['user_id' => $user->id, 'error' => $e->getMessage()],
                $user->id
            );
            return response()->json([
                'success' => false,
                'message' => 'Error al agregar método de pago',
                'error'   => $e->getMessage(),
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

            ActivityLog::record(
                'Cliente Stripe creado',
                'Cliente Stripe creado para el usuario ' . $user->email . '.',
                'payment_method',
                ['user_id' => $user->id, 'stripe_customer_id' => $customer->id],
                $user->id
            );

            return $customer->id;
        } catch (\Exception $e) {
            Log::error('Error creating Stripe customer: ' . $e->getMessage());
            ActivityLog::record(
                'Error al crear cliente Stripe',
                'Error: ' . $e->getMessage(),
                'payment_method',
                ['user_id' => $user->id, 'error' => $e->getMessage()],
                $user->id
            );
            throw $e;
        }
    }

    /**
     * Update payment method
     */
    public function updatePaymentMethod(Request $request, $uuid)
    {
        try {
            $validated = $request->validate([
                'name' => 'sometimes|string|max:100',
                'is_default' => 'sometimes|boolean',
                'is_active' => 'sometimes|boolean'
            ]);

            $user = Auth::user();
            $paymentMethod = PaymentMethod::where('user_id', $user->id)
                ->where('uuid', $uuid)
                ->firstOrFail();

            $oldIsDefault = $paymentMethod->is_default;
            $oldIsActive = $paymentMethod->is_active;

            // If setting as default, unset other defaults
            if (isset($validated['is_default']) && $validated['is_default']) {
                PaymentMethod::where('user_id', $user->id)
                    ->where('uuid', '!=', $uuid)
                    ->update(['is_default' => false]);

                if (!$oldIsDefault) {
                    ActivityLog::record(
                        'Método de pago establecido como predeterminado',
                        'Método de pago ' . $paymentMethod->name . ' (' . $paymentMethod->type . ' ****' . ($paymentMethod->details['last4'] ?? '') . ') establecido como predeterminado.',
                        'payment_method',
                        ['user_id' => $user->id, 'payment_method_id' => $paymentMethod->id],
                        $user->id
                    );
                }
            }

            $paymentMethod->update($validated);

            if (isset($validated['is_active']) && $validated['is_active'] !== $oldIsActive) {
                if ($validated['is_active']) {
                    ActivityLog::record(
                        'Método de pago reactivado',
                        'Método de pago ' . $paymentMethod->name . ' (' . $paymentMethod->type . ' ****' . ($paymentMethod->details['last4'] ?? '') . ') reactivado.',
                        'payment_method',
                        ['user_id' => $user->id, 'payment_method_id' => $paymentMethod->id],
                        $user->id
                    );
                } else {
                    ActivityLog::record(
                        'Método de pago desactivado',
                        'Método de pago ' . $paymentMethod->name . ' (' . $paymentMethod->type . ' ****' . ($paymentMethod->details['last4'] ?? '') . ') desactivado.',
                        'payment_method',
                        ['user_id' => $user->id, 'payment_method_id' => $paymentMethod->id],
                        $user->id
                    );
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Payment method updated successfully',
                'data' => $paymentMethod->fresh()
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating payment method: ' . $e->getMessage());
            ActivityLog::record(
                'Error al actualizar método de pago',
                'Error: ' . $e->getMessage(),
                'payment_method',
                ['error' => $e->getMessage()],
                $user->id
            );
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
    public function deletePaymentMethod($uuid)
    {
        try {
            $user = Auth::user();
            $paymentMethod = PaymentMethod::where('user_id', $user->id)
                ->where('uuid', $uuid)
                ->firstOrFail();

            // Detach from Stripe if it exists
            if ($paymentMethod->stripe_payment_method_id) {
                try {
                    $stripePaymentMethod = \Stripe\PaymentMethod::retrieve($paymentMethod->stripe_payment_method_id);
                    $stripePaymentMethod->detach();
                } catch (\Exception $e) {
                    Log::warning('Could not detach Stripe payment method: ' . $e->getMessage());
                    ActivityLog::record(
                        'Advertencia: No se pudo desvincular método de pago de Stripe',
                        'Método de pago ' . $paymentMethod->name . ' (' . $paymentMethod->type . ' ****' . ($paymentMethod->details['last4'] ?? '') . ') no pudo ser desvinculado de Stripe.',
                        'payment_method',
                        ['user_id' => $user->id, 'payment_method_id' => $paymentMethod->id, 'stripe_pm_id' => $paymentMethod->stripe_payment_method_id, 'error' => $e->getMessage()],
                        $user->id
                    );
                }
            }

            // If this was the default method, set another one as default
            if ($paymentMethod->is_default) {
                $nextDefault = PaymentMethod::where('user_id', $user->id)
                    ->where('uuid', '!=', $uuid)
                    ->where('is_active', true)
                    ->first();

                if ($nextDefault) {
                    $nextDefault->update(['is_default' => true]);
                    ActivityLog::record(
                        'Método de pago predeterminado reasignado',
                        'Método de pago ' . $nextDefault->name . ' (' . $nextDefault->type . ' ****' . ($nextDefault->details['last4'] ?? '') . ') establecido como nuevo predeterminado.',
                        'payment_method',
                        ['user_id' => $user->id, 'payment_method_id' => $nextDefault->id],
                        $user->id
                    );
                }
            }

            $paymentMethod->update(['is_active' => false]);

            ActivityLog::record(
                'Método de pago eliminado',
                'Método de pago ' . $paymentMethod->name . ' (' . $paymentMethod->type . ' ****' . ($paymentMethod->details['last4'] ?? '') . ') eliminado.',
                'payment_method',
                ['user_id' => $user->id, 'payment_method_id' => $paymentMethod->id],
                $user->id
            );

            return response()->json([
                'success' => true,
                'message' => 'Método de pago eliminado exitosamente'
            ]);
        } catch (\Exception $e) {
            Log::error('Error deleting payment method: ' . $e->getMessage());
            ActivityLog::record(
                'Error al eliminar método de pago',
                'Error: ' . $e->getMessage(),
                'payment_method',
                ['user_id' => $user->id, 'payment_method_id' => $id, 'error' => $e->getMessage()],
                $user->id
            );
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

                ActivityLog::record(
                    'Intento de procesamiento de pago',
                    'Intento de pago de ' . $amount . ' ' . strtoupper($currency) . ' para servicio/factura.',
                    'payment',
                    [
                        'user_id' => $user->id,
                        'amount' => $amount,
                        'currency' => $currency,
                        'payment_intent_id' => $paymentIntent->id,
                        'service_id' => $validated['service_id'] ?? null,
                        'invoice_id' => $validated['invoice_id'] ?? null,
                    ],
                    $user->id
                );

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
                ActivityLog::record(
                    'Error en procesamiento de pago (Stripe)',
                    'Error de Stripe: ' . $e->getMessage(),
                    'payment',
                    ['user_id' => $user->id, 'amount' => $amount, 'currency' => $currency, 'error' => $e->getMessage()],
                    $user->id
                );
                return response()->json([
                    'success' => false,
                    'message' => 'Payment processing error: ' . $e->getMessage()
                ], 400);
            }
        } catch (\Exception $e) {
            Log::error('Error processing payment: ' . $e->getMessage());
            ActivityLog::record(
                'Error general en procesamiento de pago',
                'Error: ' . $e->getMessage(),
                'payment',
                ['user_id' => $user->id, 'error' => $e->getMessage()],
                $user->id
            );
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
}


